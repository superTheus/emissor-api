<?php

namespace App\Models;

use stdClass;

class OrigemModel extends Connection
{
  private $conn;
  private $id;
  private $descricao;
  private $table = 'origem';

  public function __construct($id = null)
  {
    $this->conn = $this->openConnection();

    if ($id) {
      $this->setId($id);
      $this->getById();
    }
  }

  private function getById()
  {
    $sql = "SELECT * FROM {$this->table} WHERE id = :id";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':id', $this->id);
      $stmt->execute();

      $origem = $stmt->fetch(\PDO::FETCH_ASSOC);
      $this->setId($origem['id']);
      $this->setDescricao($origem['descricao']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->id = $this->getId();
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
   * Get the value of id
   */
  public function getId()
  {
    return $this->id;
  }

  /**
   * Set the value of id
   *
   * @return  self
   */
  public function setId($id)
  {
    $this->id = $id;

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
