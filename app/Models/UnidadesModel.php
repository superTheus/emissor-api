<?php

namespace App\Models;

use App\Models\Connection;
use stdClass;

class UnidadesModel extends Connection
{
  private $conn;
  private $id;
  private $nome;
  private $sigla;
  private $table = "unidades";

  public function __construct($id = null)
  {
    $this->conn = $this->openConnection();
    if ($id) {
      $this->setId($id);
      $this->findById();
    }
  }

  public function findById()
  {
    $sql = "SELECT * FROM {$this->table} WHERE id = :id";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindValue(':id', $this->getId());
      $stmt->execute();
      $result = $stmt->fetch(\PDO::FETCH_ASSOC);

      $this->setId($result['id']);
      $this->setNome($result['nome']);
      $this->setSigla($result['sigla']);
    } catch (\Exception $e) {
      throw new \Exception($e->getMessage());
    }
  }

  public function current()
  {
    $data = new stdClass();
    $data->id = $this->getId();
    $data->nome = $this->getNome();
    $data->sigla = $this->getSigla();
    return $data;
  }

  public function find($filters = [])
  {
    $sql = "SELECT * FROM {$this->table}";

    if (!empty($filters)) {
      $sql .= " WHERE ";
      $sql .= implode(" AND ", array_map(function ($key, $value) {
        return "{$key} = '{$value}'";
      }, array_keys($filters), $filters));
    }

    $stmt = $this->conn->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
   * Get the value of nome
   */
  public function getNome()
  {
    return $this->nome;
  }

  /**
   * Set the value of nome
   *
   * @return  self
   */
  public function setNome($nome)
  {
    $this->nome = $nome;

    return $this;
  }

  /**
   * Get the value of sigla
   */
  public function getSigla()
  {
    return $this->sigla;
  }

  /**
   * Set the value of sigla
   *
   * @return  self
   */
  public function setSigla($sigla)
  {
    $this->sigla = $sigla;

    return $this;
  }
}
