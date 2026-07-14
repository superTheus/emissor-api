<?php

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Models\CompanyModel;

class UtilsController
{
  public static function soNumero($str)
  {
    return preg_replace("/[^0-9]/", "", $str);
  }

  public static function publicUrl(string $path): string
  {
    $baseUrl = rtrim((string) ($_ENV['URL_BASE'] ?? getenv('URL_BASE') ?: ''), '/');
    $normalizedPath = ltrim(str_replace('\\', '/', $path), '/');

    return $baseUrl === '' ? $normalizedPath : "{$baseUrl}/{$normalizedPath}";
  }

  public static function technicalResponsible(): \stdClass
  {
    $data = new \stdClass();
    $data->CNPJ = self::environment('RESP_TEC_CNPJ', '45730598000102');
    $data->xContato = self::environment('RESP_TEC_CONTATO', 'Logic Tecnologia e Inovação');
    $data->email = self::environment('RESP_TEC_EMAIL', 'contato.logictec@gmail.com');
    $data->fone = self::environment('RESP_TEC_TELEFONE', '92991225648');
    $data->idCSRT = self::environment('RESP_TEC_ID_CSRT', '01');

    return $data;
  }

  public static function environment(string $key, $default = null)
  {
    $value = $_ENV[$key] ?? getenv($key);

    return $value === false || $value === null || $value === '' ? $default : $value;
  }

  public static function getCertificado($certificado)
  {
    $certificatePath = dirname(__DIR__, 2)
      . '/app/storage/certificados/'
      . basename((string) $certificado);

    if (!is_file($certificatePath)) {
      throw new \RuntimeException('Certificado não encontrado.');
    }

    $content = file_get_contents($certificatePath);
    if ($content === false) {
      throw new \RuntimeException('Não foi possível ler o certificado.');
    }

    return $content;
  }

  /**
   * Abre e lê um certificado PKCS12 (.pfx/.p12) com suporte a algoritmos legacy
   *
   * @param string $certificadoContent Conteúdo binário do certificado ou caminho do arquivo
   * @param string $senha Senha do certificado
   * @param bool $isFilePath Se true, $certificadoContent é tratado como caminho de arquivo
   * @return array|false Retorna array com 'cert', 'pkey' e 'extracerts' ou false em caso de erro
   */
  public static function openCertificate($certificadoContent, $senha, $isFilePath = false)
  {
    // Se for caminho de arquivo, lê o conteúdo
    if ($isFilePath && file_exists($certificadoContent)) {
      $certificadoContent = file_get_contents($certificadoContent);
    }

    // A leitura nativa preserva a cadeia intermediária contida no PFX.
    // Sem essa cadeia, a SEFAZ pode recusar o certificado durante o TLS.
    $nativeCertificates = [];
    if (@openssl_pkcs12_read($certificadoContent, $nativeCertificates, $senha)) {
      return [
        'cert' => self::normalizePem($nativeCertificates['cert']),
        'pkey' => self::normalizePem($nativeCertificates['pkey']),
        'extracerts' => array_values(array_map(
          static fn($certificate) => self::normalizePem($certificate),
          $nativeCertificates['extracerts'] ?? []
        )),
      ];
    }

    // Salva em arquivo temporário
    $caminhoTemporario = tempnam(sys_get_temp_dir(), 'cert');
    $caminhoConvertido = tempnam(sys_get_temp_dir(), 'cert');
    if ($caminhoTemporario === false || $caminhoConvertido === false) {
      throw new \RuntimeException('Não foi possível criar arquivos temporários para o certificado.');
    }
    file_put_contents($caminhoTemporario, $certificadoContent, LOCK_EX);

    // Cria arquivo de configuração OpenSSL que força uso de algoritmos legacy
    $opensslConfig = tempnam(sys_get_temp_dir(), 'ssl');
    if ($opensslConfig === false) {
      @unlink($caminhoTemporario);
      @unlink($caminhoConvertido);
      throw new \RuntimeException('Não foi possível preparar a configuração OpenSSL.');
    }
    $configContent = <<<CONF
openssl_conf = openssl_init

[openssl_init]
providers = provider_sect

[provider_sect]
default = default_sect
legacy = legacy_sect

[default_sect]
activate = 1

[legacy_sect]
activate = 1
CONF;
    file_put_contents($opensslConfig, $configContent);

    // Usa proc_open para ter controle total do ambiente
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w']
    ];

    $env = [
      'OPENSSL_CONF' => $opensslConfig,
      'PATH' => getenv('PATH')
    ];

    $comando = sprintf(
      'openssl pkcs12 -in %s -out %s -nodes -passin %s -legacy',
      escapeshellarg($caminhoTemporario),
      escapeshellarg($caminhoConvertido),
      escapeshellarg('pass:' . $senha)
    );

    $process = proc_open($comando, $descriptors, $pipes, null, $env);

