<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class MunicipioModel extends Connection
{
  use FindsByFilters;

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
      if (!$cest) {
        throw new \RuntimeException('Município não encontrado.');
      }
      $this->setId_estado($cest['id_estado']);
      $this->setNome($cest['nome']);
      $this->setCodigo_ibge($cest['codigo_ibge']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar o município.', 0, $e);
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
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['id', 'id_estado', 'nome', 'codigo_ibge'];
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
