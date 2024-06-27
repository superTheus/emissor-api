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
    $certificadoDecodificado = base64_decode($certificadoBase64);

    $caminhoTemporario = tempnam(sys_get_temp_dir(), 'cert');
    file_put_contents($caminhoTemporario, $certificadoDecodificado);

    $certificado_info = array();
    $resultado = openssl_pkcs12_read(file_get_contents($caminhoTemporario), $certificado_info, $senha);

    unlink($caminhoTemporario);

    if (!$resultado) {
      http_response_code(200);
      echo json_encode(['sucesso' => false, 'mensagem' => 'Senha incorreta ou certificado inválido.']);
      return;
    }

    $certificado = openssl_x509_parse($certificado_info['cert']);
    $validadeInicio = $certificado['validFrom_time_t'];
    $validadeFim = $certificado['validTo_time_t'];
    $tempoAtual = time();

    if ($tempoAtual < $validadeInicio || $tempoAtual > $validadeFim) {
      http_response_code(200);
      echo json_encode(['sucesso' => false, 'mensagem' => 'Certificado expirado ou ainda não é válido.']);
      return;
    }

    http_response_code(200);
    echo json_encode(['sucesso' => true, 'mensagem' => 'Senha correta e certificado válido.']);
  }
}