    $success = false;
    if (is_resource($process)) {
      fclose($pipes[0]);
      stream_get_contents($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $returnCode = proc_close($process);

      if ($returnCode === 0 && file_exists($caminhoConvertido)) {
        $success = true;
      }
    }

    // Se falhou, tenta sem -legacy
    if (!$success) {
      $comando = sprintf(
        'openssl pkcs12 -in %s -out %s -nodes -passin %s',
        escapeshellarg($caminhoTemporario),
        escapeshellarg($caminhoConvertido),
        escapeshellarg('pass:' . $senha)
      );

      $process = proc_open($comando, $descriptors, $pipes, null, $env);

      if (is_resource($process)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);

        if ($returnCode === 0 && file_exists($caminhoConvertido)) {
          $success = true;
        }
      }
    }

    @unlink($opensslConfig);
    @unlink($caminhoTemporario);

    if (!$success) {
      @unlink($caminhoConvertido);
      return false;
    }

    // Lê o certificado convertido
    $pemContent = file_get_contents($caminhoConvertido);
    @unlink($caminhoConvertido);

    // No fallback legacy, extrai todos os certificados e identifica o
    // certificado final pela correspondência com a chave privada.
    preg_match_all(
      '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
      $pemContent,
      $certMatches
    );

    // Tenta diferentes formatos de chave privada
    if (!preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $pemContent, $keyMatch)) {
      // Tenta formato RSA
      preg_match('/-----BEGIN RSA PRIVATE KEY-----.*?-----END RSA PRIVATE KEY-----/s', $pemContent, $keyMatch);
    }
    if (!isset($keyMatch[0])) {
      // Tenta formato ENCRYPTED
      preg_match('/-----BEGIN ENCRYPTED PRIVATE KEY-----.*?-----END ENCRYPTED PRIVATE KEY-----/s', $pemContent, $keyMatch);
    }

    if (empty($certMatches[0])) {
      return false;
    }

    $pkey = isset($keyMatch[0]) ? self::normalizePem($keyMatch[0]) : null;

    $leafIndex = 0;
    if ($pkey !== null) {
      foreach ($certMatches[0] as $index => $candidate) {
        if (@openssl_x509_check_private_key($candidate, $pkey)) {
          $leafIndex = $index;
          break;
        }
      }
    }

    $cert = self::normalizePem($certMatches[0][$leafIndex]);
    $extraCertificates = [];
    foreach ($certMatches[0] as $index => $candidate) {
      if ($index !== $leafIndex) {
        $extraCertificates[] = self::normalizePem($candidate);
      }
    }

    $result = [
      'cert' => $cert,
      'pkey' => $pkey,
      'extracerts' => $extraCertificates
    ];

    return $result;
  }

  /**
   * Lê certificado PFX e retorna no formato compatível com NFePHP Tools
   * Alternativa ao Certificate::readPfx() com suporte a algoritmos legacy
   *
   * @param string $certificadoContent Conteúdo binário do certificado
   * @param string $senha Senha do certificado
   * @return \NFePHP\Common\Certificate Objeto Certificate do NFePHP
   */
  public static function readPfxForNFePHP($certificadoContent, $senha)
  {
    $certs = self::openCertificate($certificadoContent, $senha);

    if (!$certs || empty($certs['cert']) || empty($certs['pkey'])) {
      throw new \Exception("Impossível ler o certificado. Verifique a senha ou formato do arquivo.");
    }

    // Apenas adapta o resultado da mesma leitura do PFX para os tipos exigidos
    // pelo construtor do NFePHP. Não aplica regras de vigência ou documento.
    $privateKeyPem = self::normalizePem($certs['pkey']);
    $publicKeyPem = self::normalizePem($certs['cert']);

    $privateKey = new \NFePHP\Common\Certificate\PrivateKey($privateKeyPem);
    $publicKey = new \NFePHP\Common\Certificate\PublicKey($publicKeyPem);
    $chainKeys = new \NFePHP\Common\Certificate\CertificationChain(
      implode('', $certs['extracerts'] ?? [])
    );

    return new \NFePHP\Common\Certificate(
      $privateKey,
      $publicKey,
      $chainKeys
    );
  }

  private static function normalizePem(string $pem): string
  {
    return trim(str_replace(["\r\n", "\r"], "\n", $pem)) . "\n";
  }

  public static function testCertificate($cnpj)
  {
    try {
      $companies = (new CompanyModel())->find([
        'cnpj' => self::soNumero($cnpj),
      ], 1);

      if ($companies === []) {
        JsonResponse::error('Empresa não encontrada.', 404);
        return;
      }

      $company = new CompanyModel($companies[0]['id']);
      $certificateContent = self::getCertificado($company->getCertificado());
      $certificate = self::openCertificate($certificateContent, $company->getSenha());
      if (!$certificate) {
        JsonResponse::error('Certificado ou senha inválidos.', 422);
        return;
      }

      $data = openssl_x509_parse($certificate['cert']);
      if ($data === false) {
        JsonResponse::error('Não foi possível ler os dados do certificado.', 422);
        return;
      }

      $commonName = (string) ($data['subject']['CN'] ?? '');
      $parts = explode(':', $commonName);
      $document = self::soNumero((string) (end($parts) ?: ''));
      $name = count($parts) > 1 ? implode(':', array_slice($parts, 0, -1)) : $commonName;

      JsonResponse::send([
        'emissao' => date('Y-m-d', (int) ($data['validFrom_time_t'] ?? 0)),
        'dt_vencimento' => date('Y-m-d', (int) ($data['validTo_time_t'] ?? 0)),
        'nome' => $name,
        'documento' => $document,
      ]);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao testar o certificado.', 500);
    }
  }

  public static function uploadXml($xml, $chave)
  {
    $folderPath = dirname(__DIR__, 2) . "/app/storage/fiscal/xml";
    $fileName = 'xml_' . self::safeFileToken($chave) . '.xml';
    self::writeFile($folderPath, $fileName, $xml);

    return $fileName;
  }

  public static function uploadXmlPreview($xml, $chave)
  {
    $folderPath = dirname(__DIR__, 2) . "/app/storage/fiscal/xml/preview";
    $fileName = 'xml_' . self::safeFileToken($chave) . '.xml';
    self::writeFile($folderPath, $fileName, $xml);

    return $fileName;
  }

  public static function uploadPdf($pdf, $chave)
  {
    $relativeFolder = "app/storage/fiscal/pdf";
    $folderPath = dirname(__DIR__, 2) . "/{$relativeFolder}";
    $fileName = 'pdf_' . self::safeFileToken($chave) . '.pdf';
    self::writeFile($folderPath, $fileName, $pdf);

    return $relativeFolder . "/" . $fileName;
  }

  public static function uploadPdfPreview($pdf, $chave)
  {
    $relativeFolder = "app/storage/fiscal/pdf/preview";
    $folderPath = dirname(__DIR__, 2) . "/{$relativeFolder}";
    $fileName = 'pdf_' . self::safeFileToken($chave) . '.pdf';
    self::writeFile($folderPath, $fileName, $pdf);

    return $relativeFolder . "/" . $fileName;
  }

  private static function safeFileToken($value): string
  {
    $token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value);
    if ($token === '') {
      throw new \InvalidArgumentException('Identificador de arquivo inválido.');
    }

    return $token;
  }

  private static function writeFile(string $folderPath, string $fileName, string $content): void
  {
    if (!is_dir($folderPath) && !mkdir($folderPath, 0770, true) && !is_dir($folderPath)) {
      throw new \RuntimeException('Não foi possível criar a pasta de armazenamento fiscal.');
    }

    if (file_put_contents("{$folderPath}/{$fileName}", $content, LOCK_EX) === false) {
      throw new \RuntimeException('Não foi possível salvar o arquivo fiscal.');
    }
  }

  function gerarCpfValido()
  {
    $n1 = rand(0, 9);
    $n2 = rand(0, 9);
    $n3 = rand(0, 9);
    $n4 = rand(0, 9);
    $n5 = rand(0, 9);
    $n6 = rand(0, 9);
    $n7 = rand(0, 9);
    $n8 = rand(0, 9);
    $n9 = rand(0, 9);

    $d1 = $n9 * 2 + $n8 * 3 + $n7 * 4 + $n6 * 5 + $n5 * 6 + $n4 * 7 + $n3 * 8 + $n2 * 9 + $n1 * 10;
    $d1 = 11 - ($this->mod($d1, 11));
    $d1 = ($d1 >= 10) ? 0 : $d1;

    $d2 = $d1 * 2 + $n9 * 3 + $n8 * 4 + $n7 * 5 + $n6 * 6 + $n5 * 7 + $n4 * 8 + $n3 * 9 + $n2 * 10 + $n1 * 11;
    $d2 = 11 - ($this->mod($d2, 11));
    $d2 = ($d2 >= 10) ? 0 : $d2;

    return "$n1$n2$n3$n4$n5$n6$n7$n8$n9$d1$d2";
  }

  private function mod($dividendo, $divisor)
  {
    return round($dividendo - (floor($dividendo / $divisor) * $divisor));
  }

  public static function verificarOperacaoPorCFOP($cfop)
  {
    $primeiroDigito = substr($cfop, 0, 1);

    if (in_array($primeiroDigito, ['1', '2', '3'])) {
      return 0;
    } elseif (in_array($primeiroDigito, ['5', '6', '7'])) {
      return 1;
    }

    return 0;
  }

  public static function validaCST($cst)
  {
    $cst = str_pad((string) $cst, 2, '0', STR_PAD_LEFT);
    $validCSTs = [
      '01',
      '02',
      '03',
      '04',
      '05',
      '06',
      '07',
      '08',
      '09'
    ];

    return in_array($cst, $validCSTs);
  }

  public static function validaCSOSN($csosn)
  {
    $csosn = str_pad($csosn, 3, '0', STR_PAD_LEFT);
    $validCSOSNs = [
      '101',
      '102',
      '103',
      '201',
      '202',
      '203',
      '300',
      '400',
      '500',
      '900'
    ];

    return in_array($csosn, $validCSOSNs);
  }
}
