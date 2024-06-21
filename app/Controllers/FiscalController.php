<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\Connection;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\NFe\Complements;
use stdClass;

class FiscalController extends Connection
{
  private $nfe;
  private $currentXML;
  private $config;
  private $ambiente = 2;
  private $company;
  private $recibo;
  private $certificado;

  public function __construct($cnpj = null)
  {
    $this->nfe = new Make();

    if ($cnpj) {
      $companyModel = new CompanyModel();
      $company = $companyModel->find([
        "cnpj" => $cnpj
      ]);

      $this->company = new CompanyModel($company[0]['id']);
      $this->certificado = $this->getCertifcado();
      $this->config = $this->setConfig();
    }
  }

  public function createNfe()
  {
    try {
      $std = new stdClass();
      $std->versao = '4.00';
      $this->nfe->taginfNFe($std);
      $this->nfe->tagide($this->generateDataSale([]));
      $this->nfe->tagemit($this->generateDataCompany());
      $this->nfe->tagenderEmit($this->generateDataAddress([]));
      $this->nfe->tagdest($this->generateClientData([]));
      $this->nfe->tagenderDest($this->generateClientAddressData([]));
      $this->nfe->tagprod($this->generateProductData());
      $this->nfe->tagimposto($this->generateImpostoData());
      $this->nfe->tagICMS($this->generateIcmsData());
      $this->nfe->tagIPI($this->generateIpiData());
      $this->nfe->tagPIS($this->generatePisData());
      $this->nfe->tagCOFINS($this->generateCofinsData());
      $this->nfe->tagICMSTot($this->generateIcmsTot());
      $this->nfe->tagtransp($this->generateFreteData());
      $this->nfe->tagvol($this->generateVolume());
      $this->nfe->tagpag($this->generateFaturaData());
      $this->nfe->tagdup($this->generateDuplicataData());
      $this->nfe->tagdetPag($this->generatePagamentoData());
      $this->nfe->taginfRespTec($this->generateReponsavelTecnicp());

      $this->currentXML = $this->nfe->getXML();

      $tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));
      $xmlAssinado = $tools->signNFe($this->currentXML);

      $idLote = str_pad(100, 15, '0', STR_PAD_LEFT); // Identificador do lote
      $resp = $tools->sefazEnviaLote([$xmlAssinado], $idLote);

      $st = new Standardize();
      $std = $st->toStd($resp);
      if ($std->cStat != 103) {
        http_response_code(500);
        echo json_encode(['error' => "[$std->cStat] $std->xMotivo"]);
        return;
      }

      $this->recibo = $std->infRec->nRec;
      $protocolo = $tools->sefazConsultaRecibo($this->recibo);

      $protocol = new Complements();
      $xmlProtocolado = $protocol->toAuthorize($xmlAssinado, $protocolo);

      var_dump($xmlProtocolado);
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

  private function generateDataSale($data)
  {
    $std = new stdClass();
    $std->cUF = 13;
    $std->cNF = '80070008';
    $std->natOp = 'VENDA';
    $std->indPag = 0;
    $std->mod = 55;
    $std->serie = 1;
    $std->nNF = 2;
    $std->dhEmi = '2024-06-19T20:48:00-02:00';
    $std->dhSaiEnt = '2024-06-19T20:48:00-02:00';
    $std->tpNF = 1;
    $std->idDest = 1;
    $std->cMunFG = '1302603';
    $std->tpImp = 1;
    $std->tpEmis = 1;
    $std->cDV = 2;
    $std->tpAmb = 2;
    $std->finNFe = 1;
    $std->indFinal = 0;
    $std->indPres = 0;
    $std->procEmi   = 0;
    $std->verProc = 1;

    return $std;
  }

  private function generateDataCompany()
  {
    $std = new stdClass();
    $std->xNome = $this->company->getRazao_social();
    $std->IE = $this->company->getInscricao_estadual();
    $std->CRT = 3;
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
    $std->xNome = 'Empresa destinatário teste';
    $std->indIEDest = 1;
    $std->IE = '6564344535';
    $std->CNPJ = '78767865000156';

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

  private function generateProductData()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->cProd = '0001';
    $std->xProd = "Coca Cola 1L";
    $std->NCM = '22021000';
    $std->CFOP = '5102';
    $std->uCom = 'UN';
    $std->qCom = '1.0000';
    $std->vUnCom = '10.99';
    $std->vProd = '10.99';
    $std->uTrib = 'UN';
    $std->qTrib = '1.0000';
    $std->vUnTrib = '10.99';
    $std->indTot = 1;
    $std->cEAN = 'SEM GTIN';
    $std->cEANTrib  = 'SEM GTIN';

    return $std;
  }

