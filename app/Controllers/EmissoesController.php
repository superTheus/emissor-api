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

    $certs = [];
    if (!openssl_pkcs12_read($certificadoDecodificado, $certs, $senha)) {
      http_response_code(400);
      echo json_encode([
        'error' => 'Senha incorreta ou certificado inválido.',
        'php_version' => PHP_VERSION,
        'openssl_version' => OPENSSL_VERSION_TEXT
      ]);
      return;
    }

    $certificado = openssl_x509_parse($certs['cert']);

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
