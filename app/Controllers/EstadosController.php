<?php

namespace App\Controllers;

use App\Models\EstadosModel;

class EstadosController
{
  protected $estadosModel;

  public function __construct($id = null)
  {
    $this->estadosModel = new EstadosModel($id ? $id : null);
  }

  public function findOnly($data)
  {
    try {
      $filter = $data && isset($data['filter']) ? $data['filter'] : null;
      $limit = $data && isset($data['limit']) ? $data['limit'] : null;
      $results = $this->estadosModel->find($filter, $limit);

      if ($results && count($results) === 1) {
        $results = $results[0];
      }

      return $results;
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function find($data)
  {
    try {
      $filter = $data && isset($data['filter']) ? $data['filter'] : null;
      $limit = $data && isset($data['limit']) ? $data['limit'] : null;
      $results = $this->estadosModel->find($filter, $limit);

      if($results && count($results) === 1) {
        $results = $results[0];
      }

      http_response_code(200);
      echo json_encode($results);
    } catch (\Exception $e) {
      http_response_code(500); // Internal Server Error
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function findunique($data)
  {
    try {
      $filter = $data && isset($data['filter']) ? $data['filter'] : null;
      $limit = $data && isset($data['limit']) ? $data['limit'] : null;
      $results = $this->estadosModel->find($filter, $limit);

      if ($results && count($results) > 0) {
        $results = $results[0];
      } else {
        throw new \Exception("Estado não encontrado");
      }

      http_response_code(200);
      echo json_encode($results);
    } catch (\Exception $e) {
      http_response_code(401);
      echo $e->getMessage();
    }
  }
}
