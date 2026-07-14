<?php

namespace App\Controllers;

use App\Controllers\Fiscal\Concerns\HandlesSefazAuthorizationResponse;
use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Models\CompanyModel;
use App\Models\EmissoesModel;
use App\Models\EmissoesEventosModel;
use App\Models\FormaPagamentoModel;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Danfce;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use stdClass;

class CupomFiscalController
{
  use HandlesSefazAuthorizationResponse;

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
  private $totalFrete = 0;
  private $totalDesconto = 0;
  private $totalOutrasDespesas = 0;
  private $receiptPollAttempts = 0;
  private $receiptNumber;
  private const MAX_RECEIPT_POLLS = 5;

  public function __construct($data = null)
  {
    if ($data) {
      if (empty($data['cnpj'])) {
        throw new HttpException('CNPJ da empresa não informado.', 422);
      }

      $this->nfe = new Make();
      $this->data = $data;

      if ($data['cnpj']) {
        $companyModel = new CompanyModel();
        $company = $companyModel->find([
          "cnpj" => UtilsController::soNumero($data['cnpj'])
        ]);

        if (!$company) {
          throw new HttpException('Empresa não encontrada.', 404);
        }

        $this->company = new CompanyModel($company[0]['id']);
        $this->ambiente = max(1, intval($this->company->getTpamb()));
        $isProduction = $this->ambiente === 1;
        $this->serie = $isProduction ? $this->company->getSerie_nfce() : $this->company->getSerie_nfce_homologacao();
        $this->numero = $isProduction ? $this->company->getNumero_nfce() : $this->company->getNumero_nfce_homologacao();
        $this->csc = $isProduction ? $this->company->getCsc() : $this->company->getCsc_homologacao();
        $this->csc_id = $isProduction ? $this->company->getCsc_id() : $this->company->getCsc_id_homologacao();
        $this->certificado = UtilsController::getCertificado($this->company->getCertificado());
        $this->config = $this->setConfig();
        $this->dataEmissao = date('Y-m-d\TH:i:sP');
        $this->modo_emissao = isset($data['modoEmissao']) ? $data['modoEmissao'] : 1;

        $this->produtos = isset($data['produtos']) ? $data['produtos'] : [];

        if (isset($data['pagamentos'])) {
          $this->pagamentos = array_map(
            function ($pagamento) {
              if (!isset($pagamento['codigo'], $pagamento['valorpago'])) {
                throw new \InvalidArgumentException('Pagamento exige código e valor pago.');
              }
              try {
                $formaPagamentoModel = new FormaPagamentoModel($pagamento['codigo']);
              } catch (\RuntimeException $exception) {
                if ($exception->getMessage() === 'Forma de pagamento não encontrada.') {
                  throw new \InvalidArgumentException($exception->getMessage());
                }
                throw $exception;
              }

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

        $this->tools = new Tools(
          json_encode($this->config),
          UtilsController::readPfxForNFePHP($this->certificado, $this->company->getSenha())
        );
        $this->tools->model($this->mod);

        $this->montaChave();
      }
    }
  }

  public function createNfe()
  {
    $stage = 'montagem das tags';

    try {
      $this->validateEmissionData();
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
        } elseif (in_array((int) $this->company->getCrt(), [1, 4], true)) {
          $this->nfe->tagICMSSN($this->generateIcmssnData($produto, $index + 1));
        } else {
          $this->nfe->tagICMS($this->generateICMSData($produto, $index + 1));
        }

        $this->nfe->tagPIS($this->generatePisData($produto, $index + 1));
        $this->nfe->tagCOFINS($this->generateCofinsData($produto, $index + 1));
        $this->nfe->tagimposto($this->generateImpostoData($produto, $index + 1));

        $this->totalIcms += number_format($this->valorIcms, 2, ".", "");
      }

      $this->nfe->tagICMSTot($this->generateIcmsTot());
      $this->nfe->taginfAdic($this->generateIcmsInfo());
      $this->nfe->taginfRespTec($this->generateResponsavelTecnico());
      $this->nfe->tagtransp($this->generateFreteData());
      $this->nfe->tagpag($this->generateFaturaData());

      if (empty($this->pagamentos)) {
        throw new \Exception("Nenhuma forma de pagamento informada para emissão da NFC-e");
      }

      foreach ($this->pagamentos as $pagamento) {
        $this->nfe->tagdetPag($this->generatePagamentoData($pagamento));
      }

      $stage = 'geração do XML';
      $this->currentXML = $this->nfe->getXML();

      $xmlErrors = $this->nfeErrors();
      if ($this->currentXML === '' || $xmlErrors !== []) {
        throw new \InvalidArgumentException('O XML da NFC-e não pôde ser gerado com os dados informados.');
      }

      $stage = 'assinatura digital';
      $this->currentXML = $this->tools->signNFe($this->currentXML);

      $stage = 'comunicação com a SEFAZ';
      $this->response = $this->tools->sefazEnviaLote([$this->currentXML], str_pad(1, 15, '0', STR_PAD_LEFT), 1);

      $stage = 'leitura da resposta da SEFAZ';
      $stdCl = new Standardize();
      $std = $stdCl->toStd($this->response);
      $this->setCurrentData($std);

      $stage = 'processamento da resposta da SEFAZ';
      $this->analisaRetorno($std);
    } catch (\Throwable $e) {
      $this->logEmissionException($e, $stage);
      $isPayloadError = $e instanceof \InvalidArgumentException
        && in_array($stage, ['montagem das tags', 'geração do XML'], true);
      $isSefazError = in_array($stage, [
        'comunicação com a SEFAZ',
        'leitura da resposta da SEFAZ',
        'processamento da resposta da SEFAZ',
      ], true);

      $status = $isPayloadError ? 422 : ($isSefazError ? 502 : 500);
      $message = $isPayloadError
        ? 'Não foi possível montar a NFC-e com os dados informados.'
        : ($isSefazError
          ? 'Não foi possível concluir a comunicação com a SEFAZ.'
          : 'Não foi possível emitir a NFC-e.');

      JsonResponse::error($message, $status, [
        'error_tags' => $this->emissionErrorTags($e, $stage),
        'etapa' => $stage,
      ]);
    }
  }

  private function nfeErrors(): array
  {
    if (!is_object($this->nfe) || !method_exists($this->nfe, 'getErrors')) {
      return [];
    }

    return array_values(array_unique(array_filter(
      array_map(static fn($error) => trim((string) $error), $this->nfe->getErrors()),
      static fn($error) => $error !== ''
    )));
  }

  private function emissionErrorTags(\Throwable $exception, string $stage): array
  {
    $errors = $this->nfeErrors();
    $publicMessage = $this->publicEmissionExceptionMessage($exception, $stage);

    if ($publicMessage !== '') {
      $errors[] = $publicMessage;
    }

    if ($errors === []) {
      $errors[] = "Falha na etapa: {$stage}.";
    }

    return array_values(array_unique($errors));
  }

  private function publicEmissionExceptionMessage(\Throwable $exception, string $stage): string
  {
    $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');

    if (preg_match('/certificate unknown|unknown ca|alert bad certificate/i', $message)) {
      return 'A SEFAZ recusou o certificado digital apresentado. Verifique a validade, a titularidade e a cadeia de certificação do PFX.';
    }

    if ($stage === 'assinatura digital') {
      return 'Não foi possível assinar o XML. Verifique o arquivo, a senha e a validade do certificado digital da empresa.';
    }

    if (preg_match('/could not load PEM client certificate|bad end line/i', $message)) {
      return 'O PFX foi lido, mas o certificado PEM temporário usado na conexão com a SEFAZ ficou malformado.';
    }

    if (preg_match('/certific|certificate|private key|openssl|pfx|pkcs/i', $message)) {
      return 'Não foi possível usar o certificado digital da empresa. Verifique o arquivo, a senha e a validade.';
    }

    if (preg_match('/timed? out|timeout|could not resolve|connection refused|failed to connect|curl|soap|webservice/i', $message)) {
      return 'Não foi possível conectar ao serviço da SEFAZ. Tente novamente e verifique a disponibilidade do autorizador.';
    }

    if (preg_match('/SQLSTATE|PDO|password|senha|secret|BEGIN [A-Z ]*PRIVATE KEY|Stack trace/i', $message)) {
      return "Falha interna na etapa: {$stage}.";
    }

    if ($message !== '' && !str_contains($message, '<NFe') && mb_strlen($message) <= 500) {
      return $message;
    }

    return "Falha na etapa: {$stage}.";
  }

  private function logEmissionException(\Throwable $exception, string $stage): void
  {
    error_log(sprintf(
      '[NFC-e][%s] %s: %s em %s:%d',
      $stage,
      get_class($exception),
      $exception->getMessage(),
      $exception->getFile(),
      $exception->getLine()
    ));
  }

  private function validateEmissionData(): void
  {
    foreach (['cnpj', 'cfop', 'produtos', 'pagamentos'] as $field) {
      if (empty($this->data[$field])) {
        throw new \InvalidArgumentException("Campo obrigatório para NFC-e: {$field}");
      }
    }

    foreach ($this->data['produtos'] as $index => $product) {
      foreach (['codigo', 'ean', 'descricao', 'ncm', 'cfop', 'unidade', 'quantidade', 'valor', 'total', 'origem'] as $field) {
        if (!array_key_exists($field, $product)) {
          throw new \InvalidArgumentException("Campo obrigatório do produto {$index}: {$field}");
        }
      }
    }

    foreach ($this->data['pagamentos'] as $index => $payment) {
      if (!isset($payment['codigo'], $payment['valorpago'])) {
        throw new \InvalidArgumentException("Pagamento {$index} exige código e valor pago.");
      }
    }
  }

  public function cancelNfce($data)
  {
    try {
      foreach (['cnpj', 'chave', 'justificativa'] as $field) {
        if (empty($data[$field])) {
          throw new \InvalidArgumentException("Campo obrigatório: {$field}");
        }
      }
      if (mb_strlen(trim($data['justificativa'])) < 15) {
        throw new \InvalidArgumentException('Justificativa deve ter no mínimo 15 caracteres.');
      }

      $companyModel = new CompanyModel();
      $company = $companyModel->find([
        "cnpj" => UtilsController::soNumero($data['cnpj'])
      ]);

      if (!$company) {
        JsonResponse::error('Empresa não encontrada.', 404);
        return;
      }

      $this->company = new CompanyModel($company[0]['id']);
      $this->certificado = UtilsController::getCertificado($this->company->getCertificado());
      $this->config = $this->setConfig();

      $emissoesModel = new EmissoesModel($data['chave']);
      $emissao = $emissoesModel->getCurrent();
      if ($emissao->tipo !== 'NFCE') {
        throw new \InvalidArgumentException('A chave informada não pertence a uma NFC-e.');
      }

      $this->tools = new Tools(
        json_encode($this->config),
        UtilsController::readPfxForNFePHP($this->certificado, $this->company->getSenha())
      );
      $this->tools->model($this->mod);

      $response = $this->tools->sefazCancela($emissao->chave, $data['justificativa'], $emissao->protocolo);
      $stdCl = new Standardize();
      $std = $stdCl->toStd($response);

      if (in_array((int) $std->cStat, [128, 135], true)) {
        $xmlProtocolado = Complements::toAuthorize($this->tools->lastRequest, $response);
        $eventProtocol = $std->retEvento->infEvento->nProt ?? '';

        $event = new EmissoesEventosModel();
        $event->setChave($emissao->chave);
        $event->setTipo('CANCELAMENTO');
        $event->setProtocolo($eventProtocol);
        $event->setXml($xmlProtocolado);
        $event->setLink('');
        $event->create();

        JsonResponse::send([
          "status" => "success",
          "message" => "Cancelamento homologado com sucesso!",
          "protocolo" => $eventProtocol,
          "xml" => $xmlProtocolado,
        ]);
      } else {
        JsonResponse::send([
          "status" => "error",
          "message" => "Erro ao cancelar: " . $std->xMotivo
        ], 422);
      }
    } catch (\InvalidArgumentException $e) {
      JsonResponse::error($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
      if ($e->getMessage() === 'Emissão não encontrada.') {
        JsonResponse::error($e->getMessage(), 404);
        return;
      }
      error_log($e->getMessage());
      JsonResponse::error('Erro interno ao cancelar a NFC-e.', 500);
    } catch (\Throwable $e) {
      error_log($e->getMessage());
      JsonResponse::error('Erro interno ao cancelar a NFC-e.', 500);
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
      $this->totalFrete += floatval($produto['frete']);
    }

    if (isset($produto['desconto']) && $produto['desconto'] > 0) {
      $std->vDesc = number_format($produto['desconto'], 2, ".", "");
      $this->totalDesconto += floatval($produto['desconto']);
    }

    if (isset($produto['acrescimo']) && $produto['acrescimo'] > 0) {
      $std->vOutro = number_format($produto['acrescimo'], 2, ".", "");
      $this->totalOutrasDespesas += floatval($produto['acrescimo']);
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
    $std->pGLP = $produto['gpl_percentual'] ?? 0;
    $std->pGNn = $produto['gas_percentual_nacional'] ?? 0;
    $std->vPart = $produto['valor_partida'] ?? 0;
    $std->UFCons = $this->company->getUf();

    return $std;
  }

  private function addICMSCombTag($produto, $item)
  {
    $icms = $produto['icms_combustivel'] ?? null;
    if (!is_array($icms) || empty($icms['CST'])) {
      throw new \InvalidArgumentException(
        "Produto de combustível {$item} exige o bloco icms_combustivel com CST e valores fiscais."
      );
    }

    $std = new \stdClass();
    $std->item = $item + 1;
    $std->orig = (string) ($icms['orig'] ?? $produto['origem'] ?? 0);
    $std->CST = (string) $icms['CST'];

    foreach ([
      'modBC', 'vBC', 'vBCICMS', 'pICMS', 'vICMS', 'vBCICMSST', 'pICMSST',
      'vICMSST', 'vBCFCP', 'pFCP', 'vFCP', 'vBCFCPST', 'pFCPST', 'vFCPST',
      'qBCMono', 'adRemICMS', 'vICMSMono', 'qBCMonoRet', 'adRemICMSRet',
      'vICMSMonoRet', 'qBCMonoDif', 'adRemICMSDif', 'vICMSMonoDif',
    ] as $field) {
      if (array_key_exists($field, $icms)) {
        $std->{$field} = $icms[$field];
      }
    }

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

    return $std;
  }

  private function generateICMSData($produto, $item)
  {
    $icms = $produto['icms'] ?? [];
    $std = new stdClass();
    $std->item = $item;
    $std->orig = $produto['origem'] ?? 0;
    $std->CST = (string) ($icms['cst'] ?? $produto['cst_icms'] ?? '40');

    if (isset($icms['aliquota_icms'])) {
      $rate = floatval($icms['aliquota_icms']);
      $value = $this->baseCalculo * ($rate / 100);
      $std->modBC = $icms['mod_bc'] ?? 0;
      $std->vBC = number_format($this->baseCalculo, 2, '.', '');
      $std->pICMS = number_format($rate, 4, '.', '');
      $std->vICMS = number_format($value, 2, '.', '');
      $this->valorIcms += $value;
    }

    return $std;
  }

  private function generatePisData($produto, $item)
  {
    $rate = floatval($produto['aliquota_pis'] ?? 0);
    $std = new stdClass();
    $std->item = $item;
    $std->CST = (string) ($produto['pis_cst'] ?? $produto['cst_pis'] ?? '07');
    $std->vBC = number_format($this->baseCalculo, 2, '.', '');
    $std->pPIS = number_format($rate, 4, '.', '');
    $std->vPIS = number_format($this->baseCalculo * ($rate / 100), 2, '.', '');

    return $std;
  }

  private function generateCofinsData($produto, $item)
  {
    $rate = floatval($produto['aliquota_cofins'] ?? 0);
    $std = new stdClass();
    $std->item = $item;
    $std->CST = (string) ($produto['cofins_cst'] ?? $produto['cst_cofins'] ?? '07');
    $std->vBC = number_format($this->baseCalculo, 2, '.', '');
    $std->pCOFINS = number_format($rate, 4, '.', '');
    $std->vCOFINS = number_format($this->baseCalculo * ($rate / 100), 2, '.', '');

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
    $std->vFrete = number_format($this->totalFrete, 2, '.', '');
    $std->vSeg = 0.00;
    $std->vDesc = number_format($this->totalDesconto, 2, '.', '');
    $std->vII = 0.00;
    $std->vIPI = 0.00;
    $std->vIPIDevol = 0.00;
    $std->vPIS = 0.00;
    $std->vCOFINS = 0.00;
    $std->vOutro = number_format($this->totalOutrasDespesas, 2, '.', '');
    $std->vNF = number_format(
      $this->total_produtos - $this->totalDesconto + $this->totalFrete + $this->totalOutrasDespesas,
      2,
      '.',
      ''
    );
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
    $std->tPag      = str_pad($pagamento['tPag'], 2, '0', STR_PAD_LEFT);
    $std->vPag      = number_format($pagamento['valorpago'], 2, ".", "");

    if (in_array($std->tPag, ['03', '04', '17', '3', '4', '17', 3, 4, 17])) {
      $std->tpIntegra   = 2;
      $std->CNPJPag        = "00000000000191";
      $std->tBand       = "99";
      $std->cAut        = "000000";
    }

    return $std;
  }

  public function generateResponsavelTecnico()
  {
    return UtilsController::technicalResponsible();
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
    if ((int) $this->ambiente === 1) {
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

    $danfe = new Danfce($this->currentXML);
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

    JsonResponse::send([
      "chave" => $this->currentChave,
      "avisos" => $this->warnings,
      "protocolo" => $this->numeroProtocolo,
      "link" => UtilsController::publicUrl($link),
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
