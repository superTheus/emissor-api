<?php

namespace App\Models;

use stdClass;

class CestModel extends Connection
{
  private $conn;
  private $cest_id;
  private $ncm_id;
  private $descricao;
  private $table = 'cest';

  public function __construct($id = null)
  {
    $this->conn = $this->openConnection();

    if ($id) {
      $this->setCest_id($id);
      $this->getById();
    }
  }

  private function getById()
  {
    $sql = "SELECT * FROM {$this->table} WHERE cest_id = :cest_id";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':cest_id', $this->cest_id);
      $stmt->execute();

      $cest = $stmt->fetch(\PDO::FETCH_ASSOC);
      $this->setNcm_id($cest['ncm_id']);
      $this->setDescricao($cest['descricao']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->cest_id = $this->getCest_id();
    $data->ncm_id = $this->getNcm_id();
    $data->descricao = $this->getDescricao();

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
   * Get the value of cest_id
   */
  public function getCest_id()
  {
    return $this->cest_id;
  }

  /**
   * Set the value of cest_id
   *
   * @return  self
   */
  public function setCest_id($cest_id)
  {
    $this->cest_id = $cest_id;

    return $this;
  }

  /**
   * Get the value of ncm_id
   */
  public function getNcm_id()
  {
    return $this->ncm_id;
  }

  /**
   * Set the value of ncm_id
   *
   * @return  self
   */
  public function setNcm_id($ncm_id)
  {
    $this->ncm_id = $ncm_id;

    return $this;
  }

  /**
   * Get the value of descricao
   */
  public function getDescricao()
  {
    return $this->descricao;
  }

  /**
   * Set the value of descricao
   *
   * @return  self
   */
  public function setDescricao($descricao)
  {
    $this->descricao = $descricao;

    return $this;
  }
}
