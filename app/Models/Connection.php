<?php

namespace App\Models;

use Dotenv\Dotenv;
use PDO;

class Connection
{
  private ?PDO $connection = null;
  private static bool $environmentLoaded = false;

  public function openConnection(): PDO
  {
    if (!self::$environmentLoaded) {
      Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
      self::$environmentLoaded = true;
    }

    $config = [];
    foreach (['DB_SERVER', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $variable) {
      $value = $_ENV[$variable] ?? getenv($variable);
      if ($value === false || $value === null) {
        throw new \RuntimeException("Variável de ambiente obrigatória ausente: {$variable}");
      }
      $config[$variable] = $value;
    }

    $dsn = sprintf(
      'mysql:host=%s;dbname=%s;charset=utf8mb4',
      $config['DB_SERVER'],
      $config['DB_NAME']
    );

    $this->connection = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $this->connection;
  }

  public function closeConnection(): void
  {
    $this->connection = null;
  }

  public function getConnection(): ?PDO
  {
    return $this->connection;
  }
}
