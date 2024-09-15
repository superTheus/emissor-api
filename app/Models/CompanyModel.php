<?php

namespace App\Models;

use App\Models\Connection;
use stdClass;

class CompanyModel extends Connection
{
  private $conn;
  private $id;
  private $cnpj;
  private $razao_social;
  private $nome_fantasia;
  private $telefone;
  private $email;
  private $cep;
  private $logradouro;
  private $numero;
  private $bairro;
  private $cidade;
  private $uf;
  private $cnae;
  private $inscricao_estadual;
  private $inscricao_municipal;
  private $atividade;
  private $certificado;
  private $senha;
  private $csc;
  private $csc_id;
  private $datahora;
  private $tpamb;
  private $serie_nfce;
  private $numero_nfce;
  private $serie_nfe;
  private $numero_nfe;
  private $serie_nfce_homologacao;
  private $numero_nfce_homologacao;
  private $serie_nfe_homologacao;
  private $numero_nfe_homologacao;
  private $csc_homologacao;
  private $csc_id_homologacao;
  private $codigo_municipio;
  private $codigo_uf;
  private $situacao_tributaria;
  private $table = 'empresa';

  function __construct($id = null)
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

      $company = $stmt->fetch(\PDO::FETCH_ASSOC);

      $this->setCnpj($company['cnpj']);
      $this->setRazao_social($company['razao_social']);
      $this->setNome_fantasia($company['nome_fantasia']);
      $this->setTelefone($company['telefone']);
      $this->setEmail($company['email']);
      $this->setCep($company['cep']);
      $this->setLogradouro($company['logradouro']);
      $this->setNumero($company['numero']);
      $this->setBairro($company['bairro']);
      $this->setCidade($company['cidade']);
      $this->setUf($company['uf']);
      $this->setCnae($company['cnae']);
      $this->setInscricao_estadual($company['inscricao_estadual']);
      $this->setInscricao_municipal($company['inscricao_municipal']);
      $this->setAtividade($company['atividade']);
      $this->setCertificado($company['certificado']);
      $this->setSenha($company['senha']);
      $this->setCsc($company['csc']);
      $this->setCsc_id($company['csc_id']);
      $this->setDatahora($company['datahora']);
      $this->setTpamb($company['tpamb']);
      $this->setSerie_nfce($company['serie_nfce']);
      $this->setNumero_nfce($company['numero_nfce']);
      $this->setSerie_nfe($company['serie_nfe']);
      $this->setNumero_nfe($company['numero_nfe']);
      $this->setCodigo_municipio($company['codigo_municipio']);
      $this->setCodigo_uf($company['codigo_uf']);
      $this->setSituacao_tributaria($company['situacao_tributaria']);
      $this->setSerie_nfce_homologacao($company['serie_nfce_homologacao']);
      $this->setNumero_nfce_homologacao($company['numero_nfce_homologacao']);
      $this->setSerie_nfe_homologacao($company['serie_nfe_homologacao']);
      $this->setNumero_nfe_homologacao($company['numero_nfe_homologacao']);
      $this->setCsc_homologacao($company['csc_homologacao']);
      $this->setCsc_id_homologacao($company['csc_id_homologacao']);
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCurrentCompany()
  {
    $data = new stdClass();
    $data->id = $this->getId();
    $data->cnpj = $this->getCnpj();
    $data->razao_social = $this->getRazao_social();
    $data->nome_fantasia = $this->getNome_fantasia();
    $data->telefone = $this->getTelefone();
    $data->email = $this->getEmail();
    $data->cep = $this->getCep();
    $data->logradouro = $this->getLogradouro();
    $data->numero = $this->getNumero();
    $data->bairro = $this->getBairro();
    $data->cidade = $this->getCidade();
    $data->uf = $this->getUf();
    $data->cnae = $this->getCnae();
    $data->inscricao_estadual = $this->getInscricao_estadual();
    $data->inscricao_municipal = $this->getInscricao_municipal();
    $data->atividade = $this->getAtividade();
    $data->certificado = $this->getCertificado();
    $data->senha = $this->getSenha();
    $data->csc = $this->getCsc();
    $data->csc_id = $this->getCsc_id();
    $data->datahora = $this->getDatahora();
    $data->tpamb = $this->getTpamb();
    $data->serie_nfce = $this->getSerie_nfce();
    $data->numero_nfce = $this->getNumero_nfce();
    $data->serie_nfe = $this->getSerie_nfe();
    $data->numero_nfe = $this->getNumero_nfe();
    $data->codigo_municipio = $this->getCodigo_municipio();
    $data->codigo_uf = $this->getCodigo_uf();
    $data->situacao_tributaria = $this->getSituacao_tributaria();
    $data->dados_certificado = $this->getCertificate();
    $data->serie_nfce_homologacao = $this->getSerie_nfce_homologacao();
    $data->numero_nfce_homologacao = $this->getNumero_nfce_homologacao();
    $data->serie_nfe_homologacao = $this->getSerie_nfe_homologacao();
    $data->numero_nfe_homologacao = $this->getNumero_nfe_homologacao();
    $data->csc_homologacao = $this->getCsc_homologacao();
    $data->csc_id_homologacao = $this->getCsc_id_homologacao();

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

  public function create($data)
  {
    $sql = "INSERT INTO {$this->table} (
      tpamb, cnpj, razao_social, nome_fantasia, telefone, email, cep, logradouro, numero, 
      bairro, cidade, uf, certificado, senha, csc, csc_id, serie_nfce, numero_nfce, 
      serie_nfe, numero_nfe, codigo_municipio, codigo_uf, situacao_tributaria, inscricao_estadual,
      csc_homologacao, csc_id_homologacao, serie_nfce_homologacao, numero_nfce_homologacao, serie_nfe_homologacao, numero_nfe_homologacao
    ) 
    VALUES (
      :tpamb, :cnpj, :razao_social, :nome_fantasia, :telefone, :email, :cep, :logradouro, :numero, 
      :bairro, :cidade, :uf, :certificado, :senha, :csc, :csc_id, :serie_nfce, :numero_nfce, 
      :serie_nfe, :numero_nfe, :codigo_municipio, :codigo_uf, :situacao_tributaria, :inscricao_estadual,
      :csc_homologacao, :csc_id_homologacao, :serie_nfce_homologacao, :numero_nfce_homologacao, :serie_nfe_homologacao, :numero_nfe_homologacao
    )";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':tpamb', $data['tpamb']);
      $stmt->bindParam(':cnpj', $data['cnpj']);
      $stmt->bindParam(':razao_social', $data['razao_social']);
      $stmt->bindParam(':nome_fantasia', $data['nome_fantasia']);
      $stmt->bindParam(':telefone', $data['telefone']);
      $stmt->bindParam(':email', $data['email']);
      $stmt->bindParam(':cep', $data['cep']);
      $stmt->bindParam(':logradouro', $data['logradouro']);
      $stmt->bindParam(':numero', $data['numero']);
      $stmt->bindParam(':bairro', $data['bairro']);
      $stmt->bindParam(':cidade', $data['cidade']);
      $stmt->bindParam(':uf', $data['uf']);
      $stmt->bindParam(':certificado', $data['certificado']);
      $stmt->bindParam(':senha', $data['senha']);
      $stmt->bindParam(':csc', $data['csc']);
      $stmt->bindParam(':csc_id', $data['csc_id']);
      $stmt->bindParam(':serie_nfce', $data['serie_nfce']);
      $stmt->bindParam(':numero_nfce', $data['numero_nfce']);
      $stmt->bindParam(':serie_nfe', $data['serie_nfe']);
      $stmt->bindParam(':numero_nfe', $data['numero_nfe']);
      $stmt->bindParam(':codigo_municipio', $data['codigo_municipio']);
      $stmt->bindParam(':codigo_uf', $data['codigo_uf']);
      $stmt->bindParam(':situacao_tributaria', $data['situacao_tributaria']);
      $stmt->bindParam(':inscricao_estadual', $data['inscricao_estadual']);
      $stmt->bindParam(':csc_homologacao', $data['csc_homologacao']);
      $stmt->bindParam(':csc_id_homologacao', $data['csc_id_homologacao']);
      $stmt->bindParam(':serie_nfce_homologacao', $data['serie_nfce_homologacao']);
      $stmt->bindParam(':numero_nfce_homologacao', $data['numero_nfce_homologacao']);
      $stmt->bindParam(':serie_nfe_homologacao', $data['serie_nfe_homologacao']);
      $stmt->bindParam(':numero_nfe_homologacao', $data['numero_nfe_homologacao']);

      $stmt->execute();

      $this->setId($this->conn->lastInsertId());
      $this->getById();
      return $this->getCurrentCompany();
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function update($data)
  {
    $sql = "UPDATE {$this->table} SET 
              tpamb = :tpamb,
              razao_social = :razao_social,
              nome_fantasia = :nome_fantasia,
              telefone = :telefone,
              email = :email,
              cep = :cep,
              logradouro = :logradouro,
              numero = :numero,
              bairro = :bairro,
              cidade = :cidade,
              uf = :uf,
              cnae = :cnae,
              inscricao_estadual = :inscricao_estadual,
              inscricao_municipal = :inscricao_municipal,
              atividade = :atividade,
              certificado = :certificado,
              senha = :senha,
              csc = :csc,
              csc_id = :csc_id,
              serie_nfce = :serie_nfce,
              numero_nfce = :numero_nfce,
              serie_nfe = :serie_nfe,
              numero_nfe = :numero_nfe,
              codigo_municipio = :codigo_municipio,
              codigo_uf = :codigo_uf,
              situacao_tributaria = :situacao_tributaria,
              serie_nfce_homologacao = :serie_nfce_homologacao,
              numero_nfce_homologacao = :numero_nfce_homologacao,
              serie_nfe_homologacao = :serie_nfe_homologacao,
              numero_nfe_homologacao = :numero_nfe_homologacao,
              csc_homologacao = :csc_homologacao,
              csc_id_homologacao = :csc_id_homologacao
            WHERE id = :id";


    foreach ($data as $column => $value) {
      $this->$column = $value;
    }

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':tpamb', $this->tpamb);
      $stmt->bindParam(':razao_social', $this->razao_social);
      $stmt->bindParam(':nome_fantasia', $this->nome_fantasia);
      $stmt->bindParam(':telefone', $this->telefone);
      $stmt->bindParam(':email', $this->email);
      $stmt->bindParam(':cep', $this->cep);
      $stmt->bindParam(':logradouro', $this->logradouro);
      $stmt->bindParam(':numero', $this->numero);
      $stmt->bindParam(':bairro', $this->bairro);
      $stmt->bindParam(':cidade', $this->cidade);
      $stmt->bindParam(':uf', $this->uf);
      $stmt->bindParam(':cnae', $this->cnae);
      $stmt->bindParam(':inscricao_estadual', $this->inscricao_estadual);
      $stmt->bindParam(':inscricao_municipal', $this->inscricao_municipal);
      $stmt->bindParam(':atividade', $this->atividade);
      $stmt->bindParam(':certificado', $this->certificado);
      $stmt->bindParam(':senha', $this->senha);
      $stmt->bindParam(':csc', $this->csc);
      $stmt->bindParam(':csc_id', $this->csc_id);
      $stmt->bindParam(':serie_nfce', $this->serie_nfce);
      $stmt->bindParam(':numero_nfce', $this->numero_nfce);
      $stmt->bindParam(':serie_nfe', $this->serie_nfe);
      $stmt->bindParam(':numero_nfe', $this->numero_nfe);
      $stmt->bindParam(':codigo_municipio', $this->codigo_municipio);
      $stmt->bindParam(':codigo_uf', $this->codigo_uf);
      $stmt->bindParam(':situacao_tributaria', $this->situacao_tributaria);
      $stmt->bindParam(':serie_nfce_homologacao', $this->serie_nfce_homologacao);
      $stmt->bindParam(':numero_nfce_homologacao', $this->numero_nfce_homologacao);
      $stmt->bindParam(':serie_nfe_homologacao', $this->serie_nfe_homologacao);
      $stmt->bindParam(':numero_nfe_homologacao', $this->numero_nfe_homologacao);
      $stmt->bindParam(':csc_homologacao', $this->csc_homologacao);
      $stmt->bindParam(':csc_id_homologacao', $this->csc_id_homologacao);
      $stmt->bindParam(':id', $this->id);
      $stmt->execute();

      $this->getById();
      return $this->getCurrentCompany();
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function delete()
  {
    $sql = "DELETE FROM {$this->table} WHERE id = :id";

    try {
      $stmt = $this->conn->prepare($sql);
      $stmt->bindParam(':id', $this->id);
      $stmt->execute();
    } catch (\PDOException $e) {
      echo $e->getMessage();
    }
  }

  public function getCertificate()
  {
    $folderPath = "app/storage/certificados";
    $certificadoPath = $folderPath . "/" . $this->getCertificado();

    if (file_exists($certificadoPath)) {
      $certificado = file_get_contents($certificadoPath);
      $certInfo = openssl_pkcs12_read($certificado, $certs, $this->getSenha());

      if ($certInfo) {
        return openssl_x509_parse($certs['cert']);
      } else {
        return null;
      }
    } else {
      return null;
    }
  }

  public function uploadCertificado($certificado)
  {
    $certificado = base64_decode($certificado);

    $folderPath = "app/storage/certificados";
    $fileName = "certificado_" . uniqid() . ".pfx";

    if (!file_exists($folderPath)) {
      mkdir($folderPath, 0777, true);
    }

    file_put_contents($folderPath . "/" . $fileName, $certificado);

    return $fileName;
  }

  public function validateCertificate($cnpj, $senha, $certificadoNome)
  {
    $folderPath = "app/storage/certificados";
    $certificadoPath = $folderPath . "/" . $certificadoNome;

    if (file_exists($certificadoPath)) {
      $certificado = file_get_contents($certificadoPath);
      $certInfo = openssl_pkcs12_read($certificado, $certs, $senha);

      if ($certInfo) {
        $data = openssl_x509_parse($certs['cert']);
        $data = json_encode($data);
        $data = json_decode($data);

        list($nome, $documento) = explode(":", $data->subject->CN);

        $dt_vencimento = date('Y-m-d', $data->validTo_time_t);
        $dt_atual = date('Y-m-d');

        if ($documento == $cnpj) {
          if ($dt_atual <= $dt_vencimento) {
            return false;
          } else {
            return "Certificado vencido";
          }
        } else {
          return "CNPJ do certificado é diferente do CNPJ informado";
        }
      } else {
        $error = '';

        while ($msg = openssl_error_string()) {
          $error .= $msg . "\n";
        }
        return $error;
      }
    } else {
      return "Certificado não encontrado";
    }
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
   * Get the value of cnpj
   */
  public function getCnpj()
  {
    return $this->cnpj;
  }

  /**
   * Set the value of cnpj
   *
   * @return  self
   */
  public function setCnpj($cnpj)
  {
    $this->cnpj = $cnpj;

    return $this;
  }

  /**
   * Get the value of razao_social
   */
  public function getRazao_social()
  {
    return $this->razao_social;
  }

  /**
   * Set the value of razao_social
   *
   * @return  self
   */
  public function setRazao_social($razao_social)
  {
    $this->razao_social = $razao_social;

    return $this;
  }

  /**
   * Get the value of nome_fantasia
   */
  public function getNome_fantasia()
  {
    return $this->nome_fantasia;
  }

  /**
   * Set the value of nome_fantasia
   *
   * @return  self
   */
  public function setNome_fantasia($nome_fantasia)
  {
    $this->nome_fantasia = $nome_fantasia;

    return $this;
  }

  /**
   * Get the value of telefone
   */
  public function getTelefone()
  {
    return $this->telefone;
  }

  /**
   * Set the value of telefone
   *
   * @return  self
   */
  public function setTelefone($telefone)
  {
    $this->telefone = $telefone;

    return $this;
  }

  /**
   * Get the value of email
   */
  public function getEmail()
  {
    return $this->email;
  }

  /**
   * Set the value of email
   *
   * @return  self
   */
  public function setEmail($email)
  {
    $this->email = $email;

    return $this;
  }

  /**
   * Get the value of cep
   */
  public function getCep()
  {
    return $this->cep;
  }

  /**
   * Set the value of cep
   *
   * @return  self
   */
  public function setCep($cep)
  {
    $this->cep = $cep;

    return $this;
  }

  /**
   * Get the value of logradouro
   */
  public function getLogradouro()
  {
    return $this->logradouro;
  }

  /**
   * Set the value of logradouro
   *
   * @return  self
   */
  public function setLogradouro($logradouro)
  {
    $this->logradouro = $logradouro;

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
   * Get the value of bairro
   */
  public function getBairro()
  {
    return $this->bairro;
  }

  /**
   * Set the value of bairro
   *
   * @return  self
   */
  public function setBairro($bairro)
  {
    $this->bairro = $bairro;

    return $this;
  }

  /**
   * Get the value of cidade
   */
  public function getCidade()
  {
    return $this->cidade;
  }

  /**
   * Set the value of cidade
   *
   * @return  self
   */
  public function setCidade($cidade)
  {
    $this->cidade = $cidade;

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
   * Get the value of cnae
   */
  public function getCnae()
  {
    return $this->cnae;
  }

  /**
   * Set the value of cnae
   *
   * @return  self
   */
  public function setCnae($cnae)
  {
    $this->cnae = $cnae;

    return $this;
  }

  /**
   * Get the value of inscricao_estadual
   */
  public function getInscricao_estadual()
  {
    return $this->inscricao_estadual;
  }

  /**
   * Set the value of inscricao_estadual
   *
   * @return  self
   */
  public function setInscricao_estadual($inscricao_estadual)
  {
    $this->inscricao_estadual = $inscricao_estadual;

    return $this;
  }

  /**
   * Get the value of inscricao_municipal
   */
  public function getInscricao_municipal()
  {
    return $this->inscricao_municipal;
  }

  /**
   * Set the value of inscricao_municipal
   *
   * @return  self
   */
  public function setInscricao_municipal($inscricao_municipal)
  {
    $this->inscricao_municipal = $inscricao_municipal;

    return $this;
  }

  /**
   * Get the value of atividade
   */
  public function getAtividade()
  {
    return $this->atividade;
  }

  /**
   * Set the value of atividade
   *
   * @return  self
   */
  public function setAtividade($atividade)
  {
    $this->atividade = $atividade;

    return $this;
  }

  /**
   * Get the value of certificado
   */
  public function getCertificado()
  {
    return $this->certificado;
  }

  /**
   * Set the value of certificado
   *
   * @return  self
   */
  public function setCertificado($certificado)
  {
    $this->certificado = $certificado;

    return $this;
  }

  /**
   * Get the value of senha
   */
  public function getSenha()
  {
    return $this->senha;
  }

  /**
   * Set the value of senha
   *
   * @return  self
   */
  public function setSenha($senha)
  {
    $this->senha = $senha;

    return $this;
  }

  /**
   * Get the value of csc
   */
  public function getCsc()
  {
    return $this->csc;
  }

  /**
   * Set the value of csc
   *
   * @return  self
   */
  public function setCsc($csc)
  {
    $this->csc = $csc;

    return $this;
  }

  /**
   * Get the value of csc_id
   */
  public function getCsc_id()
  {
    return $this->csc_id;
  }

  /**
   * Set the value of csc_id
   *
   * @return  self
   */
  public function setCsc_id($csc_id)
  {
    $this->csc_id = $csc_id;

    return $this;
  }

  /**
   * Get the value of datahora
   */
  public function getDatahora()
  {
    return $this->datahora;
  }

  /**
   * Set the value of datahora
   *
   * @return  self
   */
  public function setDatahora($datahora)
  {
    $this->datahora = $datahora;

    return $this;
  }

  /**
   * Get the value of tpamb
   */
  public function getTpamb()
  {
    return $this->tpamb;
  }

  /**
   * Set the value of tpamb
   *
   * @return  self
   */
  public function setTpamb($tpamb)
  {
    $this->tpamb = $tpamb;

    return $this;
  }

  /**
   * Get the value of serie_nfce
   */
  public function getSerie_nfce()
  {
    return $this->serie_nfce;
  }

  /**
   * Set the value of serie_nfce
   *
   * @return  self
   */
  public function setSerie_nfce($serie_nfce)
  {
    $this->serie_nfce = $serie_nfce;

    return $this;
  }

  /**
   * Get the value of numero_nfce
   */
  public function getNumero_nfce()
  {
    return $this->numero_nfce;
  }

  /**
   * Set the value of numero_nfce
   *
   * @return  self
   */
  public function setNumero_nfce($numero_nfce)
  {
    $this->numero_nfce = $numero_nfce;

    return $this;
  }

  /**
   * Get the value of serie_nfe
   */
  public function getSerie_nfe()
  {
    return $this->serie_nfe;
  }

  /**
   * Set the value of serie_nfe
   *
   * @return  self
   */
  public function setSerie_nfe($serie_nfe)
  {
    $this->serie_nfe = $serie_nfe;

    return $this;
  }

  /**
   * Get the value of numero_nfe
   */
  public function getNumero_nfe()
  {
    return $this->numero_nfe;
  }

  /**
   * Set the value of numero_nfe
   *
   * @return  self
   */
  public function setNumero_nfe($numero_nfe)
  {
    $this->numero_nfe = $numero_nfe;

    return $this;
  }

  /**
   * Get the value of codigo_municipio
   */
  public function getCodigo_municipio()
  {
    return $this->codigo_municipio;
  }

  /**
   * Set the value of codigo_municipio
   *
   * @return  self
   */
  public function setCodigo_municipio($codigo_municipio)
  {
    $this->codigo_municipio = $codigo_municipio;

    return $this;
  }

  /**
   * Get the value of codigo_uf
   */
  public function getCodigo_uf()
  {
    return $this->codigo_uf;
  }

  /**
   * Set the value of codigo_uf
   *
   * @return  self
   */
  public function setCodigo_uf($codigo_uf)
  {
    $this->codigo_uf = $codigo_uf;

    return $this;
  }

  /**
   * Get the value of situacao_tributaria
   */
  public function getSituacao_tributaria()
  {
    return $this->situacao_tributaria;
  }

  /**
   * Set the value of situacao_tributaria
   *
   * @return  self
   */
  public function setSituacao_tributaria($situacao_tributaria)
  {
    $this->situacao_tributaria = $situacao_tributaria;

    return $this;
  }

  /**
   * Get the value of serie_nfce_homologacao
   */
  public function getSerie_nfce_homologacao()
  {
    return $this->serie_nfce_homologacao;
  }

  /**
   * Set the value of serie_nfce_homologacao
   *
   * @return  self
   */
  public function setSerie_nfce_homologacao($serie_nfce_homologacao)
  {
    $this->serie_nfce_homologacao = $serie_nfce_homologacao;

    return $this;
  }

  /**
   * Get the value of numero_nfce_homologacao
   */
  public function getNumero_nfce_homologacao()
  {
    return $this->numero_nfce_homologacao;
  }

  /**
   * Set the value of numero_nfce_homologacao
   *
   * @return  self
   */
  public function setNumero_nfce_homologacao($numero_nfce_homologacao)
  {
    $this->numero_nfce_homologacao = $numero_nfce_homologacao;

    return $this;
  }

  /**
   * Get the value of serie_nfe_homologacao
   */
  public function getSerie_nfe_homologacao()
  {
    return $this->serie_nfe_homologacao;
  }

  /**
   * Set the value of serie_nfe_homologacao
   *
   * @return  self
   */
  public function setSerie_nfe_homologacao($serie_nfe_homologacao)
  {
    $this->serie_nfe_homologacao = $serie_nfe_homologacao;

    return $this;
  }

  /**
   * Get the value of numero_nfe_homologacao
   */
  public function getNumero_nfe_homologacao()
  {
    return $this->numero_nfe_homologacao;
  }

  /**
   * Set the value of numero_nfe_homologacao
   *
   * @return  self
   */
  public function setNumero_nfe_homologacao($numero_nfe_homologacao)
  {
    $this->numero_nfe_homologacao = $numero_nfe_homologacao;

    return $this;
  }

  /**
   * Get the value of csc_homologacao
   */
  public function getCsc_homologacao()
  {
    return $this->csc_homologacao;
  }

  /**
   * Set the value of csc_homologacao
   *
   * @return  self
   */
  public function setCsc_homologacao($csc_homologacao)
  {
    $this->csc_homologacao = $csc_homologacao;

    return $this;
  }

  /**
   * Get the value of csc_id_homologacao
   */
  public function getCsc_id_homologacao()
  {
    return $this->csc_id_homologacao;
  }

  /**
   * Set the value of csc_id_homologacao
   *
   * @return  self
   */
  public function setCsc_id_homologacao($csc_id_homologacao)
  {
    $this->csc_id_homologacao = $csc_id_homologacao;

    return $this;
  }
}
