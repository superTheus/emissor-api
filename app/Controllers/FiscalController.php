<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\Connection;
use App\Models\EmissoesModel;
use App\Models\FormaPagamentoModel;
use Dotenv\Dotenv;
use NFePHP\Common\Certificate;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Danfe;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use stdClass;

class FiscalController extends Connection
{
  private $nfe;
  private $tools;
  private $currentXML;
  private $currentPDF;
  private $config;
  private $numero;
  private $serie;
  private $csc;
  private $csc_id;
  private $ambiente;
  private $company;
  private $certificado;
  private $modo_emissao = 1;
  private $currentChave;
  private $dataEmissao;
  private $total_produtos = 0;
  private $produtos = [];
  private $pagamentos = [];
  private $baseCalculo = 0;
  private $baseTotalIcms = 0;
  private $origem = 0;
  private $totalIcms = 0;
  private $valorIcms = 0;
  private $data;
  private $numeroProtocolo;
  private $status;
  private $currentData;
  private $warnings = [];
  private $response;
  private $mod = 55;
  private $tipoCliente = 'PJ';
  private $indIEDest = 9;

  public function __construct($data = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    try {
      if ($data) {
        $this->tipoCliente = $data['cliente']['tipo_documento'] === 'CPF' ? 'PF' : 'PJ';

        if ($this->tipoCliente === 'PF') {
          $this->indIEDest = 9;
        }

        if ($this->tipoCliente === 'PJ') {
          $this->indIEDest = isset($data['cliente']['inscricao_estadual']) && !empty($data['cliente']['inscricao_estadual']) ?
            $data['cliente']['tipo_icms'] ?? 1
            : 9;
        }

        $this->nfe = new Make();
        $this->data = $data;

        if ($data['cnpj']) {
          $companyModel = new CompanyModel();
          $company = $companyModel->find([
            "cnpj" => UtilsController::soNumero($data['cnpj'])
          ]);

          $this->company = new CompanyModel($company[0]['id']);
          $this->ambiente = intval($this->company->getTpamb()) > 0 ? $this->company->getTpamb() : 1;
          $this->serie = $this->company->getTpamb() === 1 ? $this->company->getSerie_nfe() : $this->company->getSerie_nfe_homologacao();
          $this->numero = $this->company->getTpamb() === 1 ? $this->company->getNumero_nfe() : $this->company->getNumero_nfe_homologacao();
          $this->csc = $this->company->getTpamb() === 1 ? $this->company->getCsc() : $this->company->getCsc_homologacao();
          $this->csc_id = $this->company->getTpamb() === 1 ? $this->company->getCsc_id() : $this->company->getCsc_id_homologacao();
          $this->certificado = UtilsController::getCertifcado($this->company->getCertificado());
          $this->config = $this->setConfig();
          $this->dataEmissao = date('Y-m-d\TH:i:sP');
          $this->modo_emissao = isset($data['modoEmissao']) ? $data['modoEmissao'] : 1;

          $this->produtos = isset($data['produtos']) ? $data['produtos'] : [];

          if (isset($data['pagamentos'])) {
            $this->pagamentos = array_map(
              function ($pagamento) {
                $formaPagamentoModel = new FormaPagamentoModel($pagamento['codigo']);

                return [
                  "indPag"    => $formaPagamentoModel->getCurrent()->cod_meio,
                  "tPag"      => $formaPagamentoModel->getCurrent()->codigo,
                  "valorpago" => $pagamento['valorpago']
                ];
              },
              $data['pagamentos']
            );
          } else {
            $this->pagamentos = [];
          }

          $this->tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));
          $this->tools->model($this->mod);

          if ($this->conexaoSefaz() === false) {
            $this->modo_emissao = 9;
            array_push($this->warnings, "Não foi possível se conectar com a SEFAZ, a nota será emitida em modo de contingência");
          }

          $this->montaChave();
        }
      } else {
        http_response_code(400);
        echo json_encode(['error' => 'Dados para emissão da NFe não fornecidos']);
        exit;
      }
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
      exit;
    }
  }

  public function createNfe()
  {
    try {
      $std = new stdClass();
      $std->versao = '4.00';
      $this->nfe->taginfNFe($std);
      $this->nfe->tagide($this->generateIdeData($this->data));
      $this->nfe->tagemit($this->generateDataCompany());
      $this->nfe->tagenderEmit($this->generateDataAddress());
      $this->nfe->tagdest($this->generateClientData($this->data));
      $this->nfe->tagenderDest($this->generateClientAddressData($this->data['cliente']['endereco']));

      $this->baseTotalIcms = 0;
      $this->totalIcms = 0;
      $this->total_produtos = 0;

      foreach ($this->produtos as $index => $produto) {
        $this->baseCalculo = ($produto['total'] - $produto['desconto'] + $produto['frete'] + $produto['acrescimo']);
        $this->origem = $produto['origem'];
        $this->valorIcms = 0;

        $this->nfe->tagprod($this->generateProductData($produto, $index + 1));

        if (isset($produto['informacoes_adicionais']) && !empty($produto['informacoes_adicionais'])) {
          $this->nfe->taginfAdProd($this->generateProdutoInfoAdicional($produto, $index + 1));
        }

        $this->nfe->tagimposto($this->generateImpostoData($produto, $index + 1));

        if ($this->company->getCrt() == "3") {
          if (isset($produto['icms'])) {
            $this->nfe->tagICMS($this->generateICMSData($produto['icms'], $index + 1));
            $this->baseTotalIcms += $this->baseCalculo;
          }

          if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
            $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
            $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
            $this->baseTotalIcms += 1000.00;
          } else {
            $std = new stdClass();
            $std->item = $index + 1;
            $std->orig = $produto['origem'] ?? 0;
            $std->CST = '40';
            $std->vICMSDeson = 0.00;
            $std->motDesICMS = 9; // Outros
            $this->nfe->tagICMS($std);
          }

          if (isset($produto['ipi'])) {
            $this->nfe->tagIPI($this->generateIPIData($produto['ipi'], $index + 1));
          }

          if (isset($produto['pis'])) {
            $this->nfe->tagPIS($this->generatePisData($produto['pis'], $index + 1));
          }

          if (isset($produto['cofins'])) {
            $this->nfe->tagCOFINS($this->generateConfinsData($produto['cofins'], $index + 1));
          }
        } else {
          $icmssnData = $this->generateIcmssnData($produto, $index + 1);

          if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
            $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
            $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
            $this->baseTotalIcms += 1000.00;
          } else {
            $this->nfe->tagICMSSN($icmssnData);
            if (in_array($icmssnData->CSOSN, ['201', '202', '203', '900']) && isset($icmssnData->vBC)) {
              $this->baseTotalIcms += $icmssnData->vBC;
            }
          }

          $this->nfe->tagPIS($this->generatePisDataSimple($produto, $index + 1));
          $this->nfe->tagCOFINS($this->generateConfinsDataSimple($produto, $index + 1));
        }

        $this->totalIcms += number_format($this->valorIcms, 2, ".", "");
      }

      $this->nfe->tagICMSTot($this->generateIcmsTot());
      $this->nfe->taginfAdic($this->generateIcmsInfo($this->data));
      $this->nfe->taginfRespTec($this->generateReponsavelTecnicp());
      $this->nfe->tagtransp($this->generateFreteData());
      $this->nfe->tagpag($this->generateFaturaData());
      $this->nfe->tagautXML($this->generateAutXMLData($this->data));

      foreach ($this->pagamentos as $pagamento) {
        $this->nfe->tagdetPag($this->generatePagamentoData($pagamento));
      }

      $this->currentXML = $this->nfe->getXML();
      $this->currentXML = $this->tools->signNFe($this->currentXML);

      $this->response = $this->tools->sefazEnviaLote([$this->currentXML], str_pad(1, 15, '0', STR_PAD_LEFT), 1);

      $stdCl = new Standardize();
      $std = $stdCl->toStd($this->response);
      $this->setCurrentData($std);
      $this->analisaRetorno($std);
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode([
        'error' => $e->getMessage(),
        "error_tags" => $this->nfe->getErrors(),
        "xml" => $this->nfe->getXML()
      ]);
    }
  }

  private function generatePisDataSimple($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '06';
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pPIS = number_format(0, 2, ".", "");
    $std->vPIS = number_format((floatval($produto['total']) * (0 / 100)), 2, ".", "");

    return $std;
  }

  private function generateConfinsDataSimple($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->CST = '06';
    $std->vBC = number_format($produto['total'], 2, ".", "");
    $std->pCOFINS = number_format(0, 2, ".", "");
    $std->vCOFINS = number_format((floatval($produto['total']) * (0 / 100)), 2, ".", "");

    return $std;
  }

  private function generateIcmssnData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->orig = isset($produto['origem']) ? $produto['origem'] : 0;
    $std->CSOSN = isset($produto['csosn']) ? $produto['csosn'] : '102';

    // Para CSOSN 102 e similares, não há base de cálculo
    // Dependendo do CSOSN, preencher campos específicos
    switch ($std->CSOSN) {
      case '101': // Tributada pelo Simples Nacional com permissão de crédito
        $std->pCredSN = 3.00; // Alíquota aplicável de cálculo do crédito
        $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
        break;

      case '102': // Tributada pelo Simples Nacional sem permissão de crédito
      case '103': // Isenção do ICMS no Simples Nacional para faixa de receita bruta
      case '300': // Imune
      case '400': // Não tributada pelo Simples Nacional
        // Nenhum campo adicional necessário para estes CSOSNs
        break;

      case '201': // Tributada pelo Simples Nacional com permissão de crédito e com cobrança do ICMS por substituição tributária
      case '202': // Tributada pelo Simples Nacional sem permissão de crédito e com cobrança do ICMS por substituição tributária
      case '203': // Isenção do ICMS no Simples Nacional para faixa de receita bruta e com cobrança do ICMS por substituição tributária
        $std->modBCST = 4; // Margem Valor Agregado
        $std->pMVAST = isset($produto['mva']) ? $produto['mva'] : 0.00;
        $std->pRedBCST = isset($produto['reducao_st']) ? $produto['reducao_st'] : 0.00;
        $std->vBCST = isset($produto['base_st']) ? $produto['base_st'] : 0.00;
        $std->pICMSST = isset($produto['aliquota_st']) ? $produto['aliquota_st'] : 0.00;
        $std->vICMSST = isset($produto['valor_st']) ? $produto['valor_st'] : 0.00;

        if ($std->CSOSN == '201') {
          $std->pCredSN = 3.00;
          $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
        }
        break;

      case '500': // ICMS cobrado anteriormente por substituição tributária
        $std->vBCSTRet = isset($produto['base_retida']) ? $produto['base_retida'] : 0.00;
        $std->pST = isset($produto['aliquota_st_retida']) ? $produto['aliquota_st_retida'] : 0.00;
        $std->vICMSSTRet = isset($produto['valor_st_retido']) ? $produto['valor_st_retido'] : 0.00;
        break;

      case '900': // Outros
        $std->modBC = 3;
        $std->vBC = $this->baseCalculo;
        $std->pRedBC = isset($produto['reducao']) ? $produto['reducao'] : 0.00;
        $std->pICMS = isset($produto['aliquota']) ? $produto['aliquota'] : 0.00;
        $std->vICMS = $this->baseCalculo * ($std->pICMS / 100);

        // ST se houver
        if (isset($produto['st']) && $produto['st'] === true) {
          $std->modBCST = 4;
          $std->pMVAST = isset($produto['mva']) ? $produto['mva'] : 0.00;
          $std->pRedBCST = isset($produto['reducao_st']) ? $produto['reducao_st'] : 0.00;
          $std->vBCST = isset($produto['base_st']) ? $produto['base_st'] : 0.00;
          $std->pICMSST = isset($produto['aliquota_st']) ? $produto['aliquota_st'] : 0.00;
          $std->vICMSST = isset($produto['valor_st']) ? $produto['valor_st'] : 0.00;
        }

        // Crédito
        $std->pCredSN = isset($produto['aliquota_credito']) ? $produto['aliquota_credito'] : 0.00;
        $std->vCredICMSSN = $this->baseCalculo * ($std->pCredSN / 100);
        break;
    }

    // Cálculo do valor do ICMS para fins de totalização
    if (in_array($std->CSOSN, ['101', '201', '900']) && isset($std->vCredICMSSN)) {
      $this->valorIcms = $std->vCredICMSSN;
    }

    return $std;
  }

  public function cancelNfe($data)
  {
    try {
      $companyModel = new CompanyModel();
      $company = $companyModel->find([
        "cnpj" => UtilsController::soNumero($data['cnpj'])
      ]);

      if (!$company) {
        http_response_code(404);
        echo json_encode([
          "error" => "Empresa não encontrada"
        ]);
        return;
      }

      $this->company = new CompanyModel($company[0]['id']);
      $this->certificado = UtilsController::getCertifcado($this->company->getCertificado());
      $this->ambiente = intval($this->company->getTpamb()) > 0 ? $this->company->getTpamb() : 1;
      $this->config = $this->setConfig();

      $emissoesModel = new EmissoesModel($data['chave']);
      $emissao = $emissoesModel->getCurrent();

      $this->tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));

      $response = $this->tools->sefazCancela($emissao->chave, $data['justificativa'], $emissao->protocolo);
      $stdCl = new Standardize();
      $std = $stdCl->toStd($response);

      if ($std->cStat == 128) {
        http_response_code(200);
        echo json_encode([
          "status" => "success",
          "message" => "Cancelamento homologado com sucesso!"
        ]);
      } else if ($std->cStat == 135) {
        http_response_code(200);
        echo json_encode([
          "status" => "success",
          "message" => "Cancelamento homologado com sucesso!"
        ]);
      } else {
        http_response_code(403);
        echo json_encode([
          "status" => "error",
          "message" => "Erro ao cancelar: " . $std->xMotivo
        ]);
      }
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function gerarCC($data)
  {
    $chaveNFe = $data['chave'];
    $correcao = $data['carta'];

    $emissoesController = new EmissoesModel($data['chave']);
    $grupoCorrecao = $emissoesController->getCurrent()->sequencia_cc;

    $emissoesController->setSequencia_cc($grupoCorrecao + 1);
    $emissoesController->update();

    $response = $this->tools->sefazCCe($chaveNFe, $correcao, $grupoCorrecao);

    http_response_code(200);
    echo json_encode([
      "status" => "success",
      "message" => "Carta de correção gerada com sucesso!",
      "response" => $response
    ]);
  }

  private function setConfig()
  {
    $config = [
      "atualizacao" => date('Y-m-d H:i:s'),
      "tpAmb"       => $this->ambiente, // 1-Produção / 2-Homologação
      "razaosocial" => $this->company->getRazao_social(),
      "siglaUF"     => $this->company->getUf(),
      "cnpj"        => $this->company->getCnpj(),
      "schemes"     => "PL_009_V4",
      "versao"      => '4.00',
      "tokenIBPT"   => "AAAAAAA",
      "CSC"         => $this->csc,
      "CSCid"       => $this->csc_id,
      "proxyConf"   => [
        "proxyIp"   => "",
        "proxyPort" => "",
        "proxyUser" => "",
        "proxyPass" => ""
      ]
    ];

    return $config;
  }

  private function generateIdeData($data)
  {
    $ufCliente = $data['cliente']['endereco']['uf'];

    $std = new stdClass();
    $std->cUF = $this->company->getCodigo_uf();
    $std->cNF = str_pad((date('Y') . 100), 8, '0', STR_PAD_LEFT);
    $std->natOp = isset($data['operacao']) ? $data['operacao'] : 'VENDA DE MERCADORIA';
    $std->mod = $this->mod;
    $std->serie = $this->serie;
    $std->nNF = $this->numero;
    $std->dhEmi = $this->dataEmissao;
    $std->indPag = 0;
    $std->dhSaiEnt = null;
    $std->tpNF = UtilsController::verificarOperacaoPorCFOP($data['cfop']);
    $std->idDest = strtoupper($ufCliente) === strtoupper($this->company->getUf()) ? 1 : 2;
    $std->cMunFG = $this->company->getCodigo_municipio();
    $std->tpImp = 1;
    $std->tpEmis = $this->modo_emissao;
    $std->cDV = mb_substr($this->currentChave, -1);
    $std->tpAmb = $this->ambiente;
    $std->finNFe = 1;
    $std->indFinal = isset($data['consumidor_final']) && $data['consumidor_final'] === 'S'  ? 1 : 0;

    if ($this->tipoCliente === 'PF') {
      $std->indFinal = 1;
    }

    $std->indPres = 1; // Indica operação presencial
    $std->procEmi   = 0;
    $std->verProc = 1;
    $std->dhCont = null;
    $std->xJust = null;

    return $std;
  }

  private function generateDataCompany()
  {
    $std = new stdClass();
    $std->xNome = $this->company->getRazao_social();
    $std->xFant = $this->company->getNome_fantasia();
    $std->IE = $this->company->getInscricao_estadual();
    $std->CNAE = $this->company->getCnae();
    $std->CRT = $this->company->getCrt();
    $std->CNPJ = $this->company->getCnpj();

    return $std;
  }

  private function generateDataAddress()
  {
    $std = new stdClass();
    $std->xLgr = $this->company->getLogradouro();
    $std->nro = $this->company->getNumero();
    $std->xBairro = $this->company->getBairro();
    $std->cMun = $this->company->getCodigo_municipio();
    $std->xMun = $this->company->getCidade();
    $std->UF = $this->company->getUf();
    $std->CEP = $this->company->getCep();
    $std->cPais = '1058';
    $std->xPais = 'BRASIL';

    return $std;
  }

  private function generateClientData($data)
  {
    $std = new stdClass();

    if (isset($data['cliente']) && !empty($data['cliente'])) {
      $cliente = $data['cliente'];

      if (strtoupper($cliente['nome']) === 'CONSUMIDOR FINAL') {
        $std->xNome = "Consumidor Final";
        $std->CPF = '00000000000';
        $std->indIEDest = 9;
        return $std;
      }

      $std->xNome = $cliente['nome'];

      if ($cliente['tipo_documento'] === 'CPF') {
        $std->CPF = UtilsController::soNumero($cliente['documento']);
        $std->indIEDest = $this->indIEDest;
        return $std;
      }

      $std->CNPJ = UtilsController::soNumero($cliente['documento']);

      $ieInfo = $this->resolveIndIEDest($cliente);
      $std->indIEDest = $ieInfo['indIEDest'];

      if ($ieInfo['indIEDest'] === 1 && !empty($ieInfo['IE'])) {
        $std->IE = $ieInfo['IE'];
      }
    } else {
      $std->xNome = "Consumidor Final";
      $std->CPF = (new UtilsController)->gerarCpfValido();
      $std->indIEDest = 9;
    }

    return $std;
  }

  /**
   * Resolve o indIEDest baseado nas informações do cliente
   * 
   * indIEDest:
   * 1 = Contribuinte ICMS (tem IE numérica válida)
   * 2 = Contribuinte isento de Inscrição (CUIDADO: muitas SEFAZs rejeitam)
   * 9 = Não Contribuinte (pessoa física ou PJ sem IE)
   * 
   * IMPORTANTE: Quando a SEFAZ rejeita indIEDest=1 (Isento), 
   * devemos usar indIEDest=9 (Não Contribuinte) como alternativa segura.
   */
  private function resolveIndIEDest($cliente)
  {
    $result = [
      'indIEDest' => 9, // Default: não contribuinte
      'IE' => null
    ];

    // Se não tem informação de IE, é não contribuinte
    if (!isset($cliente['inscricao_estadual']) || empty($cliente['inscricao_estadual'])) {
      return $result;
    }

    $ie = trim(strtoupper($cliente['inscricao_estadual']));

    // Verificar se é ISENTO ou similar
    $isIsentoTexto = in_array($ie, ['ISENTO', 'ISENTA', 'ISENT', 'IS', 'ISNT', '']);

    // Se informou tipo_icms explicitamente
    if (isset($cliente['tipo_icms'])) {
      $tipoIcms = strtoupper(trim($cliente['tipo_icms']));

      switch ($tipoIcms) {
        case '1': // Contribuinte com IE
        case 'CONTRIBUINTE':
          // Só marca como contribuinte se tiver IE numérica válida
          $ieNumerico = UtilsController::soNumero($ie);
          if (!empty($ieNumerico) && strlen($ieNumerico) >= 2) {
            $result['indIEDest'] = 1;
            $result['IE'] = $ieNumerico;
          } else {
            // IE inválida/ISENTO - usar não contribuinte para evitar rejeição
            $result['indIEDest'] = 9;
          }
          break;

        case '2': // Contribuinte isento
        case 'ISENTO':
        case 'ISENTA':
          // ATENÇÃO: Muitas SEFAZs rejeitam indIEDest=2
          // Por segurança, tratamos como não contribuinte (9)
          // Se precisar forçar, descomentar linha abaixo:
          // $result['indIEDest'] = 2;
          $result['indIEDest'] = 9;
          break;

        case '9': // Não contribuinte
        case 'NAO_CONTRIBUINTE':
        case 'NAO CONTRIBUINTE':
        case 'NC':
        case 'RP': // Revenda própria - tratar como não contribuinte
        default:
          $result['indIEDest'] = 9;
          break;
      }
    } else {
      // Sem tipo_icms definido, inferir pela IE
      if ($isIsentoTexto) {
        // ISENTO -> usar não contribuinte (evita rejeição)
        $result['indIEDest'] = 9;
      } else {
        // Tem IE numérica -> contribuinte
        $ieNumerico = UtilsController::soNumero($ie);
        if (!empty($ieNumerico) && strlen($ieNumerico) >= 2) {
          $result['indIEDest'] = 1;
          $result['IE'] = $ieNumerico;
        } else {
          $result['indIEDest'] = 9;
        }
      }
    }

    return $result;
  }

  private function generateClientAddressData($endereco)
  {
    $std = new stdClass();
    $std->xLgr = $endereco['logradouro'];
    $std->nro = $endereco['numero'];
    $std->xBairro = $endereco['bairro'];
    $std->cMun = $endereco['codigo_municipio'];
    $std->xMun = $endereco['municipio'];
    $std->UF = $endereco['uf'];
    $std->CEP = UtilsController::soNumero($endereco['cep']);
    $std->cPais = '1058';
    $std->xPais = 'BRASIL';

    return $std;
  }

  private function generateProductData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->cProd = $produto['codigo'];
    $std->cEAN = $produto['ean'];
    $std->xProd = $produto['descricao'];
    $std->NCM = $produto['ncm'];
    $std->EXTIPI    = '';
    $std->CFOP = $produto['cfop'];
    $std->uCom = $produto['unidade'];
    $std->qCom = $produto['quantidade'];
    $std->vUnCom = number_format($produto['valor'], 2, ".", "");
    $std->vProd = $produto['total'];
    $std->cEANTrib  = $produto['ean'];
    $std->uTrib = $produto['unidade'];
    $std->qTrib = $produto['quantidade'];
    $std->vUnTrib = number_format($produto['valor'], 2, ".", "");
    $std->indTot = 1;

    if (isset($produto['frete']) && $produto['frete'] > 0) {
      $std->vFrete = number_format($produto['frete'], 2, ".", "");
    }

    if (isset($produto['desconto']) && $produto['desconto'] > 0) {
      $std->vDesc = number_format($produto['desconto'], 2, ".", "");
    }

    if (isset($produto['acrescimo']) && $produto['acrescimo'] > 0) {
      $std->vDesc = number_format($produto['acrescimo'], 2, ".", "");
    }

    $this->total_produtos += floatval($produto['total']);

    return $std;
  }

  private function addCombustivelTag($produto, $item)
  {
    $std = new \stdClass();
    $std->item = $item + 1;
    $std->cProdANP = $produto['codigo_anp'];
    $std->descANP = $produto['descricao_anp'];
    $std->pGLP = $produto['gpl_percentual'];
    $std->pGNn = $produto['gas_percentual_nacional'];
    $std->vPart = $produto['valor_partida'];
    $std->UFCons = $this->company->getUf();

    return $std;
  }

  private function addICMSCombTag($produto, $item)
  {
    $std = new \stdClass();
    $std->item = $item + 1;
    $std->orig = '0';
    $std->CST = '61';
    $std->modBC = '3';
    $std->vBC = '1000.00';
    $std->vBCICMS = '1000.00';
    $std->pICMS = '18.00';
    $std->vICMS = '180.00';
    $std->vBCICMSST = '1200.00';
    $std->pICMSST = '18.00';
    $std->vICMSST = '216.00';
    $std->vBCFCP = '1000.00';
    $std->pFCP = '2.00';
    $std->vFCP = '20.00';
    $std->vBCFCPST = '1200.00';
    $std->pFCPST = '2.00';
    $std->vFCPST = '24.00';
    $std->adRemICMS = '0.50';
    $std->vICMSMono = '50.00';
    $std->adRemICMSRet = '0.30';
    $std->vICMSMonoRet = '30.00';
    $std->qBCMonoRet = $produto['quantidade'];
    return $std;
  }

  private function generateProdutoInfoAdicional($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->infAdProd = $produto['informacoes_adicionais'];

    return $std;
  }

  private function generateImpostoData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->vTotTrib = $produto['total'] * (0 / 100);

    return $std;
  }

  private function generateICMSData($produto, $item)
  {
    $std = new stdClass();

    $percentual_icsm =  floatval($produto['aliquota_icms']);
    $valorIcms = $this->baseCalculo * ($percentual_icsm / 100);

    $std->item = $item;
    $std->orig = $this->origem;

    if ($this->company->getCRT() == 1) {
      $std->CSOSN = $produto['csosn'] ?? '102';
    } else {
      $std->CST = $produto['cst'];
    }

    $std->vBC = number_format($this->baseCalculo, 2, ".", "");
    $std->modBC = $produto['mod_bc'] ?? 0;
    $std->pICMS = number_format($percentual_icsm, 4, ".", "");
    $std->vICMS = number_format($valorIcms, 2, ".", "");

    $this->valorIcms += $valorIcms;

    return $std;
  }

  private function generateIPIData($produto, $item)
  {
    $percentual_ipi =  floatval($produto['aliquota_ipi']);
    $valorIpi = $this->baseCalculo * ($percentual_ipi / 100);

    $std = new stdClass();
    $std->item = $item;
    $std->CST = $produto['cst'];
    $std->cEnq = $produto['enquadramento_legal_ipi'];
    $std->vBC = number_format($this->baseCalculo, 2, ".", "");
    $std->pIPI = number_format($percentual_ipi, 4, ".", "");
    $std->vIPI = number_format($valorIpi, 2, ".", "");

    return $std;
  }

  private function generatePisData($produto, $item)
  {
    $percentual_pis =  floatval($produto['aliquota_pis']);
    $valorPis = $this->baseCalculo * ($percentual_pis / 100);

    $std = new stdClass();
    $std->item      = $item;
    $std->CST       = $produto['cst'];
    $std->vBC       = number_format($this->baseCalculo, 2, ".", "");
    $std->pPIS      = number_format($percentual_pis, 4, ".", "");
    $std->vPIS      = number_format($valorPis, 2, ".", "");

    return $std;
  }

  private function generateConfinsData($produto, $item)
  {
    $percentual_cofins =  floatval($produto['aliquota_cofins']);
    $valorCofins = $this->baseCalculo * ($percentual_cofins / 100);

    $std = new stdClass();
    $std->item      = $item;
    $std->CST       = $produto['cst'];
    $std->vBC       = number_format($this->baseCalculo, 2, ".", "");
    $std->pCOFINS = number_format($percentual_cofins, 4, ".", "");
    $std->vCOFINS = number_format($valorCofins, 2, ".", "");

    return $std;
  }

  private function generateIcmsTot()
  {
    $std = new stdClass();
    $std->vBC = number_format($this->baseTotalIcms, 2, ".", ""); // Use a soma acumulada correta
    $std->vICMS = number_format($this->totalIcms, 2, ".", ""); // Soma do valor do ICMS
    $std->vICMSDeson = 0.00;
    $std->vFCP = 0.00;
    $std->vBCST = 0.00;
    $std->vST = 0.00;
    $std->vFCPST = 0.00;
    $std->vFCPSTRet = 0.00;
    $std->vProd = number_format($this->total_produtos, 2, ".", ""); // Soma do valor total dos produtos
    $std->vFrete = 0.00;
    $std->vSeg = 0.00;
    $std->vDesc = 0.00;
    $std->vII = 0.00;
    $std->vIPI = 0.00;
    $std->vIPIDevol = 0.00;
    $std->vPIS = 0.00;
    $std->vCOFINS = 0.00;
    $std->vOutro = 0.00;
    $std->vNF = number_format($this->total_produtos, 2, ".", ""); // Valor total da nota fiscal
    $std->vTotTrib = 0.00;

    return $std;
  }

  private function generateIcmsInfo($data)
  {
    $std             = new stdClass();
    $std->infAdFisco = '';
    $std->infCpl     = isset($data['observacao']) ? $data['observacao'] : '';

    return $std;
  }

  private function generateFreteData()
  {
    $std = new stdClass();
    $std->modFrete = 9;

    return $std;
  }

  private function generateFaturaData()
  {
    $std = new stdClass();
    $std->vTroco = isset($this->data['troco']) ? $this->data['troco'] : 0;

    return $std;
  }

  private function generatePagamentoData($pagamento)
  {
    $std            = new stdClass();
    $std->indPag    = isset($pagamento['indPag']) ? $pagamento['indPag'] : 0;
    $std->tPag      = STR_PAD($pagamento['tPag'], 2, '0', STR_PAD_LEFT);
    $std->vPag      = number_format($pagamento['valorpago'], 2, ".", "");

    if (in_array($std->tPag, ['03', '04', '17', '3', '4', '17', 3, 4, 17])) {
      $std->tpIntegra = 2;
      $std->tpIntegra   = 2;
      $std->CNPJPag        = "00000000000191";
      $std->tBand       = "99";
      $std->cAut        = "000000";
    }

    return $std;
  }

  public function generateReponsavelTecnicp()
  {
    $std = new stdClass();
    $std->CNPJ      = "45730598000102";
    $std->xContato  = "Logic Tecnologia e Inovação";
    $std->email     = "contato.logictec@gmail.com";
    $std->fone      = "92991225648";
    $std->idCSRT    = "01";

    return $std;
  }

  private function montaChave()
  {
    $this->currentChave = Keys::build(
      $this->company->getCodigo_uf(),
      date('y', strtotime($this->dataEmissao)),
      date('m', strtotime($this->dataEmissao)),
      $this->company->getCnpj(),
      $this->mod,
      $this->serie,
      $this->numero,
      $this->modo_emissao,
      str_pad((date('Y') . $this->numero), 8, '0', STR_PAD_LEFT)
    );
  }

  private function atualizaNumero()
  {
    if ($this->ambiente === 1) {
      $this->company->setNumero_nfe(intval($this->numero) + 1);
      $this->company->update([
        "numero_nfe" => $this->company->getNumero_nfe()
      ]);
    } else {
      $this->company->setNumero_nfe_homologacao(intval($this->numero) + 1);
      $this->company->update([
        "numero_nfe_homologacao" => $this->company->getNumero_nfe_homologacao()
      ]);
    }
  }

  private function conexaoSefaz()
  {
    try {
      $resp_status = $this->tools->sefazStatus(strtoupper($this->company->getUf()), $this->ambiente);
      $stdCl = new Standardize($resp_status);

      $cStatus = $stdCl->toStd()->cStat;
      if ($cStatus === '107') {
        return true;
      }

      return false;
    } catch (\Exception $e) {
      return false;
    }
  }

  private function analisaRetorno($std)
  {
    try {
      if (isset($std->cStat)) {
        $this->setStatus($std->cStat);
        switch ($std->cStat) {
          case 100:
            $this->processarEmissao($std);
            break;
          case 103:
            $this->processarLote($this->getCurrentData());
            break;
          case 104:
            $this->loteProcessado($std);
            break;
          case 105:
            $this->processarLote($this->getCurrentData());
            break;
          default:
            http_response_code(403);
            echo json_encode([
              "código" => $std->cStat,
              "error" => $std->xMotivo,
              "xml" => $this->currentXML
            ]);
            break;
        }
      }
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  private function processarLote($std)
  {
    $recibo = $std->infRec->nRec;
    $this->response = $this->tools->sefazConsultaRecibo($recibo);

    $stdCl = new Standardize();
    $std = $stdCl->toStd($this->response);

    $this->analisaRetorno($std);
  }

  private function loteProcessado($std)
  {
    foreach ($std->protNFe as $prot) {
      $this->analisaRetorno($prot);
    }
  }

  private function processarEmissao($std)
  {
    if (isset($std->protNFe)) {
      $this->currentChave = $std->protNFe->infProt->chNFe;
      $this->numeroProtocolo = $std->protNFe->infProt->nProt;
    }

    if (isset($std->chNFe)) {
      $this->currentChave = $std->chNFe;
    }

    if (isset($std->nProt)) {
      $this->numeroProtocolo = $std->nProt;
    }

    $this->currentXML = Complements::toAuthorize($this->currentXML, $this->response);

    $danfe = new Danfe($this->currentXML);
    $danfe->debugMode(true);
    $danfe->setDefaultFont('arial');
    $danfe->creditsIntegratorFooter('Estoque Premium - Sistema de Gestão Comercial');
    $this->currentPDF = $danfe->render();
    UtilsController::uploadXml($this->currentXML, $this->currentChave);
    $link = UtilsController::uploadPdf($this->currentPDF, $this->currentChave);

    $this->atualizaNumero();
    $this->salvaEmissao();

    http_response_code(200);
    echo json_encode([
      "chave" => $this->currentChave,
      "avisos" => $this->warnings,
      "protocolo" => $this->numeroProtocolo,
      "link" => $_ENV['URL_BASE'] . $link,
      "xml" => $this->currentXML,
      "pdf" => base64_encode($this->currentPDF)
    ]);
  }

  private function salvaEmissao()
  {
    $newEmissao = new EmissoesModel();

    $newEmissao->setChave($this->currentChave);
    $newEmissao->setNumero($this->numero);
    $newEmissao->setSerie($this->serie);
    $newEmissao->setEmpresa($this->company->getCnpj());
    $newEmissao->setXml($this->currentXML);
    $newEmissao->setPdf(base64_encode($this->currentPDF));
    $newEmissao->setTipo('NFE');
    $newEmissao->setProtocolo($this->numeroProtocolo);
    $newEmissao->create();
  }

  private function generateAutXMLData($data)
  {
    $std = new stdClass();
    $std->CNPJ = $data['cnpj_consulta'] ?? '13937073000156';
    return $std;
  }

  /**
   * Get the value of status
   */
  public function getStatus()
  {
    return $this->status;
  }

  /**
   * Set the value of status
   *
   * @return  self
   */
  public function setStatus($status)
  {
    $this->status = $status;

    return $this;
  }

  /**
   * Get the value of currentData
   */
  public function getCurrentData()
  {
    return $this->currentData;
  }

  /**
   * Set the value of currentData
   *
   * @return  self
   */
  public function setCurrentData($currentData)
  {
    $this->currentData = $currentData;

    return $this;
  }
}
