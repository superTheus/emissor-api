<?php

namespace App\Models;

use stdClass;

class FormaPagamentoModel extends Connection
{
  private $conn;
  private $codigo;
  private $descricao;
  private $cod_meio;
  private $meio;
  private $table = 'formas_pagtosefaz';

  public function __construct($codigo = null)
  {
    $this->conn = $this->openConnection();

    if ($codigo) {
      $this->setCodigo($codigo);
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

      $forma = $stmt->fetch(\PDO::FETCH_ASSOC);
      $this->setDescricao($forma['descricao']);
      $this->setCod_meio($forma['cod_meio']);
      $this->setMeio($forma['meio']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->codigo = $this->codigo;
    $data->descricao = $this->descricao;
    $data->cod_meio = $this->cod_meio;
    $data->meio = $this->meio;

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

  /**
   * Get the value of cod_meio
   */
  public function getCod_meio()
  {
    return $this->cod_meio;
  }

  /**
   * Set the value of cod_meio
   *
   * @return  self
   */
  public function setCod_meio($cod_meio)
  {
    $this->cod_meio = $cod_meio;

    return $this;
  }

  /**
   * Get the value of meio
   */
  public function getMeio()
  {
    return $this->meio;
  }

  /**
   * Set the value of meio
   *
   * @return  self
   */
  public function setMeio($meio)
  {
    $this->meio = $meio;

    return $this;
  }
}
