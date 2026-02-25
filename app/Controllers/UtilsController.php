<?php

namespace App\Controllers;

use App\Models\CompanyModel;

class UtilsController
{
  public static function soNumero($str)
  {
    return preg_replace("/[^0-9]/", "", $str);
  }

  public static function getCertifcado($certificado)
  {
    $folderPath = "app/storage/certificados";
    $certificadoPath = $folderPath . "/" . $certificado;
    return file_get_contents($certificadoPath);
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

    // Salva em arquivo temporário
    $caminhoTemporario = tempnam(sys_get_temp_dir(), 'cert') . '.p12';
    file_put_contents($caminhoTemporario, $certificadoContent);
    $caminhoConvertido = tempnam(sys_get_temp_dir(), 'cert') . '.pem';

    // Cria arquivo de configuração OpenSSL que força uso de algoritmos legacy
    $opensslConfig = tempnam(sys_get_temp_dir(), 'ssl') . '.cnf';
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
      'openssl pkcs12 -in %s -out %s -nodes -password pass:%s -legacy',
      escapeshellarg($caminhoTemporario),
      escapeshellarg($caminhoConvertido),
      escapeshellarg($senha)
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
        'openssl pkcs12 -in %s -out %s -nodes -password pass:%s',
        escapeshellarg($caminhoTemporario),
        escapeshellarg($caminhoConvertido),
        escapeshellarg($senha)
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

    // Extrai as partes do PEM - suporta múltiplos formatos de chave privada
    preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pemContent, $certMatch);

    // Tenta diferentes formatos de chave privada
    if (!preg_match('/-----BEGIN PRIVATE KEY-----.*?-----END PRIVATE KEY-----/s', $pemContent, $keyMatch)) {
      // Tenta formato RSA
      preg_match('/-----BEGIN RSA PRIVATE KEY-----.*?-----END RSA PRIVATE KEY-----/s', $pemContent, $keyMatch);
    }
    if (!isset($keyMatch[0])) {
      // Tenta formato ENCRYPTED
      preg_match('/-----BEGIN ENCRYPTED PRIVATE KEY-----.*?-----END ENCRYPTED PRIVATE KEY-----/s', $pemContent, $keyMatch);
    }

    if (!isset($certMatch[0])) {
      return false;
    }

    // Garante que termina com quebra de linha
    $cert = trim($certMatch[0]) . "\n";
    $pkey = isset($keyMatch[0]) ? trim($keyMatch[0]) . "\n" : null;

    $result = [
      'cert' => $cert,
      'pkey' => $pkey,
      'extracerts' => []
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

    if (!$certs) {
      throw new \Exception("Impossível ler o certificado. Verifique a senha ou formato do arquivo.");
    }

    if (empty($certs['pkey'])) {
      throw new \Exception("Chave privada não encontrada no certificado.");
    }

    // Garante que as chaves estão formatadas corretamente (com quebras de linha)
    $privateKeyPem = trim($certs['pkey']);
    $publicKeyPem = trim($certs['cert']);

    // Valida que os PEM estão corretos
    if (strpos($privateKeyPem, '-----BEGIN') === false || strpos($privateKeyPem, '-----END') === false) {
      throw new \Exception("Formato de chave privada inválido.");
    }
    if (strpos($publicKeyPem, '-----BEGIN CERTIFICATE-----') === false) {
      throw new \Exception("Formato de certificado público inválido.");
    }

    // Cria objetos NFePHP com as chaves extraídas
    $privateKey = new \NFePHP\Common\Certificate\PrivateKey($privateKeyPem);
    $publicKey = new \NFePHP\Common\Certificate\PublicKey($publicKeyPem);
    $chainKeys = new \NFePHP\Common\Certificate\CertificationChain();

    // Cria o objeto Certificate usando o construtor público
    $certificate = new \NFePHP\Common\Certificate(
      $privateKey,
      $publicKey,
      $chainKeys
    );

    return $certificate;
  }

