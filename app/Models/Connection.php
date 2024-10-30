<?php

namespace App\Models;

use Dotenv\Dotenv;
use PDO;
use PDOException;

class Connection
{
  private $connection;

  public function openConnection()
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    try {
      $this->connection = new PDO("mysql:host=" . $_ENV['DB_SERVER'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8", $_ENV['DB_USER'], $_ENV['DB_PASS']);

      //             Mostra erros de PDO
      $this->connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
      $this->connection->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
      return $this->connection;
    } catch (PDOException $ex) {
      return $ex->getMessage();
    }
  }

  public function closeConnection()
  {
    return $this->connection = null;
  }

  function getConnection()
  {
    return $this->connection;
  }
}
