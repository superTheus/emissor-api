<?php

namespace App\Controllers;

use App\Http\JsonResponse;

abstract class LookupController
{
  abstract protected function modelClass(): string;

  public function find($data): void
  {
    try {
      $data = is_array($data) ? $data : [];
      $modelClass = $this->modelClass();
      $model = new $modelClass();
      $results = $model->find($data['filter'] ?? [], $data['limit'] ?? null);

      JsonResponse::send($results);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      JsonResponse::error('Erro interno ao consultar o recurso.', 500);
      error_log($exception->getMessage());
    }
  }
}
