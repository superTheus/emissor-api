<?php

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Models\EmissoesEventosModel;

final class EmissoesEventosController
{
  private $finder;

  public function __construct(?callable $finder = null)
  {
    $this->finder = $finder;
  }

  public function find($data): void
  {
    try {
      $data = is_array($data) ? $data : [];
      $hasEnvelope = array_key_exists('filter', $data) || array_key_exists('limit', $data);
      $filter = $hasEnvelope ? ($data['filter'] ?? []) : $data;
      $limit = $hasEnvelope ? ($data['limit'] ?? null) : null;

      $finder = $this->finder ?? static fn($filter, $limit): array =>
        (new EmissoesEventosModel())->find($filter, $limit);

      $results = $finder($filter, $limit);
      JsonResponse::send(array_map([$this, 'presentEvent'], $results));
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar eventos fiscais.', 500);
    }
  }

  private function presentEvent(array $event): array
  {
    if (!empty($event['link'])) {
      $event['link'] = UtilsController::publicUrl($event['link']);
    }

    return $event;
  }
}
