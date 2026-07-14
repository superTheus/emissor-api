<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class OrigemModel extends Connection
{
  use FindsByFilters;

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
      if (!$origem) {
        throw new \RuntimeException('Origem não encontrada.');
      }
      $this->setId($origem['id']);
      $this->setDescricao($origem['descricao']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar a origem.', 0, $e);
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
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['id', 'descricao'];
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
