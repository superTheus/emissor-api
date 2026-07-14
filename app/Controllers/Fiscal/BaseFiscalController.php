<?php

namespace App\Controllers\Fiscal;

use App\Controllers\UtilsController;
use App\Controllers\Fiscal\Concerns\HandlesSefazAuthorizationResponse;
use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Models\CompanyModel;
use App\Models\EmissoesEventosModel;
use App\Models\EmissoesModel;
use App\Models\FormaPagamentoModel;
use NFePHP\Common\Keys;
use NFePHP\DA\NFe\Daevento;
use NFePHP\DA\NFe\Danfe;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use stdClass;

abstract class BaseFiscalController
{
  use HandlesSefazAuthorizationResponse;

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
  protected $totalIBS = 0.00;
  protected $totalIBSUF = 0.00;
  protected $totalIBSMun = 0.00;
  protected $totalCBS = 0.00;
  protected $baseIBS = 0.00;
  protected $totalPIS = 0.00;
  protected $totalCOFINS = 0.00;
  protected $totalIPI = 0.00;
  protected $totalImposto = 0.00;
  protected $totalImpostoProduto = 0.00;
  protected $totalFrete = 0.00;
  protected $modalidadeFrete = 9;
  protected $totalOutrasDespesas = 0;
  protected $totalDesconto = 0;
  protected $receiptPollAttempts = 0;
  protected $receiptNumber;
  protected const MAX_RECEIPT_POLLS = 5;

  public function __construct($data = null)
  {
    if ($data) {
      $this->data = $data;

      try {
        $this->validateEmissionData($data);
        $this->initializeFromData($data);
      } catch (HttpException $exception) {
        throw $exception;
      } catch (\InvalidArgumentException $exception) {
        throw new HttpException(
          'Dados inválidos para emissão da NF-e.',
          422,
          ['error_tags' => [$exception->getMessage()], 'etapa' => 'validação']
        );
      } catch (\Throwable $exception) {
        $this->logEmissionException($exception, 'inicialização');
        throw new HttpException(
          'Não foi possível preparar a emissão da NF-e.',
          500,
          [
            'error_tags' => $this->emissionErrorTags($exception, 'inicialização'),
            'etapa' => 'inicialização',
          ]
        );
      }
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
    $this->ambiente = max(1, (int) $this->company->getTpamb());
    $isProduction = $this->ambiente === 1;
    $this->serie = $isProduction ? $this->company->getSerie_nfe() : $this->company->getSerie_nfe_homologacao();
    $this->numero = $isProduction ? $this->company->getNumero_nfe() : $this->company->getNumero_nfe_homologacao();
    $this->csc = $isProduction ? $this->company->getCsc() : $this->company->getCsc_homologacao();
    $this->csc_id = $isProduction ? $this->company->getCsc_id() : $this->company->getCsc_id_homologacao();
    $this->certificado = UtilsController::getCertificado($this->company->getCertificado());
    $this->config = $this->setConfig();

    // Para emissão, dataEmissao é relevante; para cancelamento/CCe, não atrapalha.
    if (empty($this->dataEmissao)) {
      // Usa a data/hora atual do servidor com timezone configurado
      $this->dataEmissao = (new \DateTime('now'))->format('Y-m-d\TH:i:sP');
    }

    $this->tools = new Tools(
      json_encode($this->config),
      UtilsController::readPfxForNFePHP($this->certificado, $this->company->getSenha())
    );
    $this->tools->model($this->mod);

    return true;
  }

  /**
   * Inicializa o controller com os dados recebidos
   */
  protected function initializeFromData($data)
  {
    $data['cliente']['tipo_documento'] = strtoupper(trim((string) $data['cliente']['tipo_documento']));
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
      throw new HttpException('Empresa não encontrada.', 404);
    }

    $this->validateEmissionContext($data);

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

    if ($this->conexaoSefaz() === false) {
      // tpEmis=9 é contingência offline exclusiva da NFC-e (modelo 65).
      // Para NF-e modelo 55, mantemos o modo solicitado.
      array_push(
        $this->warnings,
        'A consulta de status da SEFAZ falhou; a autorização será tentada no modo de emissão informado.'
      );
    }

    $this->montaChave();
  }

