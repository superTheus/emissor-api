<?php

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Models\EstadosModel;

final class EstadosController
{
  private EstadosModel $model;

  public function __construct($id = null)
  {
    $this->model = new EstadosModel($id);
  }

  public function findOnly($data)
  {
    $results = $this->search($data);

    return count($results) === 1 ? $results[0] : $results;
  }

  public function find($data): void
  {
    try {
      $results = $this->search($data);
      JsonResponse::send(count($results) === 1 ? $results[0] : $results);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar estados.', 500);
    }
  }

  public function findunique($data): void
  {
    try {
      $results = $this->search($data);
      if ($results === []) {
        JsonResponse::error('Estado não encontrado.', 404);
        return;
      }

      JsonResponse::send($results[0]);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar o estado.', 500);
    }
  }

  private function search($data): array
  {
    $data = is_array($data) ? $data : [];

    return $this->model->find($data['filter'] ?? [], $data['limit'] ?? null);
  }
}
