<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class NcmModel extends Connection
{
  use FindsByFilters;

  private $conn;
  private $id;
  private $codigo;
  private $descricao;
  private $table = 'ncm';

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

      $ncm = $stmt->fetch(\PDO::FETCH_ASSOC);
      if (!$ncm) {
        throw new \RuntimeException('NCM não encontrado.');
      }
      $this->setId($ncm['id']);
      $this->setCodigo($ncm['codigo']);
      $this->setDescricao($ncm['descricao']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar o NCM.', 0, $e);
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->id = $this->getId();
    $data->codigo = $this->getCodigo();
    $data->descricao = $this->getDescricao();

    return $data;
  }

  public function find($filter = [], $limit = null)
  {
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['id', 'codigo', 'descricao'];
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
}
