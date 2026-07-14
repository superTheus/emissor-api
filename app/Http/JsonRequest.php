<?php

namespace App\Http;

final class JsonRequest
{
  public static function body(): array
  {
    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
      return [];
    }

    if (!str_starts_with(ltrim($rawBody), '{')) {
      throw new \InvalidArgumentException('O corpo JSON precisa ser um objeto.');
    }

    try {
      $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      throw new \InvalidArgumentException('JSON inválido: ' . $exception->getMessage());
    }

    return $data;
  }
}
