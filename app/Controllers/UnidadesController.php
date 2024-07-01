<?php

namespace App\Controllers;

use App\Models\UnidadesModel;

class UnidadesController
{
  protected $unidadesModel;

  public function __construct($id = null)
  {
    $this->unidadesModel = new UnidadesModel($id ? $id : null);
  }

  public function find($data)
  {
    $unidadesModel = new UnidadesModel();
    $filter = $data && isset($data['filter']) ? $data['filter'] : null;
    $limit = $data && isset($data['limit']) ? $data['limit'] : null;
    $results = $unidadesModel->find($filter, $limit);

    if ($results) {
      http_response_code(200); // OK
      echo json_encode($results);
    } else {
      http_response_code(404); // Not Found
      echo json_encode(['error' => 'No results found for the given filter']);
    }
  }
}
