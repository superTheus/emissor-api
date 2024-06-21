<?php

namespace App\Models;

use stdClass;

class IbptModel extends Connection
{
  private $conn;
  private $codigo;
  private $nacional;
  private $importado;
  private $table = 'ibpt_nacional';

  public function __construct($id = null)
  {
    $this->conn = $this->openConnection();

    if ($id) {
      $this->setCodigo($id);
      $this->getById();
    }
  }

  private function getById()
  {
    $sql = "SELECT * FROM {$this->table} WHERE codigo = :codigo";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':codigo', $this->codigo);
      $stmt->execute();

      $cest = $stmt->fetch(\PDO::FETCH_ASSOC);
      $this->setNacional($cest['nacional']);
      $this->setImportado($cest['importado']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->codigo = $this->getCodigo();
    $data->nacional = $this->getNacional();
    $data->importado = $this->getImportado();

    return $data;
  }

  public function find($filter = [], $limit = null)
  {
    $sql = "SELECT * FROM {$this->table}";

    if (!empty($filter)) {
      $sql .= " WHERE ";
      $sql .= implode(" AND ", array_map(function ($column) {
        return "$column = :$column";
      }, array_keys($filter)));
    }

    if ($limit !== null) {
      $sql .= " LIMIT :limit";
    }

    try {
      $stmt = $this->conn->prepare($sql);

      if (!empty($filter)) {
        foreach ($filter as $column => $value) {
          $stmt->bindValue(":$column", $value);
        }
      }

      if ($limit !== null) {
        $stmt->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
      }

      $stmt->execute();

      return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  /**
   * Get the value of codigo
   */
  public function getCodigo()
  {
    return $this->codigo;
  }

  /**
   * Set the value of codigo
   *
   * @return  self
   */
  public function setCodigo($codigo)
  {
    $this->codigo = $codigo;

    return $this;
  }

  /**
   * Get the value of nacional
   */
  public function getNacional()
  {
    return $this->nacional;
  }

  /**
   * Set the value of nacional
   *
   * @return  self
   */
  public function setNacional($nacional)
  {
    $this->nacional = $nacional;

    return $this;
  }

  /**
   * Get the value of importado
   */
  public function getImportado()
  {
    return $this->importado;
  }

  /**
   * Set the value of importado
   *
   * @return  self
   */
  public function setImportado($importado)
  {
    $this->importado = $importado;

    return $this;
  }
}
