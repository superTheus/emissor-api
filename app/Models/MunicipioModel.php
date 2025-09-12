<?php

namespace App\Models;

use stdClass;

class MunicipioModel extends Connection
{
  private $conn;
  private $id;
  private $id_estado;
  private $nome;
  private $codigo_ibge;
  private $table = 'municipio';

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

      $cest = $stmt->fetch(\PDO::FETCH_ASSOC);
      $this->setId_estado($cest['id_estado']);
      $this->setNome($cest['nome']);
      $this->setCodigo_ibge($cest['codigo_ibge']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->id = $this->getId();
    $data->nome = $this->getNome();
    $data->id_estado = $this->getId_estado();
    $data->codigo_ibge = $this->getCodigo_ibge();
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
   * Get the value of id_estado
   */ 
  public function getId_estado()
  {
    return $this->id_estado;
  }

  /**
   * Set the value of id_estado
   *
   * @return  self
   */ 
  public function setId_estado($id_estado)
  {
    $this->id_estado = $id_estado;

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
   * Get the value of codigo_ibge
   */ 
  public function getCodigo_ibge()
  {
    return $this->codigo_ibge;
  }

  /**
   * Set the value of codigo_ibge
   *
   * @return  self
   */ 
  public function setCodigo_ibge($codigo_ibge)
  {
    $this->codigo_ibge = $codigo_ibge;

    return $this;
  }
}