  protected function validateEmissionData(array $data): void
  {
    $errors = [];

    foreach (['cnpj', 'cfop', 'cliente', 'produtos', 'total', 'pagamentos'] as $field) {
      if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === []) {
        $errors[] = "{$field}: campo obrigatório.";
      }
    }

    $cnpj = UtilsController::soNumero((string) ($data['cnpj'] ?? ''));
    if ($cnpj !== '' && !preg_match('/^\d{14}$/', $cnpj)) {
      $errors[] = 'cnpj: informe os 14 dígitos do CNPJ do emitente.';
    }

    if (isset($data['cfop']) && !preg_match('/^[1-7]\d{3}$/', (string) $data['cfop'])) {
      $errors[] = 'cfop: informe um CFOP válido com 4 dígitos.';
    }

    if (isset($data['modoEmissao']) && !in_array((int) $data['modoEmissao'], [1, 2, 4, 5, 6, 7], true)) {
      $errors[] = 'modoEmissao: valor inválido para NF-e modelo 55. Use 1, 2, 4, 5, 6 ou 7.';
    }

    if (isset($data['modalidade_frete']) && !in_array((int) $data['modalidade_frete'], [0, 1, 2, 3, 4, 9], true)) {
      $errors[] = 'modalidade_frete: use 0, 1, 2, 3, 4 ou 9.';
    }

    $cliente = $data['cliente'] ?? null;
    if (!is_array($cliente)) {
      if (array_key_exists('cliente', $data)) {
        $errors[] = 'cliente: deve ser um objeto.';
      }
    } else {
      foreach (['nome', 'tipo_documento', 'documento', 'endereco'] as $field) {
        if (!array_key_exists($field, $cliente) || $cliente[$field] === '' || $cliente[$field] === []) {
          $errors[] = "cliente.{$field}: campo obrigatório.";
        }
      }

      $tipoDocumento = strtoupper(trim((string) ($cliente['tipo_documento'] ?? '')));
      if ($tipoDocumento !== '' && !in_array($tipoDocumento, ['CPF', 'CNPJ'], true)) {
        $errors[] = 'cliente.tipo_documento: use CPF ou CNPJ.';
      }

      $documento = UtilsController::soNumero((string) ($cliente['documento'] ?? ''));
      $documentLength = $tipoDocumento === 'CPF' ? 11 : ($tipoDocumento === 'CNPJ' ? 14 : null);
      if ($documentLength !== null && !preg_match('/^\d{' . $documentLength . '}$/', $documento)) {
        $errors[] = "cliente.documento: deve conter {$documentLength} dígitos para {$tipoDocumento}.";
      }

      $endereco = $cliente['endereco'] ?? null;
      if (!is_array($endereco)) {
        if (array_key_exists('endereco', $cliente)) {
          $errors[] = 'cliente.endereco: deve ser um objeto.';
        }
      } else {
        foreach (['logradouro', 'numero', 'bairro', 'codigo_municipio', 'municipio', 'uf', 'cep'] as $field) {
          if (!array_key_exists($field, $endereco) || trim((string) $endereco[$field]) === '') {
            $errors[] = "cliente.endereco.{$field}: campo obrigatório.";
          }
        }

        if (isset($endereco['uf']) && !preg_match('/^[A-Za-z]{2}$/', (string) $endereco['uf'])) {
          $errors[] = 'cliente.endereco.uf: informe a sigla da UF com 2 letras.';
        }
        if (isset($endereco['codigo_municipio']) && !preg_match('/^\d{7}$/', (string) $endereco['codigo_municipio'])) {
          $errors[] = 'cliente.endereco.codigo_municipio: informe o código IBGE com 7 dígitos.';
        }
        if (isset($endereco['cep']) && !preg_match('/^\d{8}$/', UtilsController::soNumero((string) $endereco['cep']))) {
          $errors[] = 'cliente.endereco.cep: informe um CEP com 8 dígitos.';
        }
      }
    }

