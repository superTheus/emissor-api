<?php

namespace App\Controllers;

use App\Controllers\Fiscal\CRT1Controller;
use App\Controllers\Fiscal\CRT2Controller;
use App\Controllers\Fiscal\CRT3Controller;
use App\Controllers\Fiscal\CRT4Controller;
use App\Models\CompanyModel;
use Dotenv\Dotenv;

class FiscalController
{
  private $data;
  private $crt;
  private $handler;

  public function __construct($data = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    if (!$data) {
      http_response_code(400);
      echo json_encode(['error' => 'Dados não fornecidos']);
      exit;
    }

    if (!isset($data['cnpj']) || empty($data['cnpj'])) {
      http_response_code(400);
      echo json_encode(['error' => 'CNPJ não informado']);
      exit;
    }

    $this->data = $data;

    $crt = $this->resolveCrtByCnpj($data['cnpj']);
    if ($crt === null) {
      http_response_code(404);
      echo json_encode(['error' => 'Empresa não encontrada']);
      exit;
    }

    $this->crt = $crt;
    $this->handler = $this->makeHandlerByCrt($this->crt, $this->data);
  }

  private function resolveCrtByCnpj($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => UtilsController::soNumero($cnpj)
    ]);

    if (!$company) {
      return null;
    }

    $companyObj = new CompanyModel($company[0]['id']);
    return (int)$companyObj->getCrt();
  }

  private function makeHandlerByCrt($crt, $data)
  {
    switch ((int)$crt) {
      case 1:
        return new CRT1Controller($data);
      case 2:
        return new CRT2Controller($data);
      case 3:
        return new CRT3Controller($data);
      case 4:
        return new CRT4Controller($data);
      default:
        http_response_code(400);
        echo json_encode([
          'error' => 'CRT inválido ou não suportado',
          'crt' => $crt
        ]);
        exit;
    }
  }

  private function handlerFromRequestData($data)
  {
    if (!isset($data['cnpj']) || empty($data['cnpj'])) {
      http_response_code(400);
      echo json_encode(['error' => 'CNPJ não informado']);
      return null;
    }

    $crt = $this->resolveCrtByCnpj($data['cnpj']);
    if ($crt === null) {
      http_response_code(404);
      echo json_encode(['error' => 'Empresa não encontrada']);
      return null;
    }

    return $this->makeHandlerByCrt($crt, $data);
  }

  public function createNfe()
  {
    return $this->handler->createNfe();
  }

  public function cancelNfe($data)
  {
    // Para cancelamento, o payload geralmente é diferente da emissão.
    // Então resolvemos o handler baseado no CNPJ do payload.
    $handler = $this->handlerFromRequestData($data);
    if (!$handler) {
      return;
    }

    return $handler->cancelNfe($data);
  }

  public function gerarCC($data)
  {
    $handler = $this->handlerFromRequestData($data);
    if (!$handler) {
      return;
    }

    return $handler->gerarCC($data);
  }
}

__halt_compiler();

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
    $std->fone = $this->company->getTelefone();

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

  private function generateIBSData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;

    $base = $produto['total']
      - $produto['desconto']
      + $produto['frete']
      + $produto['acrescimo'];

    $std->vBC  = number_format($base, 2, '.', '');
    $std->pIBS = number_format($this->aliquotaIbs, 2, '.', '');
    $std->vIBS = number_format($base * ($this->aliquotaIbs / 100), 2, '.', '');

    return $std;
  }

  private function generateCBSData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;

    $base = $produto['total']
      - $produto['desconto']
      + $produto['frete']
      + $produto['acrescimo'];

    $std->vBC  = number_format($base, 2, '.', '');
    $std->pCBS = number_format($this->aliquotaCbs, 2, '.', '');
    $std->vCBS = number_format($base * ($this->aliquotaCbs / 100), 2, '.', '');

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
    $std->CNPJ = isset($data['cnpj_consulta']) ? UtilsController::soNumero($data['cnpj_consulta']) : '13937073000156';
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
