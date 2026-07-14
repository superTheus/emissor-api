<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class CestModel extends Connection
{
  use FindsByFilters;

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
      if (!$cest) {
        throw new \RuntimeException('CEST não encontrado.');
      }
      $this->setNcm_id($cest['ncm_id']);
      $this->setDescricao($cest['descricao']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar o CEST.', 0, $e);
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
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['cest_id', 'ncm_id', 'descricao'];
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