    $productsTotal = 0.0;
    $products = $data['produtos'] ?? null;
    if (!is_array($products)) {
      if (array_key_exists('produtos', $data)) {
        $errors[] = 'produtos: deve ser uma lista.';
      }
    } else {
      foreach ($products as $index => $product) {
        $path = "produtos[{$index}]";
        if (!is_array($product)) {
          $errors[] = "{$path}: deve ser um objeto.";
          continue;
        }

        foreach (['codigo', 'ean', 'descricao', 'ncm', 'cfop', 'unidade', 'quantidade', 'valor', 'total', 'origem'] as $field) {
          if (!array_key_exists($field, $product) || $product[$field] === '') {
            $errors[] = "{$path}.{$field}: campo obrigatório.";
          }
        }

        if (isset($product['ncm']) && !preg_match('/^\d{8}$/', UtilsController::soNumero((string) $product['ncm']))) {
          $errors[] = "{$path}.ncm: informe um NCM com 8 dígitos.";
        }
        if (isset($product['cfop']) && !preg_match('/^[1-7]\d{3}$/', (string) $product['cfop'])) {
          $errors[] = "{$path}.cfop: informe um CFOP válido com 4 dígitos.";
        }
        if (isset($product['quantidade']) && (!is_numeric($product['quantidade']) || (float) $product['quantidade'] <= 0)) {
          $errors[] = "{$path}.quantidade: deve ser maior que zero.";
        }
        foreach (['valor', 'total', 'desconto', 'frete', 'outras_despesas'] as $field) {
          if (isset($product[$field]) && (!is_numeric($product[$field]) || (float) $product[$field] < 0)) {
            $errors[] = "{$path}.{$field}: deve ser um número maior ou igual a zero.";
          }
        }

        if (is_numeric($product['quantidade'] ?? null) && is_numeric($product['valor'] ?? null) && is_numeric($product['total'] ?? null)) {
          $expected = round((float) $product['quantidade'] * (float) $product['valor'], 2);
          if (abs($expected - (float) $product['total']) > 0.01) {
            $errors[] = sprintf(
              '%s.total: esperado %.2f (quantidade × valor), recebido %.2f.',
              $path,
              $expected,
              (float) $product['total']
            );
          }
          $productsTotal += (float) $product['total'];
        }
      }
    }

    if (isset($data['total']) && !is_numeric($data['total'])) {
      $errors[] = 'total: deve ser numérico.';
    } elseif (is_numeric($data['total'] ?? null) && $productsTotal > 0 && abs($productsTotal - (float) $data['total']) > 0.01) {
      $errors[] = sprintf('total: a soma dos produtos é %.2f, mas foi informado %.2f.', $productsTotal, (float) $data['total']);
    }

    $paymentsTotal = 0.0;
    $payments = $data['pagamentos'] ?? null;
    if (!is_array($payments)) {
      if (array_key_exists('pagamentos', $data)) {
        $errors[] = 'pagamentos: deve ser uma lista.';
      }
    } else {
      foreach ($payments as $index => $payment) {
        $path = "pagamentos[{$index}]";
        if (!is_array($payment)) {
          $errors[] = "{$path}: deve ser um objeto.";
          continue;
        }
        if (!isset($payment['codigo'])) {
          $errors[] = "{$path}.codigo: campo obrigatório.";
        }
        if (!isset($payment['valorpago'])) {
          $errors[] = "{$path}.valorpago: campo obrigatório.";
        } elseif (!is_numeric($payment['valorpago']) || (float) $payment['valorpago'] < 0) {
          $errors[] = "{$path}.valorpago: deve ser um número maior ou igual a zero.";
        } else {
          $paymentsTotal += (float) $payment['valorpago'];
        }
      }
    }

    if (isset($data['total_pago']) && is_numeric($data['total_pago']) && abs($paymentsTotal - (float) $data['total_pago']) > 0.01) {
      $errors[] = sprintf(
        'total_pago: a soma dos pagamentos é %.2f, mas foi informado %.2f.',
        $paymentsTotal,
        (float) $data['total_pago']
      );
    }

