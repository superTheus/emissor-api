<?php

namespace App\Controllers;

use App\Models\FormaPagamentoModel;

class FormaPagamentoController
{
  protected $formaPagamentoModel;

  public function __construct($id = null)
  {
    $this->formaPagamentoModel = new FormaPagamentoModel($id ? $id : null);
  }

  public function find($data)
  {
    try {
      $companyModel = new FormaPagamentoModel();
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
