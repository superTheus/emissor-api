<?php

namespace App\Models;

use PDO;
use PDOException;

define("SERVER",    "localhost");
define("DATABASE", "emissor_api");
define("USER",     "root");
define("PASSWORD",       "");

class Connection
{
  private $connection;

  public function openConnection()
  {
    try {
      $this->connection = new PDO("mysql:host=" . SERVER . ";dbname=" . DATABASE . ";charset=utf8", USER, PASSWORD);

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
