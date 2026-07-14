<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class EstadosModel extends Connection
{
  use FindsByFilters;

  private $conn;
  private $id;
  private $nome;
  private $uf;
  private $codigo_ibge;
  private $table = 'estados';

  public function __construct($codigo = null)
  {
    $this->conn = $this->openConnection();

    if ($codigo) {
      $this->setId($codigo);
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
      if (!$cest) {
        throw new \RuntimeException('Estado não encontrado.');
      }

      $this->setNome($cest['nome']);
      $this->setUf($cest['uf']);
      $this->setCodigo_ibge($cest['codigo_ibge']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar o estado.', 0, $e);
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->id = $this->getId();
    $data->nome = $this->getNome();
    $data->uf = $this->getUf();
    $data->codigo_ibge = $this->getCodigo_ibge();
    return $data;
  }

  public function find($filter = [], $limit = null)
  {
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['id', 'nome', 'uf', 'codigo_ibge'];
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
   * Get the value of uf
   */ 
  public function getUf()
  {
    return $this->uf;
  }

  /**
   * Set the value of uf
   *
   * @return  self
   */ 
  public function setUf($uf)
  {
    $this->uf = $uf;

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
