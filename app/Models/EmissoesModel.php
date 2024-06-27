<?php

namespace App\Models;

use App\Models\Connection;
use stdClass;

class EmissoesModel extends Connection
{
  private $conn;
  private $chave;
  private $numero;
  private $serie;
  private $empresa;
  private $xml;
  private $pdf;
  private $tipo;
  private $table = 'emissoes';

  public function __construct($chave = null)
  {
    $this->conn = $this->openConnection();

    if ($chave) {
      $this->setChave($chave);
      $this->getById();
    }
  }

  private function getById()
  {
    $sql = "SELECT * FROM {$this->table} WHERE chave = :chave";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':chave', $this->chave);
      $stmt->execute();

      $emissao = $stmt->fetch(\PDO::FETCH_ASSOC);
      $this->setNumero($emissao['numero']);
      $this->setSerie($emissao['serie']);
      $this->setEmpresa($emissao['empresa']);
      $this->setXml($emissao['xml']);
      $this->setPdf($emissao['pdf']);
      $this->setTipo($emissao['tipo']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->chave = $this->getChave();
    $data->numero = $this->getNumero();
    $data->serie = $this->getSerie();
    $data->empresa = $this->getEmpresa();
    $data->xml = $this->getXml();
    $data->pdf = $this->getPdf();
    $data->tipo = $this->getTipo();
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

      foreach ($filter as $key => $value) {
        $stmt->bindParam(":$key", $value);
      }

      if ($limit !== null) {
        $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
      }

      $stmt->execute();
      return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function create()
  {
    $sql = "INSERT INTO {$this->table} (chave, numero, serie, empresa, xml, pdf, tipo) 
            VALUES (:chave, :numero, :serie, :empresa, :xml, :pdf, :tipo)";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':chave', $this->chave);
      $stmt->bindParam(':numero', $this->numero);
      $stmt->bindParam(':serie', $this->serie);
      $stmt->bindParam(':empresa', $this->empresa);
      $stmt->bindParam(':xml', $this->xml);
      $stmt->bindParam(':pdf', $this->pdf);
      $stmt->bindParam(':tipo', $this->tipo);
      $stmt->execute();
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  /**
   * Get the value of chave
   */
  public function getChave()
  {
    return $this->chave;
  }

  /**
   * Set the value of chave
   *
   * @return  self
   */
  public function setChave($chave)
  {
    $this->chave = $chave;

    return $this;
  }

  /**
   * Get the value of numero
   */
  public function getNumero()
  {
    return $this->numero;
  }

  /**
   * Set the value of numero
   *
   * @return  self
   */
  public function setNumero($numero)
  {
    $this->numero = $numero;

    return $this;
  }

  /**
   * Get the value of serie
   */
  public function getSerie()
  {
    return $this->serie;
  }

  /**
   * Set the value of serie
   *
   * @return  self
   */
  public function setSerie($serie)
  {
    $this->serie = $serie;

    return $this;
  }

  /**
   * Get the value of empresa
   */
  public function getEmpresa()
  {
    return $this->empresa;
  }

  /**
   * Set the value of empresa
   *
   * @return  self
   */
  public function setEmpresa($empresa)
  {
    $this->empresa = $empresa;

    return $this;
  }

  /**
   * Get the value of xml
   */
  public function getXml()
  {
    return $this->xml;
  }

  /**
   * Set the value of xml
   *
   * @return  self
   */
  public function setXml($xml)
  {
    $this->xml = $xml;

    return $this;
  }

  /**
   * Get the value of pdf
   */
  public function getPdf()
  {
    return $this->pdf;
  }

  /**
   * Set the value of pdf
   *
   * @return  self
   */
  public function setPdf($pdf)
  {
    $this->pdf = $pdf;

    return $this;
  }

  /**
   * Get the value of tipo
   */
  public function getTipo()
  {
    return $this->tipo;
  }

  /**
   * Set the value of tipo
   *
   * @return  self
   */
  public function setTipo($tipo)
  {
    $this->tipo = $tipo;

    return $this;
  }
}
