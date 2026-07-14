<?php

namespace App\Http;

final class JsonResponse
{
  public static function send($data, int $status = 200): void
  {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    try {
      echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      http_response_code(500);
      echo json_encode([
        'error' => 'Falha ao serializar a resposta.',
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
  }

  public static function error(string $message, int $status = 400, array $context = []): void
  {
    self::send(array_merge(['error' => $message], $context), $status);
  }
}
