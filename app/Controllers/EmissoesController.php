<?php

namespace App\Controllers;

use App\Models\EmissoesModel;

class EmissoesController
{
  protected $emissoesModel;

  public function __construct($id = null)
  {
    $this->emissoesModel = new EmissoesModel($id ? $id : null);
  }

  public function find($data)
  {
    try {
      $companyModel = new EmissoesModel();
      $filter = $data && isset($data['filter']) ? $data['filter'] : null;
      $limit = $data && isset($data['limit']) ? $data['limit'] : null;
      $results = $companyModel->find($filter, $limit);

      http_response_code(200);
      echo json_encode($results);
    } catch (\Exception $e) {
      http_response_code(500); // Internal Server Error
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  function verifyCertificate($certificadoBase64, $senha)
  {
    if (strpos($certificadoBase64, 'base64,') !== false) {
      $certificadoBase64 = explode('base64,', $certificadoBase64, 2)[1];
    }
    $certificadoDecodificado = base64_decode($certificadoBase64);

    if ($certificadoDecodificado === false) {
      http_response_code(400);
      echo json_encode(['error' => 'Falha ao decodificar o certificado base64.']);
      return;
    }

    $caminhoTemporario = tempnam(sys_get_temp_dir(), 'cert') . '.p12';
    file_put_contents($caminhoTemporario, $certificadoDecodificado);

    // Tenta converter o certificado usando openssl via linha de comando (suporta legacy)
    $caminhoConvertido = tempnam(sys_get_temp_dir(), 'cert') . '.pem';
    
    // Converte PKCS12 para PEM usando linha de comando com provider legacy
    $comando = sprintf(
      'openssl pkcs12 -in %s -out %s -nodes -password pass:%s -legacy 2>&1',
      escapeshellarg($caminhoTemporario),
      escapeshellarg($caminhoConvertido),
      escapeshellarg($senha)
    );
    
    exec($comando, $output, $returnCode);
    
    // Se a conversão falhou, tenta sem -legacy (para OpenSSL < 3)
    if ($returnCode !== 0 || !file_exists($caminhoConvertido)) {
      $comando = sprintf(
        'openssl pkcs12 -in %s -out %s -nodes -password pass:%s 2>&1',
        escapeshellarg($caminhoTemporario),
        escapeshellarg($caminhoConvertido),
        escapeshellarg($senha)
      );
      exec($comando, $output2, $returnCode2);
      
      if ($returnCode2 !== 0 || !file_exists($caminhoConvertido)) {
        @unlink($caminhoTemporario);
        @unlink($caminhoConvertido);
        
        http_response_code(400);
        echo json_encode([
          'error' => 'Senha incorreta ou certificado inválido.',
          'openssl_output' => array_merge($output, $output2),
          'php_version' => PHP_VERSION,
          'openssl_version' => OPENSSL_VERSION_TEXT
        ]);
        return;
      }
    }

    // Lê o certificado convertido
    $pemContent = file_get_contents($caminhoConvertido);
    
    // Extrai apenas o certificado X.509
    preg_match('/-----BEGIN CERTIFICATE-----(.*)-----END CERTIFICATE-----/s', $pemContent, $matches);
    
    @unlink($caminhoTemporario);
    @unlink($caminhoConvertido);
    
    if (!isset($matches[0])) {
      http_response_code(400);
      echo json_encode(['error' => 'Não foi possível extrair o certificado.']);
      return;
    }

    $certificado = openssl_x509_parse($matches[0]);
    
    if ($certificado === false) {
      http_response_code(400);
      echo json_encode(['error' => 'Não foi possível analisar o certificado.']);
      return;
    }
    
    $validadeInicio = $certificado['validFrom_time_t'];
    $validadeFim = $certificado['validTo_time_t'];
    $tempoAtual = time();  

    if ($tempoAtual < $validadeInicio || $tempoAtual > $validadeFim) {
      http_response_code(400);
      echo json_encode(['error' => 'Certificado expirado ou ainda não é válido.']);
      return;
    }

    http_response_code(200);
    echo json_encode([
      "empresa" => explode(':', $certificado['subject']['CN'])[0],
      "cnpj" => explode(':', $certificado['subject']['CN'])[1]
    ]);
  }
}