  public static function debugCertificate($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => $cnpj
    ]);

    if ($company) {
      $company = new CompanyModel($company[0]['id']);

      try {
        $certificado = self::getCertifcado($company->getCertificado());
        $certificate = self::readPfxForNFePHP($certificado, $company->getSenha());

        // Simula o que o NFePHP faz
        $privateKeyStr = "{$certificate->privateKey}";
        $publicKeyStr = "{$certificate->publicKey}";
        $certificateStr = "{$certificate}";

        // Testa cada parte
        $privKeyValid = openssl_pkey_get_private($privateKeyStr) !== false;
        $pubKeyValid = openssl_x509_read($publicKeyStr) !== false;

        // Simula o arquivo certfile que o NFePHP cria: privateKey + certificate
        $certfile = $privateKeyStr . $certificateStr;
        $certfileValid = openssl_pkey_get_private($certfile) !== false;

        // Salva em arquivo temporário para testar exatamente como o cURL vai ler
        $tempFile = tempnam(sys_get_temp_dir(), 'cert_test') . '.pem';
        file_put_contents($tempFile, $certfile);
        $fileContent = file_get_contents($tempFile);
        $fileValid = openssl_pkey_get_private($fileContent) !== false;
        @unlink($tempFile);

        http_response_code(200);
        echo json_encode([
          "privateKey_length" => strlen($privateKeyStr),
          "privateKey_valid" => $privKeyValid,
          "privateKey_start" => substr($privateKeyStr, 0, 50),
          "privateKey_end" => substr($privateKeyStr, -50),
          "publicKey_length" => strlen($publicKeyStr),
          "publicKey_valid" => $pubKeyValid,
          "publicKey_start" => substr($publicKeyStr, 0, 50),
          "publicKey_end" => substr($publicKeyStr, -50),
          "certificate_length" => strlen($certificateStr),
          "certificate_start" => substr($certificateStr, 0, 50),
          "certfile_length" => strlen($certfile),
          "certfile_valid" => $certfileValid,
          "file_read_valid" => $fileValid,
          "file_equals_string" => $fileContent === $certfile
        ]);
      } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
      }
    } else {
      http_response_code(404);
      echo json_encode(["message" => "Empresa não encontrada"]);
    }
  }

  public static function testCertificate($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => $cnpj
    ]);

    if ($company) {
      $company = new CompanyModel($company[0]['id']);

      $folderPath = "app/storage/certificados";
      $certificadoPath = $folderPath . "/" . $company->getCertificado();

      $certs = self::openCertificate($certificadoPath, $company->getSenha(), true);

      if ($certs) {
        $data = openssl_x509_parse($certs['cert']);
        $data = json_encode($data);
        $data = json_decode($data);

        list($nome, $documento) = explode(":", $data->subject->CN);

        $dt_emissao    = date('Y-m-d', $data->validFrom_time_t);
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
    } else {
      http_response_code(404); // Not Found
      echo json_encode(["message" => "Empresa não encontrada"]);
    }
  }

  public static function uploadXml($xml, $chave)
  {
    $folderPath = "app/storage/fiscal/xml";
    $fileName = "xml_" . $chave . ".xml";

    if (!file_exists($folderPath)) {
      mkdir($folderPath, 0777, true);
    }

    file_put_contents($folderPath . "/" . $fileName, $xml);

    return $fileName;
  }

  public static function uploadPdf($pdf, $chave)
  {
    $folderPath = "app/storage/fiscal/pdf";
    $fileName = "pdf_" . $chave . ".pdf";

    if (!file_exists($folderPath)) {
      mkdir($folderPath, 0777, true);
    }

    file_put_contents($folderPath . "/" . $fileName, $pdf);

    return $folderPath . "/" . $fileName;
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

  public static function validaCST($cst) {
    $cst = str_pad($cst, 3, '0', STR_PAD_LEFT);
    $validCSTs = [
      '01', '02', '03', '04', '05', '06', '07', '08', '09'
    ];

    return in_array($cst, $validCSTs);
  }
}
