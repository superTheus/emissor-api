<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class IbptModel extends Connection
{
  use FindsByFilters;

  private $conn;
  private $codigo;
  private $nacional;
  private $importado;
  private $table = 'ibpt_nacional';

  public function __construct($id = null)
  {
    $this->conn = $this->openConnection();

    if ($id) {
      $this->setCodigo($id);
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

      $cest = $stmt->fetch(\PDO::FETCH_ASSOC);
      if (!$cest) {
        throw new \RuntimeException('IBPT não encontrado.');
      }
      $this->setNacional($cest['nacional']);
      $this->setImportado($cest['importado']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar o IBPT.', 0, $e);
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->codigo = $this->getCodigo();
    $data->nacional = $this->getNacional();
    $data->importado = $this->getImportado();

    return $data;
  }

  public function find($filter = [], $limit = null)
  {
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['codigo', 'nacional', 'importado'];
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
   * Get the value of nacional
   */
  public function getNacional()
  {
    return $this->nacional;
  }

  /**
   * Set the value of nacional
   *
   * @return  self
   */
  public function setNacional($nacional)
  {
    $this->nacional = $nacional;

    return $this;
  }

  /**
   * Get the value of importado
   */
  public function getImportado()
  {
    return $this->importado;
  }

  /**
   * Set the value of importado
   *
   * @return  self
   */
  public function setImportado($importado)
  {
    $this->importado = $importado;

    return $this;
  }
}
