<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\Connection;
use App\Models\EmissoesModel;
use App\Models\FormaPagamentoModel;
use NFePHP\Common\Certificate;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Danfce;
use NFePHP\NFe\Common\Standardize;
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
  private $ambiente = 2;
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
  private $warnings = [];

  public function __construct($data = null)
  {
    if ($data) {
      $this->nfe = new Make();
      $this->data = $data;

      if ($data['cnpj']) {
        $companyModel = new CompanyModel();
        $company = $companyModel->find([
          "cnpj" => UtilsController::soNumero($data['cnpj'])
        ]);

        $this->company = new CompanyModel($company[0]['id']);
        $this->certificado = UtilsController::getCertifcado($this->company->getCertificado());
        $this->config = $this->setConfig();
        $this->dataEmissao = date('Y-m-d\TH:i:sP');
        $this->modo_emissao = $data['modoEmissao'];

        $this->produtos = $data['produtos'];
        $this->pagamentos = array_map(function ($pagamento) {
          $formaPagamentoModel = new FormaPagamentoModel($pagamento['codigo']);

          return [
            "indPag"    => $formaPagamentoModel->getCurrent()->cod_meio,
            "tPag"      => $formaPagamentoModel->getCurrent()->codigo,
            "valorpago" => $pagamento['valorpago']
          ];
        }, $data['pagamentos']);

        $this->tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));
        $this->tools->model('65');

        if ($this->conexaoSefaz() === false) {
          $this->modo_emissao = 9;
          array_push($this->warnings, "Não foi possível se conectar com a SEFAZ, a nota será emitida em modo de contingência");
        }

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
      $this->nfe->tagide($this->generateIdeData([]));
      $this->nfe->tagemit($this->generateDataCompany());
      $this->nfe->tagenderEmit($this->generateDataAddress());
      $this->nfe->tagdest($this->generateClientData($this->data));

      if (isset($data['cliente']) && !empty($data['cliente'])) {
        $this->nfe->tagenderDest($this->generateClientAddressData($data['cliente']['endereco']));
      }

      foreach ($this->produtos as $index => $produto) {
        $this->baseCalculo = ($produto['total'] - $produto['desconto'] + $produto['frete'] + $produto['acrescimo']);
        $this->valorIcms = 0;
        $this->nfe->tagprod($this->generateProductData($produto, $index + 1));
        if (isset($produto['informacoes_adicionais']) && !empty($produto['informacoes_adicionais'])) {
          $this->nfe->taginfAdProd($this->generateProdutoInfoAdicional($produto, $index + 1));
        }
        $this->nfe->tagimposto($this->generateImpostoData($produto, $index + 1));
        $this->nfe->tagICMSSN($this->generateIcmssnData($produto, $index + 1));
        $this->totalIcms += number_format($this->valorIcms, 2, ".", "");
      }

      $this->nfe->tagICMSTot($this->generateIcmsTot());
      $this->nfe->taginfAdic($this->generateIcmsInfo());
      $this->nfe->taginfRespTec($this->generateReponsavelTecnicp());
      $this->nfe->tagtransp($this->generateFreteData());
      $this->nfe->tagpag($this->generateFaturaData());

      foreach ($this->pagamentos as $pagamento) {
        $this->nfe->tagdetPag($this->generatePagamentoData($pagamento));
      }

      $this->nfe->montaNFe();
      $this->currentXML = $this->nfe->getXML();
      $this->currentXML = $this->tools->signNFe($this->currentXML);

      $response = $this->tools->sefazEnviaLote([$this->currentXML], 1);

      $stdCl = new Standardize();
      $std = $stdCl->toStd($response);
      $this->setCurrentData($std);
      $this->analisaRetorno($std);
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage(), "error_tags" => $this->nfe->getErrors()]);
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

      $this->tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));

      $response = $this->tools->sefazCancela($emissao->chave, $data['justificativa'], $emissao->protocolo, 2);
      $stdCl = new Standardize();
      $std = $stdCl->toStd($response);

      if ($std->cStat == 128) {
        foreach ($std->retEvento as $evento) {
          if ($evento->infEvento->cStat == 135) {
            $protocolo = $evento->infEvento->nProt;
            echo "Cancelamento homologado com sucesso! Protocolo: " . $protocolo;

            http_response_code(200);
            echo json_encode([
              "status" => "success",
              "message" => "Cancelamento homologado com sucesso!",
              "protocolo" => $protocolo
            ]);
          } else {
            // Cancelamento rejeitado
            echo "Erro ao cancelar: " . $evento->infEvento->xMotivo;

            http_response_code(500);
            echo json_encode([
              "status" => "error",
              "message" => "Erro ao cancelar: " . $evento->infEvento->xMotivo
            ]);
          }
        }
      } else if ($std->cStat == 135) {
        echo "Cancelamento realizado com sucesso!";
      } else {
        echo "Erro ao cancelar: " . $std->xMotivo;
      }
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
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
      "CSC"         => $this->company->getCsc(),
      "CSCid"       => $this->company->getCsc_id(),
      "proxyConf"   => [
        "proxyIp"   => "",
        "proxyPort" => "",
        "proxyUser" => "",
        "proxyPass" => ""
      ]
    ];

    return $config;
  }

  private function generateIdeData()
  {
    $std = new stdClass();
    $std->cUF = $this->company->getCodigo_uf();
    $std->cNF = str_pad((date('Y') . 100), 8, '0', STR_PAD_LEFT);
    $std->natOp = 'VENDA';
    $std->mod = 65;
    $std->serie = $this->company->getSerie_nfce();
    $std->nNF = $this->company->getNumero_nfce();
    $std->dhEmi = $this->dataEmissao;
    $std->indPag = 0;
    $std->dhSaiEnt = null;
    $std->tpNF = 1;
    $std->idDest = 1;
    $std->cMunFG = $this->company->getCodigo_municipio();
    $std->tpImp = 4;
    $std->tpEmis = $this->modo_emissao;
    $std->cDV = mb_substr($this->currentChave, -1);
    $std->tpAmb = 2;
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
    $std->CNAE = $this->company->getCnae();
    $std->CRT = 1;
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
    $std->indIEDest = 9;

    if (isset($data['cliente']) && !empty($data['cliente'])) {
      $cliente = $data['cliente'];
      $std->xNome = $cliente['nome'];
      if ($cliente['tipo_documento'] === 'CPF') {
        $std->CPF = UtilsController::soNumero($cliente['documento']);
      } else {
        $std->CNPJ = UtilsController::soNumero($cliente['documento']);
      }
    } else {
      $std->xNome = "Consumidor Final";
      $std->CPF = '00000000000';
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
    $std->CEP = UtilsController::soNumero($endereco['uf']);
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

  private function generateIcmssnData($produto, $item)
  {
    $std = new stdClass();
    $std->item    = $item;
    $std->orig    = $produto['origem'];
    $std->CSOSN   = $this->company->getSituacao_tributaria();
    $std->vBC     = 0.00;
    $std->pICMS   = 0.00;
    $std->vICMS   = 0.00;

    $std->modBCST         = 4;
    $std->pMVAST          = 0.00;
    $std->pRedBCST        = 0.00;
    $std->vBCST           = 0.00;
    $std->pICMSST         = 0.00;
    $std->vICMSST         = 0.00;
    $std->pRedBC          = 0.00;
    $std->pCredSN         = 3.00;
    $std->vCredICMSSN     = $this->baseCalculo * ($std->pCredSN / 100);
    $std->vBCSTRet        = 0.00;
    $std->vICMSSTRet      = 0.00;
    $std->vBCSTRet        = null;
    $std->vICMSSTRet      = null;
    $std->pST             = null;
    $std->vICMSSubstituto = null;
    $std->pRedBCEfet      = null;
    $std->vBCEfet         = null;
    $std->pICMSEfet       = null;
    $std->vICMSEfet       = null;

    return $std;
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
    $std->vTroco = $this->data['troco'];

    return $std;
  }

  private function generatePagamentoData($pagamento)
  {
    $std            = new stdClass();
    $std->indPag    = $pagamento['indPag'];
    $std->tPag      = STR_PAD($pagamento['tPag'], 2, '0', STR_PAD_LEFT);
    $std->vPag      = number_format($pagamento['valorpago'], 2, ".", "");

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
      13,
      date('y', strtotime($this->dataEmissao)),
      date('m', strtotime($this->dataEmissao)),
      $this->company->getCnpj(),
      65,
      $this->company->getSerie_nfce(),
      $this->company->getNumero_nfce(),
      $this->modo_emissao,
      str_pad((date('Y') . $this->company->getNumero_nfce()), 8, '0', STR_PAD_LEFT)
    );
  }

  private function atualizaNumero()
  {
    $this->company->setNumero_nfce(intval($this->company->getNumero_nfce()) + 1);
    $this->company->update([
      "numero_nfce" => $this->company->getNumero_nfce()
    ]);
  }

  private function conexaoSefaz()
  {
    try {
      $tpAmb = $this->ambiente;
      $uf    =  strtoupper($this->company->getUf());
      $resp_status = $this->tools->sefazStatus($uf, $tpAmb);
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
              "error" => $std->xMotivo
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
      "link" => "http://localhost/emissor-api/" . $link,
      "xml" => $this->currentXML,
      "pdf" => base64_encode($this->currentPDF)
    ]);
  }

  private function salvaEmissao()
  {
    $newEmissao = new EmissoesModel();

    $newEmissao->setChave($this->currentChave);
    $newEmissao->setNumero($this->company->getNumero_nfce());
    $newEmissao->setSerie($this->company->getSerie_nfce());
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
