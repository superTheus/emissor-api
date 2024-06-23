<?php

namespace App\Controllers;

use App\Models\CompanyModel;

class CompanyController
{
  protected $companyModel;

  public function __construct($id = null)
  {
    $this->companyModel = new CompanyModel($id ? $id : null);
  }

  public function find($data)
  {
    try {
      $companyModel = new CompanyModel();
      $filter = $data && isset($data['filter']) ? $data['filter'] : null;
      $limit = $data && isset($data['limit']) ? $data['limit'] : null;
      $results = $companyModel->find($filter, $limit);

      if ($results) {
        foreach ($results as $key => $result) {
          $company = new CompanyModel($result['id']);
          $results[$key]['dados_certificado'] = $company->getCertificate();
        }
      }

      http_response_code(200);
      echo json_encode($results);
    } catch (\Exception $e) {
      http_response_code(500); // Internal Server Error
      echo json_encode(['error' => $e->getMessage()]);
    }
  }

  public function create($data)
  {
    if (isset($data['certificado'])) {
      if (base64_decode($data['certificado'], true) === false) {
        http_response_code(400); // Not Found
        echo json_encode(['error' => 'Certificado precisa ser uma string base64 válida']);
      } else {
        $data['certificado'] = $this->companyModel->uploadCertificado($data['certificado']);

        $isInvalid = $this->companyModel->validateCertificate($data['cnpj'], $data['senha'], $data['certificado']);

        if ($isInvalid) {
          http_response_code(400);
          echo json_encode(['error' => $isInvalid]);
        } else {
          $result = $this->companyModel->create($data);
          if ($result) {
            http_response_code(200);
            echo json_encode(array($result));
          } else {
            http_response_code(404);
            echo json_encode(['error' => 'Data not created successfully']);
          }
        }
      }
    } else {
      http_response_code(400);
      echo json_encode(['error' => 'Certificado não informado']);
    }
  }

  public function update($data)
  {
    $result = $this->companyModel->update($data);

    if ($result) {
      http_response_code(200); // OK
      echo json_encode($result);
    } else {
      http_response_code(404); // Not Found
      echo json_encode(['error' => 'Data not updated successfully']);
    }
  }
}
