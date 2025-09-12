<?php

namespace App\Controllers;

use App\Models\MunicipioModel;

class MunicipiosController
{
  protected $municipiosModel;

  public function __construct($id = null)
  {
    $this->municipiosModel = new MunicipioModel($id ? $id : null);
  }

  public function find($data)
  {
    try {
      $filter = $data && isset($data['filter']) ? $data['filter'] : null;
      $limit = $data && isset($data['limit']) ? $data['limit'] : null;
      $results = $this->municipiosModel->find($filter, $limit);

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
      $results = $this->municipiosModel->find($filter, $limit);

      if ($results && count($results) > 0) {
        $results = $results[0];
      } else {
        throw new \Exception("Município não encontrado");
      }

      http_response_code(200);
      echo json_encode($results);
    } catch (\Exception $e) {
      http_response_code(401);
      echo $e->getMessage();
    }
  }

  public function findByUf($uf)
  {
    try {
      $estadoController = new EstadosController();
      $estado = $estadoController->findOnly(['filter' => ["uf = '$uf'"]]);

      if (!$estado) {
        http_response_code(404);
        echo json_encode(['error' => 'Estado não encontrado']);
        return;
      }

      $estado = $estado[0];

      $results = $this->municipiosModel->find(["id_estado = {$estado['id']}"]);

      http_response_code(200);
      echo json_encode($results);
    } catch (\Exception $e) {
      http_response_code(500); // Internal Server Error
      echo json_encode(['error' => $e->getMessage()]);
    }
  }
}
