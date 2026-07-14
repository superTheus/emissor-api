<?php

namespace App\Http;

use Dotenv\Dotenv;

final class ApiTokenMiddleware
{
  private static bool $environmentLoaded = false;

  public static function handle(): void
  {
    if (!self::$environmentLoaded) {
      Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
      self::$environmentLoaded = true;
    }

    $configuredToken = (string) ($_ENV['API_TOKEN'] ?? getenv('API_TOKEN') ?: '');
    if ($configuredToken === '') {
      return;
    }

    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $providedToken = (string) ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
      $providedToken = trim($matches[1]);
    }

    if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
      JsonResponse::error('Não autorizado.', 401);
      exit;
    }
  }
}