    if ($errors !== []) {
      throw new HttpException(
        'Dados inválidos para emissão da NF-e.',
        422,
        ['error_tags' => array_values(array_unique($errors)), 'etapa' => 'validação']
      );
    }
  }

  protected function validateEmissionContext(array $data): void
  {
    $errors = [];
    $issuerUf = strtoupper((string) $this->company->getUf());
    $recipientUf = strtoupper((string) ($data['cliente']['endereco']['uf'] ?? ''));
    $cfops = [['path' => 'cfop', 'value' => $data['cfop'] ?? null]];

    foreach ($data['produtos'] as $index => $product) {
      $cfops[] = [
        'path' => "produtos[{$index}].cfop",
        'value' => is_array($product) ? ($product['cfop'] ?? null) : null,
      ];
    }

    foreach ($cfops as $cfopData) {
      $cfop = (string) $cfopData['value'];
      $firstDigit = substr($cfop, 0, 1);
      if ($issuerUf !== '' && $recipientUf !== '' && $issuerUf !== $recipientUf && $firstDigit === '5') {
        $errors[] = sprintf(
          '%s: CFOP %s é de operação interna, mas o emitente é de %s e o destinatário é de %s. Use um CFOP iniciado por 6.',
          $cfopData['path'],
          $cfop,
          $issuerUf,
          $recipientUf
        );
      } elseif ($issuerUf === $recipientUf && $firstDigit === '6') {
        $errors[] = sprintf(
          '%s: CFOP %s é interestadual, mas emitente e destinatário são de %s. Use um CFOP iniciado por 5.',
          $cfopData['path'],
          $cfop,
          $issuerUf
        );
      }
    }

    $crt = (int) $this->company->getCrt();
    foreach ($data['produtos'] as $index => $product) {
      if (!is_array($product)) {
        continue;
      }

      if ($crt === 1 && array_key_exists('cst_icms', $product) && !UtilsController::validaCSOSN($product['cst_icms'])) {
        $errors[] = sprintf(
          'produtos[%d].cst_icms: CSOSN %s inválido para CRT 1. Use 101, 102, 103, 201, 202, 203, 300, 400, 500 ou 900.',
          $index,
          (string) $product['cst_icms']
        );
      }

      if ($crt === 4 && array_key_exists('csosn', $product) && !in_array((string) $product['csosn'], ['102', '103', '300', '400', '500'], true)) {
        $errors[] = sprintf(
          'produtos[%d].csosn: valor %s inválido para CRT 4. Use 102, 103, 300, 400 ou 500.',
          $index,
          (string) $product['csosn']
        );
      }
    }

    if ($errors !== []) {
      throw new HttpException(
        'Dados fiscais incompatíveis com a operação.',
        422,
        ['error_tags' => array_values(array_unique($errors)), 'etapa' => 'validação fiscal']
      );
    }
  }

  /**
   * Método abstrato para processamento dos impostos específicos de cada regime
   * Cada controller filho deve implementar sua própria lógica
   */
  abstract protected function processarImpostosProduto($produto, $index);

  /**
   * Cria a NFe chamando o método abstrato de impostos para cada produto
   */
  public function createNfe($onlyPreview = false)
  {
    if (empty($this->data) || !isset($this->data['cnpj']) || !isset($this->data['cliente'])) {
      JsonResponse::error('Payload inválido para emissão de NF-e.', 400);
      return;
    }

    $stage = 'montagem das tags';

    try {
      $std = new stdClass();
      $std->versao = '4.00';
      $this->nfe->taginfNFe($std);
      $this->nfe->tagide($this->generateIdeData($this->data));
      $this->nfe->tagemit($this->generateDataCompany());
      $this->nfe->tagenderEmit($this->generateDataAddress());
      $this->nfe->tagdest($this->generateClientData($this->data));
      $this->nfe->tagenderDest($this->generateClientAddressData($this->data['cliente']['endereco']));

      if (($this->data['total_frete'] ?? 0) > 0) {
        $this->totalFrete = floatval($this->data['total_frete']);
      }

      $this->baseTotalIcms = 0;
      $this->totalIcms = 0;
      $this->total_produtos = 0;
      $this->totalDesconto = 0;

      foreach ($this->data['produtos'] as $produto) {
        $this->total_produtos += floatval($produto['total']);
        $this->totalDesconto += floatval($produto['desconto'] ?? 0);
      }

      foreach ($this->produtos as $index => $produto) {
        $produto['frete'] = isset($produto['frete']) && $produto['frete'] ? $produto['frete'] : $this->rateioFrete($produto['total'], $this->total_produtos, $this->totalFrete);

        $this->baseCalculo = max(
          0,
          floatval($produto['total'])
          - floatval($produto['desconto'] ?? 0)
          + floatval($produto['frete'])
          + floatval($produto['outras_despesas'] ?? 0)
        );
        $this->origem = $produto['origem'];

        $this->nfe->tagprod($this->generateProductData($produto, $index + 1));

        if (isset($produto['informacoes_adicionais']) && !empty($produto['informacoes_adicionais'])) {
          $this->nfe->taginfAdProd($this->generateProdutoInfoAdicional($produto, $index + 1));
        }

        $this->processarImpostosProduto($produto, $index);

        $this->nfe->tagimposto($this->generateImpostoData($produto, $index + 1));
        $this->totalImpostoProduto = 0;
        // $this->totalIcms += number_format($this->valorIcms, 2, ".", "");
      }

      $this->nfe->tagICMSTot($this->generateIcmsTot($this->data));
      $this->nfe->tagTotal($this->generateNFTotal());

      if (isset($this->data['observacao']) && !empty(trim($this->data['observacao']))) {
        $this->nfe->taginfAdic($this->generateIcmsInfo($this->data));
      }

      if (isset($this->data['nota_referencia']) && !empty($this->data['nota_referencia'])) {
        $this->nfe->tagrefNFe($this->generateReferencia($this->data['nota_referencia']));
      }

      $this->nfe->taginfRespTec($this->generateResponsavelTecnico());
      $this->nfe->tagtransp($this->generateFreteData($this->data));

      if ($this->modalidadeFrete !== 9 && isset($this->data['transportadora'])) {
        $this->nfe->tagtransporta($this->generateTransportadoraData($this->data['transportadora']));

        if (isset($this->data['transportadora']['veiculo'])) {
          $this->nfe->tagveicTransp($this->generateVeiculoData($this->data['transportadora']['veiculo']));
        }
      }

      $this->nfe->tagvol($this->generateVolumeData($this->data));

      $this->nfe->tagpag($this->generateFaturaData());
      $this->nfe->tagautXML($this->generateAutXMLData($this->data));

      foreach ($this->pagamentos as $pagamento) {
        $this->nfe->tagdetPag($this->generatePagamentoData($pagamento));
      }

      $stage = 'geração do XML';
      $this->currentXML = $this->nfe->getXML();

      $xmlErrors = $this->nfeErrors();
      if ($this->currentXML === '' || $xmlErrors !== []) {
        throw new \InvalidArgumentException('O XML da NF-e não pôde ser gerado com os dados informados.');
      }

      if ($onlyPreview) {
        $stage = 'geração da pré-visualização';
        $this->processarPreview();
        return;
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
        && in_array($stage, ['montagem das tags', 'geração do XML', 'geração da pré-visualização'], true);
      $isSefazError = in_array($stage, [
        'comunicação com a SEFAZ',
        'leitura da resposta da SEFAZ',
        'processamento da resposta da SEFAZ',
      ], true);

      $status = $isPayloadError ? 422 : ($isSefazError ? 502 : 500);
      $message = $isPayloadError
        ? 'Não foi possível montar a NF-e com os dados informados.'
        : ($isSefazError ? 'Não foi possível concluir a comunicação com a SEFAZ.' : 'Não foi possível emitir a NF-e.');

      JsonResponse::error($message, $status, [
        'error_tags' => $this->emissionErrorTags($e, $stage),
        'etapa' => $stage,
      ]);
    }
  }

  protected function nfeErrors(): array
  {
    if (!is_object($this->nfe) || !method_exists($this->nfe, 'getErrors')) {
      return [];
    }

    return array_values(array_unique(array_filter(
      array_map(static fn($error) => trim((string) $error), $this->nfe->getErrors()),
      static fn($error) => $error !== ''
    )));
  }

  protected function emissionErrorTags(\Throwable $exception, string $stage): array
  {
    $errors = $this->nfeErrors();
    $exceptionMessage = $this->publicEmissionExceptionMessage($exception, $stage);

    if ($exceptionMessage !== '') {
      $errors[] = $exceptionMessage;
    }

    if ($errors === []) {
      $errors[] = "Falha na etapa: {$stage}.";
    }

    return array_values(array_unique($errors));
  }

  protected function publicEmissionExceptionMessage(\Throwable $exception, string $stage): string
  {
    $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');

    if ($stage === 'assinatura digital') {
      return 'Não foi possível assinar o XML. Verifique a validade, o arquivo e a senha do certificado digital da empresa.';
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

  protected function logEmissionException(\Throwable $exception, string $stage): void
  {
    error_log(sprintf(
      '[NF-e][%s] %s: %s em %s:%d',
      $stage,
      get_class($exception),
      $exception->getMessage(),
      $exception->getFile(),
      $exception->getLine()
    ));
  }

  /**
   * Cancela uma NFe
   */
  public function cancelNfe($data)
  {
    try {
      foreach (['cnpj', 'chave', 'justificativa'] as $field) {
        if (empty($data[$field])) {
          JsonResponse::error("Campo obrigatório: {$field}", 422);
          return;
        }
      }

      if (mb_strlen(trim($data['justificativa'])) < 15) {
        JsonResponse::error('Justificativa deve ter no mínimo 15 caracteres.', 422);
        return;
      }

      if ($this->bootstrapCompanyAndToolsByCnpj($data['cnpj']) === false) {
        JsonResponse::error('Empresa não encontrada.', 404);
        return;
      }

      $emissoesModel = new EmissoesModel($data['chave']);
      $emissao = $emissoesModel->getCurrent();
      if ($emissao->tipo !== 'NFE') {
        JsonResponse::error('A chave informada não pertence a uma NF-e.', 422);
        return;
      }

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

        JsonResponse::send([
          "chave" => $emissao->chave,
          "avisos" => [],
          "protocolo" => $protocoloCancelamento,
          "link" => UtilsController::publicUrl($link),
          "xml" => $emissao->xml,
          "pdf" => base64_encode($pdf)
        ]);
      } else {
        JsonResponse::send([
          "status" => "error",
          "message" => "Erro ao cancelar: " . $std->xMotivo
        ], 422);
      }
    } catch (\RuntimeException $e) {
      if ($e->getMessage() === 'Emissão não encontrada.') {
        JsonResponse::error($e->getMessage(), 404);
        return;
      }
      error_log($e->getMessage());
      JsonResponse::error('Erro interno ao cancelar a NF-e.', 500);
    } catch (\Throwable $e) {
      error_log($e->getMessage());
      JsonResponse::error('Erro interno ao cancelar a NF-e.', 500);
    }
  }

  /**
   * Gera carta de correção
   */
  public function gerarCC($data)
  {
    foreach (['cnpj', 'chave', 'carta'] as $field) {
      if (empty($data[$field])) {
        JsonResponse::error("Campo obrigatório: {$field}", 422);
        return;
      }
    }
    if (mb_strlen(trim($data['carta'])) < 15) {
      JsonResponse::error('A correção deve ter no mínimo 15 caracteres.', 422);
      return;
    }

    if ($this->bootstrapCompanyAndToolsByCnpj($data['cnpj']) === false) {
      JsonResponse::error('Empresa não encontrada.', 404);
      return;
    }

    $chaveNFe = $data['chave'];
    $correcao = $data['carta'];

    // Busca a emissão original
    try {
      $emissoesModel = new EmissoesModel($data['chave']);
      $emissao = $emissoesModel->getCurrent();
    } catch (\RuntimeException $exception) {
      if ($exception->getMessage() === 'Emissão não encontrada.') {
        JsonResponse::error($exception->getMessage(), 404);
        return;
      }
      throw $exception;
    }

    if ($emissao->tipo !== 'NFE') {
      JsonResponse::error('A chave informada não pertence a uma NF-e.', 422);
      return;
    }

    $grupoCorrecao = ($emissao->sequencia_cc ?? 0) + 1;

    // Envia a CC-e para a SEFAZ
    $response = $this->tools->sefazCCe($chaveNFe, $correcao, $grupoCorrecao);

    $stdCl = new Standardize();
    $std = $stdCl->toStd($response);

    if (!in_array($std->cStat, ['135', '136', '128'])) {
      JsonResponse::send([
        'error' => 'Erro ao processar CC-e',
        'codigo' => $std->cStat,
        'motivo' => $std->xMotivo
      ], 422);
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

      JsonResponse::send([
        'chave' => $chaveNFe,
        'avisos' => [],
        'protocolo' => $protocolo,
        'sequencia' => $grupoCorrecao,
        'link' => UtilsController::publicUrl($link),
        'xml' => $xmlProtocolado,
        'pdf' => base64_encode($pdfCCe)
      ]);
    } catch (\Exception $e) {
      // Se falhar ao gerar o PDF, retorna sem o PDF mas com sucesso no evento
      JsonResponse::send([
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
    $std->finNFe = isset($data['finalidade']) ? $data['finalidade'] : 1;
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

      $std->xNome = substr($cliente['nome'], 0, 60);

      if ($cliente['tipo_documento'] === 'CPF') {
        $std->CPF = UtilsController::soNumero($cliente['documento']);
        $std->indIEDest = $this->indIEDest;
        return $std;
      }

      $std->CNPJ = UtilsController::soNumero($cliente['documento']);

      $ieInfo = $this->resolveIndIEDest($cliente);
      $std->indIEDest = $ieInfo['indIEDest'];

      // if ($ieInfo['indIEDest'] === 1 && !empty($ieInfo['IE'])) {
      //   $std->IE = $ieInfo['IE'];
      // }

      if (isset($cliente['inscricao_estadual'])) {
        $std->IE = $cliente['inscricao_estadual'];
      }
    } else {
      $std->xNome = "Consumidor Final";
      $std->CPF = (new UtilsController)->gerarCpfValido();
      $std->indIEDest = 9;
    }

    return $std;
  }

  protected function generateReferencia($chave)
  {
    $std = new stdClass();
    $std->refNFe = str_replace(' ', '', $chave);
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
    $std->vProd = number_format($produto['valor'] * $produto['quantidade'], 2, ".", "");
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

    if (isset($produto['outras_despesas']) && $produto['outras_despesas'] > 0) {
      $std->vOutro = number_format($produto['outras_despesas'], 2, ".", "");
      $this->totalOutrasDespesas += floatval($produto['outras_despesas']);
    }

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
    $std->vTotTrib = $this->totalImpostoProduto;

    return $std;
  }

  protected function addCombustivelTag($produto, $item)
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

  protected function addICMSCombTag($produto, $item)
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

    $allowedFields = [
      'modBC', 'vBC', 'vBCICMS', 'pICMS', 'vICMS', 'vBCICMSST', 'pICMSST',
      'vICMSST', 'vBCFCP', 'pFCP', 'vFCP', 'vBCFCPST', 'pFCPST', 'vFCPST',
      'qBCMono', 'adRemICMS', 'vICMSMono', 'qBCMonoRet', 'adRemICMSRet',
      'vICMSMonoRet', 'qBCMonoDif', 'adRemICMSDif', 'vICMSMonoDif',
    ];
    foreach ($allowedFields as $field) {
      if (array_key_exists($field, $icms)) {
        $std->{$field} = $icms[$field];
      }
    }

    return $std;
  }

  // ==================== MÉTODOS DE TOTALIZAÇÃO ====================

  protected function generateIcmsTot($data)
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

    $totalProdutos = floatval($this->total_produtos);

    $std->vFrete = $this->totalFrete;
    $std->vSeg = 0.00;
    $std->vDesc = number_format($this->totalDesconto, 2, ".", "");
    $std->vII = 0.00;
    $std->vIPI = number_format($this->totalIPI, 2, ".", "");
    $std->vIPIDevol = 0.00;
    $std->vPIS = $this->totalPIS;
    $std->vCOFINS = $this->totalCOFINS;
    $std->vOutro = $this->totalOutrasDespesas;
    $std->vNF = number_format(
      $totalProdutos - $this->totalDesconto + $this->totalFrete + $this->totalOutrasDespesas,
      2,
      ".",
      ""
    );
    $std->vTotTrib = number_format($this->totalImposto, 2, ".", "");

    return $std;
  }

  protected function generateNFTotal()
  {
    $std = new stdClass();

    $std->vBCIBSCBS = number_format($this->baseIBS, 2, ".", "");

    // IBS
    $std->vIBSUF = number_format($this->totalIBSUF, 2, ".", "");
    $std->vIBSMun = number_format($this->totalIBSMun, 2, ".", "");
    $std->vIBS = number_format($this->totalIBS, 2, ".", "");

    // CBS
    $std->vCBS = number_format($this->totalCBS, 2, ".", "");

    // créditos
    $std->vCredPres = "0.00";
    $std->vCredPresCondSus = "0.00";

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

  protected function generateFreteData($data)
  {
    $std = new stdClass();
    $std->modFrete = (int) ($data['modalidade_frete'] ?? 9);
    $this->modalidadeFrete = $std->modFrete;

    return $std;
  }

  protected function generateTransportadoraData($data)
  {
    $std = new stdClass();
    $std->CNPJ = $data['cnpj'] ?? '';
    $std->xNome = $data['razao_social'] ?? '';
    $std->xMun = $data['cidade'] ?? '';
    $std->UF = $data['uf'] ?? '';

    return $std;
  }

  protected function generateVeiculoData($data)
  {
    $std = new stdClass();
    $std->placa = $data['placa'] ?? '';
    $std->UF = $data['uf'] ?? '';

    return $std;
  }

  protected function generateVolumeData($data)
  {
    $std = new stdClass();
    $std->qVol = $data['quantidade_volumes'] ?? 0;
    $std->esp = $data['especie_volume'] ?? '';
    $std->pesoL = $data['peso_liquido'] ?? 0;
    $std->pesoB = $data['peso_bruto'] ?? 0;

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
    if (isset($this->data['finalidade']) && $this->data['finalidade'] == 4) {
      $std->tPag = 90;
      $std->vPag = 0;
    } else {
      $std->indPag = isset($pagamento['indPag']) ? $pagamento['indPag'] : 0;
      $std->tPag = str_pad($pagamento['tPag'], 2, '0', STR_PAD_LEFT);
      $std->vPag = number_format($pagamento['valorpago'], 2, ".", "");

      if (in_array($std->tPag, ['03', '04', '17', '3', '4', '17', 3, 4, 17])) {
        $std->tpIntegra = 2;
        $std->CNPJPag = "00000000000191";
        $std->tBand = "99";
        $std->cAut = "000000";
      }
    }

    return $std;
  }

  protected function generateResponsavelTecnico()
  {
    return UtilsController::technicalResponsible();
  }

  protected function generateAutXMLData($data)
  {
    $std = new stdClass();
    $std->CNPJ = isset($data['cnpj_consulta'])
      ? UtilsController::soNumero($data['cnpj_consulta'])
      : UtilsController::environment('AUTXML_CNPJ_PADRAO', '13937073000156');
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
    if ((int) $this->ambiente === 1) {
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
    $danfe->setDefaultFont('arial');
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

  protected function processarPreview()
  {
    $danfe = new Danfe($this->currentXML);
    $danfe->setDefaultFont('arial');
    $danfe->creditsIntegratorFooter('Estoque Premium - Sistema de Gestão Comercial');
    $this->currentPDF = $danfe->render();
    UtilsController::uploadXmlPreview($this->currentXML, $this->currentChave);
    $link = UtilsController::uploadPdfPreview($this->currentPDF, $this->currentChave . uniqid());
    JsonResponse::send([
      "chave" => $this->currentChave,
      "avisos" => $this->warnings,
      "protocolo" => $this->numeroProtocolo,
      "link" => UtilsController::publicUrl($link),
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

  private function salvarEvento($data)
  {
    $emissoesEventosModel = new EmissoesEventosModel();
    $emissoesEventosModel->setChave($data['chave']);
    $emissoesEventosModel->setTipo($data['tipo']);
    $emissoesEventosModel->setLink($data['link']);
    $emissoesEventosModel->setXml($data['xml']);
    $emissoesEventosModel->setProtocolo($data['protocolo']);
    $emissoesEventosModel->create();
  }

  protected function rateioFrete($produtoValor, $totalProdutos, $valorFrete)
  {
    if ($totalProdutos == 0) {
      return 0;
    }

    return ($produtoValor / $totalProdutos) * $valorFrete;
  }
}
