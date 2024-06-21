<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\Connection;
use NFePHP\Common\Certificate;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Danfce;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use stdClass;

class CupomFiscalController extends Connection
{
  private $nfe;
  private $tools;
  private $currentXML;
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

  public function __construct($cnpj = null)
  {
    $this->nfe = new Make();

    if ($cnpj) {
      $companyModel = new CompanyModel();
      $company = $companyModel->find([
        "cnpj" => $cnpj
      ]);

      $this->company = new CompanyModel($company[0]['id']);
      $this->certificado = UtilsController::getCertifcado($this->company->getCertificado());
      $this->config = $this->setConfig();
      $this->dataEmissao = date('Y-m-d\TH:i:sP');

      array_push($this->produtos, [
        "codigo" => str_pad(1, 4, "0", STR_PAD_LEFT),
        "ean" => "7892840800079",
        "descricao" => "REFRIGERANTE PEPSI LATA 350ML",
        "ncm" => "22021000",
        "cfop" => "5102",
        "unidade" => "UN",
        "quantidade" => 1,
        "valor" => 4.50,
        "total" => 4.50,
      ]);

      array_push($this->pagamentos, [
        "indPag"    => 1,
        "tPag"      => 4,
        "valorpago" => 4.50
      ]);

      $this->tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));
      $this->tools->model('65');

      $this->montaChave();
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
      $this->nfe->tagenderEmit($this->generateDataAddress([]));
      $this->nfe->tagdest($this->generateClientData([]));
      $this->nfe->tagenderDest($this->generateClientAddressData([]));

      foreach ($this->produtos as $index => $produto) {
        $this->baseCalculo = $produto['total'];
        $this->valorIcms = 0;
        $this->nfe->tagprod($this->generateProductData($produto, $index + 1));
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

      $danfe = new Danfce($this->currentXML);
      $danfe->debugMode(true);
      $danfe->setPaperWidth(80);
      $danfe->setMargins(2);
      $danfe->setDefaultFont('arial');
      $danfe->setOffLineDoublePrint(false);
      $danfe->creditsIntegratorFooter('Estoque Premium - Sistema de Gestão Comercial');
      $pdf = $danfe->render();
      UtilsController::uploadXml($this->currentXML, $this->currentChave);
      UtilsController::uploadPdf($pdf, $this->currentChave);

      echo $this->currentChave;
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage(), "error_tags" => $this->nfe->getErrors()]);
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

  private function generateIdeData($data)
  {
    $std = new stdClass();
    $std->cUF = 13;
    $std->cNF = str_pad((date('Y') . 100), 8, '0', STR_PAD_LEFT);
    $std->natOp = 'VENDA';
    $std->mod = 65; // Modelo 65 para NFC-e
    $std->serie = 1;
    $std->nNF = 100;
    $std->dhEmi = $this->dataEmissao;
    $std->indPag = 0;
    $std->dhSaiEnt = null;
    $std->tpNF = 1;
    $std->idDest = 1;
    $std->cMunFG = '1302603';
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

  private function generateDataAddress($data)
  {
    $std = new stdClass();
    $std->xLgr = $this->company->getLogradouro();
    $std->nro = $this->company->getNumero();
    $std->xBairro = $this->company->getBairro();
    $std->cMun = '1302603';
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
    $std->xNome = 'Matheus Souza';
    $std->indIEDest = 9;
    $std->CPF = '70259203203';
    return $std;
  }

  private function generateClientAddressData($data)
  {
    $std = new stdClass();
    $std->xLgr = "Rua Teste";
    $std->nro = '203';
    $std->xBairro = 'Compensa';
    $std->cMun = '1302603';
    $std->xMun = 'Manaus';
    $std->UF = 'AM';
    $std->CEP = 69035115;
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
    $std->vUnCom = $produto['valor'];
    $std->vProd = $produto['total'];
    $std->cEANTrib  = $produto['ean'];
    $std->uTrib = $produto['unidade'];
    $std->qTrib = $produto['quantidade'];
    $std->vUnTrib = $produto['valor'];
    $std->indTot = 1;

    $this->total_produtos += $produto['total'];

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
    $std->orig    = 0;
    $std->CSOSN   = '102';
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
    $std->vTroco = 0;

    return $std;
  }

  private function generatePagamentoData($pagamento)
  {
    $std            = new stdClass();
    $std->indPag    = $pagamento['indPag'];
    $std->tPag      = STR_PAD($pagamento['tPag'], 2, '0', STR_PAD_LEFT);
    $std->vPag      = $pagamento['valorpago'];

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
      1,
      100,
      $this->modo_emissao,
      str_pad((date('Y') . 100), 8, '0', STR_PAD_LEFT)
    );
  }
}
