<?php

namespace App\Controllers\Fiscal;

use App\Controllers\UtilsController;
use App\Models\CompanyModel;
use App\Models\Connection;
use App\Models\EmissoesEventosModel;
use App\Models\EmissoesModel;
use App\Models\FormaPagamentoModel;
use Dotenv\Dotenv;
use NFePHP\Common\Certificate;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Daevento;
use NFePHP\DA\NFe\Danfe;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use stdClass;

/**
 * Classe base abstrata para emissão de NFe
 * Contém toda a lógica comum compartilhada entre os diferentes regimes tributários
 */
abstract class BaseFiscalController extends Connection
{
  protected $nfe;
  protected $tools;
  protected $currentXML;
  protected $currentPDF;
  protected $config;
  protected $numero;
  protected $serie;
  protected $csc;
  protected $csc_id;
  protected $ambiente;
  protected $company;
  protected $certificado;
  protected $modo_emissao = 1;
  protected $currentChave;
  protected $dataEmissao;
  protected $total_produtos = 0;
  protected $produtos = [];
  protected $pagamentos = [];
  protected $baseCalculo = 0;
  protected $baseTotalIcms = 0;
  protected $origem = 0;
  protected $totalIcms = 0;
  protected $valorIcms = 0;
  protected $data;
  protected $numeroProtocolo;
  protected $status;
  protected $currentData;
  protected $warnings = [];
  protected $response;
  protected $mod = 55;
  protected $tipoCliente = 'PJ';
  protected $indIEDest = 9;
  protected $aliquotaIbsEstadual = 0.00;
  protected $aliquotaIbsMunicipal = 0.00;
  protected $aliquotaCbs = 0.00;


