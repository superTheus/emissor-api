<?php

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Models\MunicipioModel;

final class MunicipiosController
{
  private MunicipioModel $model;

  public function __construct($id = null)
  {
    $this->model = new MunicipioModel($id);
  }

  public function find($data): void
  {
    try {
      JsonResponse::send($this->search($data));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar municípios.', 500);
    }
  }

  public function findunique($data): void
  {
    try {
      $results = $this->search($data);
      if ($results === []) {
        JsonResponse::error('Município não encontrado.', 404);
        return;
      }

      JsonResponse::send($results[0]);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar o município.', 500);
    }
  }

  public function findByUf($uf): void
  {
    try {
      $estado = (new EstadosController())->findOnly([
        'filter' => ['uf' => $uf],
        'limit' => 1,
      ]);

      if (!$estado || !isset($estado['id'])) {
        JsonResponse::error('Estado não encontrado.', 404);
        return;
      }

      JsonResponse::send($this->model->find(['id_estado' => $estado['id']]));
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar municípios por UF.', 500);
    }
  }

  private function search($data): array
  {
    $data = is_array($data) ? $data : [];

    return $this->model->find($data['filter'] ?? [], $data['limit'] ?? null);
  }
}
