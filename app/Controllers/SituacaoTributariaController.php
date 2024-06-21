<?php

namespace App\Controllers;

use App\Models\SituacaoTributariaModel;

class SituacaoTributariaController
{
  protected $situacaoTributariaModel;

  public function __construct($id = null)
  {
    $this->situacaoTributariaModel = new SituacaoTributariaModel($id ? $id : null);
  }

  public function find($data)
  {
    try {
      $companyModel = new SituacaoTributariaModel();
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
}
