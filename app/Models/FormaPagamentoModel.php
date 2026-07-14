<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class FormaPagamentoModel extends Connection
{
  use FindsByFilters;

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
      if (!$forma) {
        throw new \RuntimeException('Forma de pagamento não encontrada.');
      }
      $this->setDescricao($forma['descricao']);
      $this->setCod_meio($forma['cod_meio']);
      $this->setMeio($forma['meio']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar a forma de pagamento.', 0, $e);
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
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['codigo', 'descricao', 'cod_meio', 'meio'];
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