  public function __construct($data = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 3));
    $dotenv->load();

    try {
      if ($data) {
        $this->data = $data;

        if (isset($data['cliente']) && isset($data['produtos']) && isset($data['cnpj'])) {
          $this->initializeFromData($data);
        }
      }
    } catch (\Exception $e) {
      http_response_code(500);
      echo json_encode(['error' => $e->getMessage()]);
      exit;
    }
  }

  /**
   * Carrega a empresa, configura ambiente/serie/numero/CSC e inicializa Tools.
   * Retorna false caso a empresa não seja encontrada.
   */
  protected function bootstrapCompanyAndToolsByCnpj($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => UtilsController::soNumero($cnpj)
    ]);

    if (!$company) {
      return false;
    }

    $this->company = new CompanyModel($company[0]['id']);
    $this->ambiente = intval($this->company->getTpamb()) > 0 ? $this->company->getTpamb() : 1;
    $this->serie = $this->company->getTpamb() === 1 ? $this->company->getSerie_nfe() : $this->company->getSerie_nfe_homologacao();
    $this->numero = $this->company->getTpamb() === 1 ? $this->company->getNumero_nfe() : $this->company->getNumero_nfe_homologacao();
    $this->csc = $this->company->getTpamb() === 1 ? $this->company->getCsc() : $this->company->getCsc_homologacao();
    $this->csc_id = $this->company->getTpamb() === 1 ? $this->company->getCsc_id() : $this->company->getCsc_id_homologacao();
    $this->certificado = UtilsController::getCertifcado($this->company->getCertificado());
    $this->config = $this->setConfig();

    // Para emissão, dataEmissao é relevante; para cancelamento/CCe, não atrapalha.
    if (empty($this->dataEmissao)) {
      // Usa a data/hora atual do servidor com timezone configurado
      $this->dataEmissao = (new \DateTime('now'))->format('Y-m-d\TH:i:sP');
    }

    $this->tools = new Tools(json_encode($this->config), Certificate::readPfx($this->certificado, $this->company->getSenha()));
    $this->tools->model($this->mod);

    return true;
  }

  /**
   * Inicializa o controller com os dados recebidos
   */
  protected function initializeFromData($data)
  {
    $this->tipoCliente = $data['cliente']['tipo_documento'] === 'CPF' ? 'PF' : 'PJ';

    if ($this->tipoCliente === 'PF') {
      $this->indIEDest = 9;
    }

    if ($this->tipoCliente === 'PJ') {
      $this->indIEDest = isset($data['cliente']['inscricao_estadual']) && !empty($data['cliente']['inscricao_estadual']) ?
        $data['cliente']['tipo_icms'] ?? 1
        : 9;
    }

    $this->nfe = new Make(10);
    $this->data = $data;

    if (!isset($data['cnpj']) || empty($data['cnpj'])) {
      throw new \Exception('CNPJ da empresa não informado');
    }

    if ($this->bootstrapCompanyAndToolsByCnpj($data['cnpj']) === false) {
      throw new \Exception('Empresa não encontrada');
    }

    $this->modo_emissao = isset($data['modoEmissao']) ? $data['modoEmissao'] : 1;
    $this->produtos = isset($data['produtos']) ? $data['produtos'] : [];

    $this->baseCalculo = floatval($this->data['total'] ?? 0) + floatval($this->data['total_acrescimo'] ?? 0) + floatval($this->data['total_frete'] ?? 0) - floatval($this->data['total_desconto'] ?? 0);

    if (isset($this->data['fiscal'])) {
      if (isset($this->data['fiscal']['aliquota_ibs_estadual'])) {
        $this->aliquotaIbsEstadual = floatval($this->data['fiscal']['aliquota_ibs_estadual']);
      }

      if (isset($this->data['fiscal']['aliquota_ibs_municipal'])) {
        $this->aliquotaIbsMunicipal = floatval($this->data['fiscal']['aliquota_ibs_municipal']);
      }

      if (isset($this->data['fiscal']['aliquota_cbs'])) {
        $this->aliquotaCbs = floatval($this->data['fiscal']['aliquota_cbs']);
      }
    }

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

    if ($this->conexaoSefaz() === false) {
      $this->modo_emissao = 9;
      array_push($this->warnings, "Não foi possível se conectar com a SEFAZ, a nota será emitida em modo de contingência");
    }

    $this->montaChave();
  }

  /**
   * Método abstrato para processamento dos impostos específicos de cada regime
   * Cada controller filho deve implementar sua própria lógica
   */
  abstract protected function processarImpostosProduto($produto, $index);

  /**
   * Cria a NFe chamando o método abstrato de impostos para cada produto
   */
  public function createNfe()
  {
    if (empty($this->data) || !isset($this->data['cnpj']) || !isset($this->data['cliente'])) {
      http_response_code(400);
      echo json_encode(['error' => 'Payload inválido para emissão de NFe']);
      return;
    }

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

        // Método abstrato - cada regime implementa sua própria lógica
        $this->processarImpostosProduto($produto, $index);

        $this->totalIcms += number_format($this->valorIcms, 2, ".", "");
      }

      $this->nfe->tagICMSTot($this->generateIcmsTot());
      $this->nfe->taginfAdic($this->generateIcmsInfo($this->data));
      $this->nfe->taginfRespTec($this->generateReponsavelTecnico());
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

  /**
   * Cancela uma NFe
   */
  public function cancelNfe($data)
  {
    try {
      if (!isset($data['cnpj']) || empty($data['cnpj'])) {
        http_response_code(400);
        echo json_encode([
          "error" => "CNPJ não informado"
        ]);
        return;
      }

      if (!isset($data['chave']) || empty($data['chave'])) {
        http_response_code(400);
        echo json_encode([
          "error" => "Chave da NFe não informada"
        ]);
        return;
      }

      if (!isset($data['justificativa']) || empty($data['justificativa'])) {
        http_response_code(400);
        echo json_encode([
          "error" => "Justificativa não informada"
        ]);
        return;
      }

      if (strlen($data['justificativa']) < 15) {
        http_response_code(400);
        echo json_encode([
          "error" => "Justificativa deve ter no mínimo 15 caracteres"
        ]);
        return;
      }

      if ($this->bootstrapCompanyAndToolsByCnpj($data['cnpj']) === false) {
        http_response_code(404);
        echo json_encode([
          "error" => "Empresa não encontrada"
        ]);
        return;
      }

      $emissoesModel = new EmissoesModel($data['chave']);
      $emissao = $emissoesModel->getCurrent();

      $response = $this->tools->sefazCancela($emissao->chave, $data['justificativa'], $emissao->protocolo);
      $stdCl = new Standardize();
      $std = $stdCl->toStd($response);

      if ($std->cStat == 128 || $std->cStat == 135) {
        $xmlProtocolado = Complements::toAuthorize($this->tools->lastRequest, $response);
        $std = new Standardize($xmlProtocolado);
        $obj = $std->toStd();
        $protocoloCancelamento = $obj->retEvento->infEvento->nProt;

        $danfe = new Danfe($emissao->xml);
        $danfe->setCancelFlag(true);
        $pdf = $danfe->render();
        $link = UtilsController::uploadPdf($pdf, $emissao->chave);

        $this->salvarEvento([
          "chave" => $emissao->chave,
          "tipo" => "CANCELAMENTO",
          "link" => $link,
          "xml" => $xmlProtocolado,
          "protocolo" => $protocoloCancelamento
        ]);

        http_response_code(200);
        echo json_encode([
          "chave" => $emissao->chave,
          "avisos" => [],
          "protocolo" => $protocoloCancelamento,
          "link" => $_ENV['URL_BASE'] . $link,
          "xml" => $emissao->xml,
          "pdf" => base64_encode($pdf)
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

  /**
   * Gera carta de correção
   */
  public function gerarCC($data)
  {
    if (!isset($data['cnpj']) || empty($data['cnpj'])) {
      http_response_code(400);
      echo json_encode([
        "error" => "CNPJ não informado"
      ]);
      return;
    }

    if ($this->bootstrapCompanyAndToolsByCnpj($data['cnpj']) === false) {
      http_response_code(404);
      echo json_encode([
        "error" => "Empresa não encontrada"
      ]);
      return;
    }

    $chaveNFe = $data['chave'];
    $correcao = $data['carta'];

    // Busca a emissão original
    $emissoesModel = new EmissoesModel($data['chave']);
    $emissao = $emissoesModel->getCurrent();

    if (!$emissao) {
      http_response_code(404);
      echo json_encode(['error' => 'NFe não encontrada']);
      return;
    }

    $grupoCorrecao = ($emissao->sequencia_cc ?? 0) + 1;

    // Envia a CC-e para a SEFAZ
    $response = $this->tools->sefazCCe($chaveNFe, $correcao, $grupoCorrecao);

    $stdCl = new Standardize();
    $std = $stdCl->toStd($response);

    if (!in_array($std->cStat, ['135', '136', '128'])) {
      http_response_code(400);
      echo json_encode([
        'error' => 'Erro ao processar CC-e',
        'codigo' => $std->cStat,
        'motivo' => $std->xMotivo
      ]);
      return;
    }

    $xmlEnviado = $this->tools->lastRequest;
    $xmlProtocolado = Complements::toAuthorize($xmlEnviado, $response);

    $emissoesModel->setSequencia_cc($grupoCorrecao);
    $emissoesModel->update();

    $protocolo = isset($std->retEvento->infEvento->nProt) ? $std->retEvento->infEvento->nProt : '';

    // Gera o PDF da Carta de Correção
    try {
      $dadosEmitente = [
        'CNPJ' => $this->company->getCnpj(),
        'razao' => $this->company->getRazao_social(),
        'logradouro' => $this->company->getLogradouro(),
        'numero' => $this->company->getNumero(),
        'bairro' => $this->company->getBairro(),
        'CEP' => $this->company->getCep(),
        'municipio' => $this->company->getCidade(),
        'UF' => $this->company->getUf(),
        'telefone' => $this->company->getTelefone(),
        'email' => $this->company->getEmail()
      ];

      $daccePdf = new Daevento($xmlProtocolado, $dadosEmitente);
      $pdfCCe = $daccePdf->render();

      $nomePdfCCe = "cce_{$chaveNFe}_seq{$grupoCorrecao}";
      $link = UtilsController::uploadPdf($pdfCCe, $nomePdfCCe);

      $this->salvarEvento([
        "chave" => $chaveNFe,
        "tipo" => "CC",
        "link" => $link,
        "xml" => $xmlProtocolado,
        "protocolo" => $protocolo
      ]);

      http_response_code(200);
      echo json_encode([
        'chave' => $chaveNFe,
        'avisos' => [],
        'protocolo' => $protocolo,
        'sequencia' => $grupoCorrecao,
        'link' => $_ENV['URL_BASE'] . $link,
        'xml' => $xmlProtocolado,
        'pdf' => base64_encode($pdfCCe)
      ]);
    } catch (\Exception $e) {
      // Se falhar ao gerar o PDF, retorna sem o PDF mas com sucesso no evento
      http_response_code(200);
      echo json_encode([
        'chave' => $chaveNFe,
        'avisos' => ['Erro ao gerar PDF: ' . $e->getMessage()],
        'protocolo' => $protocolo,
        'sequencia' => $grupoCorrecao,
        'link' => '',
        'xml' => $xmlProtocolado,
        'pdf' => ''
      ]);
    }
  }

  // ==================== MÉTODOS DE CONFIGURAÇÃO ====================

  protected function setConfig()
  {
    $config = [
      "atualizacao" => date('Y-m-d H:i:s'),
      "tpAmb"       => $this->ambiente,
      "razaosocial" => $this->company->getRazao_social(),
      "siglaUF"     => $this->company->getUf(),
      "cnpj"        => $this->company->getCnpj(),
      // "schemes"     => "PL_009_V4",
      "schemes"     => "PL_010_V1.30",
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

  // ==================== MÉTODOS DE GERAÇÃO DE DADOS ====================

  protected function generateIdeData($data)
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
    $std->indFinal = isset($data['consumidor_final']) && $data['consumidor_final'] === 'S' ? 1 : 0;

    if ($this->tipoCliente === 'PF') {
      $std->indFinal = 1;
    }

    $std->indPres = 1;
    $std->procEmi = 0;
    $std->verProc = 1;
    $std->dhCont = null;
    $std->xJust = null;

    return $std;
  }

  protected function generateDataCompany()
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

  protected function generateDataAddress()
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

  protected function generateClientData($data)
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
   */
  protected function resolveIndIEDest($cliente)
  {
    $result = [
      'indIEDest' => 9,
      'IE' => null
    ];

    if (!isset($cliente['inscricao_estadual']) || empty($cliente['inscricao_estadual'])) {
      return $result;
    }

    $ie = trim(strtoupper($cliente['inscricao_estadual']));
    $isIsentoTexto = in_array($ie, ['ISENTO', 'ISENTA', 'ISENT', 'IS', 'ISNT', '']);

    if (isset($cliente['tipo_icms'])) {
      $tipoIcms = strtoupper(trim($cliente['tipo_icms']));

      switch ($tipoIcms) {
        case '1':
        case 'CONTRIBUINTE':
          $ieNumerico = UtilsController::soNumero($ie);
          if (!empty($ieNumerico) && strlen($ieNumerico) >= 2) {
            $result['indIEDest'] = 1;
            $result['IE'] = $ieNumerico;
          } else {
            $result['indIEDest'] = 9;
          }
          break;

        case '2':
        case 'ISENTO':
        case 'ISENTA':
          $result['indIEDest'] = 9;
          break;

        case '9':
        case 'NAO_CONTRIBUINTE':
        case 'NAO CONTRIBUINTE':
        case 'NC':
        case 'RP':
        default:
          $result['indIEDest'] = 9;
          break;
      }
    } else {
      if ($isIsentoTexto) {
        $result['indIEDest'] = 9;
      } else {
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

  protected function generateClientAddressData($endereco)
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

  protected function generateProductData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->cProd = $produto['codigo'];
    $std->cEAN = $produto['ean'];
    $std->xProd = $produto['descricao'];
    $std->NCM = $produto['ncm'];
    $std->EXTIPI = '';
    $std->CFOP = $produto['cfop'];
    $std->uCom = $produto['unidade'];
    $std->qCom = $produto['quantidade'];
    $std->vUnCom = number_format($produto['valor'], 2, ".", "");
    $std->vProd = $produto['total'];
    $std->cEANTrib = $produto['ean'];
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

  protected function generateProdutoInfoAdicional($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->infAdProd = $produto['informacoes_adicionais'];

    return $std;
  }

  protected function generateImpostoData($produto, $item)
  {
    $std = new stdClass();
    $std->item = $item;
    $std->vTotTrib = $produto['total'] * (0 / 100);

    return $std;
  }

  protected function addCombustivelTag($produto, $item)
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

  protected function addICMSCombTag($produto, $item)
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

  // ==================== MÉTODOS DE TOTALIZAÇÃO ====================

  protected function generateIcmsTot()
  {
    $std = new stdClass();
    $std->vBC = number_format($this->baseTotalIcms, 2, ".", "");
    $std->vICMS = number_format($this->totalIcms, 2, ".", "");
    $std->vICMSDeson = 0.00;
    $std->vFCP = 0.00;
    $std->vBCST = 0.00;
    $std->vST = 0.00;
    $std->vFCPST = 0.00;
    $std->vFCPSTRet = 0.00;
    $std->vProd = number_format($this->total_produtos, 2, ".", "");
    $std->vFrete = 0.00;
    $std->vSeg = 0.00;
    $std->vDesc = 0.00;
    $std->vII = 0.00;
    $std->vIPI = 0.00;
    $std->vIPIDevol = 0.00;
    $std->vPIS = 0.00;
    $std->vCOFINS = 0.00;
    $std->vOutro = 0.00;
    $std->vNF = number_format($this->total_produtos, 2, ".", "");
    $std->vTotTrib = 0.00;

    return $std;
  }

  protected function generateIcmsInfo($data)
  {
    $std = new stdClass();
    $std->infAdFisco = '';
    $std->infCpl = isset($data['observacao']) ? $data['observacao'] : '';

    return $std;
  }

  // ==================== MÉTODOS DE TRANSPORTE E PAGAMENTO ====================

  protected function generateFreteData()
  {
    $std = new stdClass();
    $std->modFrete = 9;

    return $std;
  }

  protected function generateFaturaData()
  {
    $std = new stdClass();
    $std->vTroco = isset($this->data['troco']) ? $this->data['troco'] : 0;

    return $std;
  }

  protected function generatePagamentoData($pagamento)
  {
    $std = new stdClass();
    $std->indPag = isset($pagamento['indPag']) ? $pagamento['indPag'] : 0;
    $std->tPag = str_pad($pagamento['tPag'], 2, '0', STR_PAD_LEFT);
    $std->vPag = number_format($pagamento['valorpago'], 2, ".", "");

    if (in_array($std->tPag, ['03', '04', '17', '3', '4', '17', 3, 4, 17])) {
      $std->tpIntegra = 2;
      $std->CNPJPag = "00000000000191";
      $std->tBand = "99";
      $std->cAut = "000000";
    }

    return $std;
  }

  protected function generateReponsavelTecnico()
  {
    $std = new stdClass();
    $std->CNPJ = "45730598000102";
    $std->xContato = "Logic Tecnologia e Inovação";
    $std->email = "contato.logictec@gmail.com";
    $std->fone = "92991225648";
    $std->idCSRT = "01";

    return $std;
  }

  protected function generateAutXMLData($data)
  {
    $std = new stdClass();
    $std->CNPJ = isset($data['cnpj_consulta']) ? UtilsController::soNumero($data['cnpj_consulta']) : '13937073000156';
    return $std;
  }

  // ==================== MÉTODOS AUXILIARES ====================

  protected function montaChave()
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

  protected function atualizaNumero()
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

  protected function conexaoSefaz()
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

  // ==================== MÉTODOS DE PROCESSAMENTO DE RETORNO ====================

  protected function analisaRetorno($std)
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

  protected function processarLote($std)
  {
    $recibo = $std->infRec->nRec;
    $this->response = $this->tools->sefazConsultaRecibo($recibo);

    $stdCl = new Standardize();
    $std = $stdCl->toStd($this->response);

    $this->analisaRetorno($std);
  }

  protected function loteProcessado($std)
  {
    foreach ($std->protNFe as $prot) {
      $this->analisaRetorno($prot);
    }
  }

  protected function processarEmissao($std)
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

  protected function salvaEmissao()
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

  // ==================== GETTERS E SETTERS ====================

  public function getStatus()
  {
    return $this->status;
  }

  public function setStatus($status)
  {
    $this->status = $status;
    return $this;
  }

  public function getCurrentData()
  {
    return $this->currentData;
  }

  public function setCurrentData($currentData)
  {
    $this->currentData = $currentData;
    return $this;
  }

  public function getCompany()
  {
    return $this->company;
  }

  public function getTools()
  {
    return $this->tools;
  }

  private function salvarEvento($data) {
    $emissoesEventosModel = new EmissoesEventosModel();
    $emissoesEventosModel->setChave($data['chave']);
    $emissoesEventosModel->setTipo($data['tipo']);
    $emissoesEventosModel->setLink($data['link']);
    $emissoesEventosModel->setXml($data['xml']);
    $emissoesEventosModel->setProtocolo($data['protocolo']);
    $emissoesEventosModel->create();
  }
}