  private function generateImpostoData()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->vTotTrib = 10.99;

    return $std;
  }

  private function generateIcmsData()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->orig = 0;
    $std->CST = '00';
    $std->modBC = 0;
    $std->vBC = 0.20;
    $std->pICMS = '18.0000';
    $std->vICMS = '0.04';

    return $std;
  }

  private function generateIpiData()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->cEnq = '999';
    $std->CST = '50';
    $std->vIPI = 0;
    $std->vBC = 0;
    $std->pIPI = 0;

    return $std;
  }

  private function generatePisData()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->CST = '07';
    $std->vBC = 0;
    $std->pPIS = 0;
    $std->vPIS = 0;

    return $std;
  }

  private function generateCofinsData()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->CST = '07';
    $std->vBC = 0;
    $std->pCOFINS = 0;
    $std->vCOFINS = 0;

    return $std;
  }

  private function generateIcmsTot()
  {
    $std = new stdClass();
    $std->vBC = 0.20;
    $std->vICMS = 0.04;
    $std->vICMSDeson = 0.00;
    $std->vBCST = 0.00;
    $std->vST = 0.00;
    $std->vProd = 10.99;
    $std->vFrete = 0.00;
    $std->vSeg = 0.00;
    $std->vDesc = 0.00;
    $std->vII = 0.00;
    $std->vIPI = 0.00;
    $std->vPIS = 0.00;
    $std->vCOFINS = 0.00;
    $std->vOutro = 0.00;
    $std->vNF = 10.99;
    $std->vTotTrib = 0.00;

    return $std;
  }

  private function generateFreteData()
  {
    $std = new stdClass();
    $std->modFrete = 1;

    return $std;
  }

  private function generateVolume()
  {
    $std = new stdClass();
    $std->item = 1;
    $std->qVol = 2;
    $std->esp = 'caixa';
    $std->marca = 'OLX';
    $std->nVol = '11111';
    $std->pesoL = 10.00;
    $std->pesoB = 11.00;

    return $std;
  }

  private function generateFaturaData()
  {
    $std = new stdClass();
    $std->nFat = '100';
    $std->vOrig = 100;
    $std->vLiq = 100;

    return $std;
  }

  private function generateDuplicataData()
  {
    $std = new stdClass();
    $std->nDup = '100';
    $std->dVenc = '2024-06-19';
    $std->vDup = 11.03;

    return $std;
  }

  private function generatePagamentoData()
  {
    $std            = new stdClass();
    $std->tPag      = '01';
    $std->vPag      = 100.00;

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

  private function getCertifcado()
  {
    $folderPath = "app/storage/certificados";
    $certificadoPath = $folderPath . "/" . $this->company->getCertificado();
    return file_get_contents($certificadoPath);
  }

  public function testCertificate($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => $cnpj
    ]);

    if ($company) {
      $company = new CompanyModel($company[0]['id']);

      $folderPath = "app/storage/certificados";
      $certificadoPath = $folderPath . "/" . $company->getCertificado();

      $certInfo = openssl_pkcs12_read(file_get_contents($certificadoPath), $certs, $company->getSenha());

      if ($certInfo) {
        $data = openssl_x509_parse($certs['cert']);
        $data = json_encode($data);
        $data = json_decode($data);

        list($nome, $documento) = explode(":", $data->subject->CN);

        $dt_emissao    = date('Y-m-d', $data->validTo_time_t);
        $dt_vencimento = date('Y-m-d', $data->validTo_time_t);

        $result = [
          "emissao" => $dt_emissao,
          "dt_vencimento" => $dt_vencimento,
          "nome" => $nome,
          "documento" => $documento
        ];

        http_response_code(200); // OK
        echo json_encode($result);
      } else {
        http_response_code(500); // Not Found
        while ($msg = openssl_error_string()) {
          echo $msg . "\n";
        }
      }
    }
  }
}
