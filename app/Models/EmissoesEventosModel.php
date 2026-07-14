<?php

namespace App\Models;

use App\Models\Concerns\FindsByFilters;
use stdClass;

class EmissoesEventosModel extends Connection
{
  use FindsByFilters;

  private $id;
  private $conn;
  private $chave;
  private $tipo;
  private $protocolo;
  private $xml;
  private $link;
  private $table = 'emissoes_eventos';

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
    $sql = "SELECT * FROM {$this->table} WHERE chave = :chave ORDER BY id DESC LIMIT 1";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':chave', $this->chave);
      $stmt->execute();

      $emissao = $stmt->fetch(\PDO::FETCH_ASSOC);
      if (!$emissao) {
        throw new \RuntimeException('Evento fiscal não encontrado.');
      }
      $this->setId($emissao['id']);
      $this->setProtocolo($emissao['protocolo']);
      $this->setChave($emissao['chave']);
      $this->setXml($emissao['xml']);
      $this->setLink($emissao['link']);
      $this->setTipo($emissao['tipo']);
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao carregar o evento fiscal.', 0, $e);
    }
  }

  public function getCurrent()
  {
    $data = new stdClass();
    $data->chave = $this->getChave();
    $data->protocolo = $this->getProtocolo();
    $data->xml = $this->getXml();
    $data->tipo = $this->getTipo();
    $data->link = $this->getLink();
    return $data;
  }

  public function find($filter = [], $limit = null)
  {
    return $this->findByFilters($filter, $limit);
  }

  protected function filterableColumns(): array
  {
    return ['id', 'chave', 'tipo', 'protocolo'];
  }

  public function create()
  {
    $sql = "INSERT INTO {$this->table} (chave, protocolo, xml, tipo, link)
            VALUES (:chave, :protocolo, :xml, :tipo, :link)";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':chave', $this->chave);
      $stmt->bindParam(':protocolo', $this->protocolo);
      $stmt->bindParam(':xml', $this->xml);
      $stmt->bindParam(':tipo', $this->tipo);
      $stmt->bindParam(':link', $this->link);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao salvar o evento fiscal.', 0, $e);
    }
  }

  public function update($data = null)
  {
    $sql = "UPDATE {$this->table} SET protocolo = :protocolo, xml = :xml, tipo = :tipo, link = :link WHERE chave = :chave";

    if ($data) {
      foreach ($data as $column => $value) {
        $this->$column = $value;
      }
    }

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':chave', $this->chave);
      $stmt->bindParam(':protocolo', $this->protocolo);
      $stmt->bindParam(':xml', $this->xml);
      $stmt->bindParam(':tipo', $this->tipo);
      $stmt->bindParam(':link', $this->link);
      $stmt->execute();
    } catch (\PDOException $e) {
      throw new \RuntimeException('Erro ao atualizar o evento fiscal.', 0, $e);
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
   * Get the value of conn
   */
  public function getConn()
  {
    return $this->conn;
  }

  /**
   * Set the value of conn
   *
   * @return  self
   */
  public function setConn($conn)
  {
    $this->conn = $conn;

    return $this;
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

  /**
   * Get the value of protocolo
   */
  public function getProtocolo()
  {
    return $this->protocolo;
  }

  /**
   * Set the value of protocolo
   *
   * @return  self
   */
  public function setProtocolo($protocolo)
  {
    $this->protocolo = $protocolo;

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
   * Get the value of link
   */
  public function getLink()
  {
    return $this->link;
  }

  /**
   * Set the value of link
   *
   * @return  self
   */
  public function setLink($link)
  {
    $this->link = $link;

    return $this;
  }
}
