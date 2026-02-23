<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\Connection;
use App\Models\EmissoesModel;
use App\Models\FormaPagamentoModel;
use Dotenv\Dotenv;
use NFePHP\Common\Certificate;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Danfce;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use stdClass;

class CupomFiscalController extends Connection
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
  private $totalIcms = 0;
  private $valorIcms = 0;
  private $data;
  private $numeroProtocolo;
  private $status;
  private $currentData;
  private $response;
  private $warnings = [];
  private $mod = 65;

  public function __construct($data = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    if ($data) {
      $this->nfe = new Make();
      $this->data = $data;

      if ($data['cnpj']) {
        $companyModel = new CompanyModel();
        $company = $companyModel->find([
          "cnpj" => UtilsController::soNumero($data['cnpj'])
        ]);

        if (!$company) {
          throw new \Exception("Empresa não encontrada");
        }

        $this->company = new CompanyModel($company[0]['id']);
        $this->ambiente = intval($this->company->getTpamb()) > 0 ? $this->company->getTpamb() : 1;
        $this->serie = $this->company->getTpamb() === 1 ? $this->company->getSerie_nfce() : $this->company->getSerie_nfce_homologacao();
        $this->numero = $this->company->getTpamb() === 1 ? $this->company->getNumero_nfce() : $this->company->getNumero_nfce_homologacao();
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

        $this->montaChave();
      }
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

      if (isset($this->data['cliente']['documento']) && !empty($this->data['cliente']['documento'])) {
        $this->nfe->tagdest($this->generateClientData($this->data));
      }

      if (isset($this->data['cliente']) && !empty($this->data['cliente']) && strtoupper($this->data['cliente']['nome']) !== 'CONSUMIDOR FINAL') {
        if (isset($this->data['cliente']['endereco']) && !empty($this->data['cliente']['endereco'])) {
          $this->nfe->tagenderDest($this->generateClientAddressData($this->data['cliente']['endereco']));
        }
      }

      if (empty($this->produtos)) {
        throw new \Exception("Nenhum produto informado para emissão da NFC-e");
      }

      foreach ($this->produtos as $index => $produto) {
        $desconto = isset($produto['desconto']) ? floatval($produto['desconto']) : 0;
        $frete = isset($produto['frete']) ? floatval($produto['frete']) : 0;
        $acrescimo = isset($produto['acrescimo']) ? floatval($produto['acrescimo']) : 0;
        $this->baseCalculo = max(0, floatval($produto['total']) - $desconto + $frete + $acrescimo);
        $this->valorIcms = 0;
        $this->nfe->tagprod($this->generateProductData($produto, $index + 1));
        if (isset($produto['informacoes_adicionais']) && !empty($produto['informacoes_adicionais'])) {
          $this->nfe->taginfAdProd($this->generateProdutoInfoAdicional($produto, $index + 1));
        }

        if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
          $this->nfe->tagcomb($this->addCombustivelTag($produto, $index));
        }

        if (isset($produto['codigo_anp']) && !empty($produto['codigo_anp'])) {
          $this->nfe->tagICMS($this->addICMSCombTag($produto, $index));
        } else {
          $this->nfe->tagICMSSN($this->generateIcmssnData($produto, $index + 1));
        }

        $this->nfe->tagimposto($this->generateImpostoData($produto, $index + 1));

        $this->totalIcms += number_format($this->valorIcms, 2, ".", "");
      }

      $this->nfe->tagICMSTot($this->generateIcmsTot());
      $this->nfe->taginfAdic($this->generateIcmsInfo());
      $this->nfe->taginfRespTec($this->generateReponsavelTecnicp());
      $this->nfe->tagtransp($this->generateFreteData());
      $this->nfe->tagpag($this->generateFaturaData());

      if (empty($this->pagamentos)) {
        throw new \Exception("Nenhuma forma de pagamento informada para emissão da NFC-e");
      }

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
        "xml" => $this->currentXML,
        "csc" => $this->csc,
      ]);
    }
  }

  public function cancelNfce($data)
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
      $this->config = $this->setConfig();

      $emissoesModel = new EmissoesModel($data['chave']);
      $emissao = $emissoesModel->getCurrent();

      $this->tools = new Tools(json_encode($this->config), UtilsController::readPfxForNFePHP($this->certificado, $this->company->getSenha()));
      $this->tools->model($this->mod);

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
      echo json_encode([
        'error' => $e->getMessage(),
        "xml" => $this->currentXML,
        "csc" => $this->csc,
      ]);
    }
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
    $std->idDest = 1;
    $std->cMunFG = $this->company->getCodigo_municipio();
    $std->tpImp = 4;
    $std->tpEmis = $this->modo_emissao;
    $std->cDV = mb_substr($this->currentChave, -1);
    $std->tpAmb = $this->ambiente;
    $std->finNFe = 1;
    $std->indFinal = 1;
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
    if (!empty($this->company->getCnae())) {
      $std->CNAE = $this->company->getCnae();
    }
    $std->CRT = $this->company->getCrt() ?? 1;
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
    $std->fone = UtilsController::soNumero($this->company->getTelefone());

    return $std;
  }

  private function generateClientData($data)
  {
    $std = new stdClass();

    $std->indIEDest = 9;

    if (isset($data['cliente']) && !empty($data['cliente'])) {
      $cliente = $data['cliente'];

      if (strtoupper($cliente['nome']) === 'CONSUMIDOR FINAL') {
        $std->xNome = "Consumidor Final";
      } else {
        $std->xNome = $cliente['nome'];
      }

      if ($cliente['tipo_documento'] === 'CPF') {
        $std->CPF = UtilsController::soNumero($cliente['documento']);
      } elseif ($cliente['tipo_documento'] === 'CNPJ') {
        $std->CNPJ = UtilsController::soNumero($cliente['documento']);
      }
    } else {
      $std->xNome = "Consumidor Final";
      $std->CPF = (new UtilsController)->gerarCpfValido();
    }

    return $std;
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
      $std->vOutro = number_format($produto['acrescimo'], 2, ".", "");
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
    $std = new \stdClass();
    $std->item = $item;


    $std->vTotTrib = number_format(0.00, 2, ".", "");

    $std->PIS = new \stdClass();
    $std->PIS->PISAliq = new \stdClass();
    $std->PIS->PISAliq->CST = isset($produto['pis_cst']) ? $produto['pis_cst'] : '07'; // exemplo: 07 = isento
    $std->PIS->PISAliq->vBC = number_format(0.00, 2, ".", "");
    $std->PIS->PISAliq->pPIS = number_format(0.00, 2, ".", "");
    $std->PIS->PISAliq->vPIS = number_format(0.00, 2, ".", "");

    $std->COFINS = new \stdClass();
    $std->COFINS->COFINSAliq = new \stdClass();
    $std->COFINS->COFINSAliq->CST = isset($produto['cofins_cst']) ? $produto['cofins_cst'] : '07';
    $std->COFINS->COFINSAliq->vBC = number_format(0.00, 2, ".", "");
    $std->COFINS->COFINSAliq->pCOFINS = number_format(0.00, 2, ".", "");
    $std->COFINS->COFINSAliq->vCOFINS = number_format(0.00, 2, ".", "");

    return $std;
  }

  private function generateIcmssnData($produto, $item)
  {
    $std = new stdClass();
    $std->item    = $item;
    $std->orig    = $produto['origem'];
    $std->CSOSN   = (string) $this->resolveCSOSN();

    // Fallback: se CSOSN estiver vazio ou inválido, usar 102 (sem crédito)
    $validCsosn = ['101','102','103','300','400','201','202','203','500','900'];
    if (empty($std->CSOSN) || !in_array($std->CSOSN, $validCsosn, true)) {
      $std->CSOSN = '102';
    }

    // Para CSOSN 102, 103, 300, 400 - Simples Nacional sem permissão de crédito
    if (in_array($std->CSOSN, ['102', '103', '300', '400'])) {
      // Apenas origem e CSOSN são obrigatórios
      return $std;
    }

    // Para CSOSN 101 - Simples Nacional com permissão de crédito
    if ($std->CSOSN == '101') {
      $std->pCredSN = 3.00;
      $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
      return $std;
    }

    // Para CSOSN 201 - Simples Nacional com permissão de crédito e com cobrança do ICMS por ST
    if ($std->CSOSN == '201') {
      $std->modBCST = 4;
      $std->pMVAST = 0.00;
      $std->pRedBCST = 0.00;
      $std->vBCST = 0.00;
      $std->pICMSST = 0.00;
      $std->vICMSST = 0.00;
      $std->pCredSN = 3.00;
      $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
      return $std;
    }

    // Para CSOSN 202, 203 - Simples Nacional com cobrança do ICMS por ST
    if (in_array($std->CSOSN, ['202', '203'])) {
      $std->modBCST = 4;
      $std->pMVAST = 0.00;
      $std->pRedBCST = 0.00;
      $std->vBCST = 0.00;
      $std->pICMSST = 0.00;
      $std->vICMSST = 0.00;
      return $std;
    }

    // Para CSOSN 500 - ICMS cobrado anteriormente por ST ou por antecipação
    if ($std->CSOSN == '500') {
      $std->vBCSTRet = 0.00;
      $std->vICMSSTRet = 0.00;
      return $std;
    }

    // Para CSOSN 900 - Outros
    if ($std->CSOSN == '900') {
      $std->modBC = 3;
      $std->vBC = 0.00;
      $std->pRedBC = 0.00;
      $std->pICMS = 0.00;
      $std->vICMS = 0.00;
      $std->modBCST = 4;
      $std->pMVAST = 0.00;
      $std->pRedBCST = 0.00;
      $std->vBCST = 0.00;
      $std->pICMSST = 0.00;
      $std->vICMSST = 0.00;
      $std->pCredSN = 3.00;
      $std->vCredICMSSN = number_format($this->baseCalculo * ($std->pCredSN / 100), 2, ".", "");
      return $std;
    }

    // Caso padrão - retorna apenas origem e CSOSN
    return $std;
  }

  private function resolveCSOSN()
  {
    $validCsosn = ['101','102','103','300','400','201','202','203','500','900'];
    $csosn = (string) ($this->company->getSituacao_tributaria() ?? '');

    if (!empty($csosn) && in_array($csosn, $validCsosn, true)) {
      return $csosn;
    }

    // Mapeia possíveis códigos vindos da request
    if (isset($this->data['cliente']['tipo_icms'])) {
      $tipo = strtoupper(trim((string)$this->data['cliente']['tipo_icms']));
      switch ($tipo) {
        case '101':
        case 'CSOSN101':
        case 'CREDITO':
          return '101';
        case 'RP':
        case 'ISENTO':
        case 'NAO TRIBUTADO':
        case 'NAO-TRIBUTADO':
        case 'SEM TRIBUTACAO':
          return '102';
        case '201':
        case '202':
        case '203':
        case '500':
        case '900':
          // aceita diretamente se vier válido
          if (in_array($tipo, $validCsosn, true)) {
            return $tipo;
          }
          break;
        default:
          break;
      }
    }

    // Fallback seguro
    return '102';
  }

  private function generateIcmsTot()
  {
    $std = new stdClass();
    $std->vBC = number_format(0, 2, ".", "");
    $std->vICMS = number_format($this->totalIcms, 2, ".", "");
    $std->vICMSDeson = 0000.00;
    $std->vFCP       = 0000.00;
    $std->vBCST      = 0000.00;
    $std->vST        = 0000.00;
    $std->vFCPST     = 0000.00;
    $std->vFCPSTRet  = 0000.00;
    $std->vProd = $this->total_produtos;
    $std->vNF = $this->total_produtos;
    $std->vTotTrib = 0;

    return $std;
  }

  private function generateIcmsInfo()
  {
    $std             = new stdClass();
    $std->infAdFisco = '';
    $std->infCpl     = '';

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
      $this->company->setNumero_nfce(intval($this->numero) + 1);
      $this->company->update([
        "numero_nfce" => $this->company->getNumero_nfce()
      ]);
    } else {
      $this->company->setNumero_nfce_homologacao(intval($this->numero) + 1);
      $this->company->update([
        "numero_nfce_homologacao" => $this->company->getNumero_nfce_homologacao()
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
              "error" => $std->xMotivo,
              "xml" => $this->currentXML,
              "csc" => $this->csc,
            ]);
            break;
        }
      }
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode([
        'error' => $e->getMessage(),
        "xml" => $this->currentXML
      ]);
    }
  }

  private function processarLote($std)
  {
    $recibo = $std->infRec->nRec;
    $response = $this->tools->sefazConsultaRecibo($recibo);

    $stdCl = new Standardize();
    $std = $stdCl->toStd($response);

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

    $danfe = new Danfce($this->currentXML);
    $danfe->debugMode(true);
    $danfe->setPaperWidth(80);
    $danfe->setMargins(2);
    $danfe->setDefaultFont('arial');
    $danfe->setOffLineDoublePrint(false);
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
    $newEmissao->setTipo('NFCE');
    $newEmissao->setProtocolo($this->numeroProtocolo);
    $newEmissao->create();
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
