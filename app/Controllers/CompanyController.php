<?php

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Models\CompanyModel;
use App\Services\CompanyLogoStorage;

final class CompanyController
{
  private $id;
  private CompanyLogoStorage $logoStorage;

  public function __construct($id = null, ?CompanyLogoStorage $logoStorage = null)
  {
    $this->id = $id;
    $this->logoStorage = $logoStorage ?? new CompanyLogoStorage();
  }

  public function find($data): void
  {
    try {
      $data = is_array($data) ? $data : [];
      if (isset($data['filter']['cnpj'])) {
        $data['filter']['cnpj'] = UtilsController::soNumero($data['filter']['cnpj']);
      }
      $model = new CompanyModel();
      $results = $model->find($data['filter'] ?? [], $data['limit'] ?? null);

      $results = array_map(function (array $company): array {
        $companyModel = new CompanyModel($company['id']);
        $company['dados_certificado'] = $companyModel->getCertificate();

        return $this->presentCompany($company);
      }, $results);

      JsonResponse::send($results);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar empresas.', 500);
    }
  }

  public function create($data): void
  {
    $model = new CompanyModel();
    $uploadedCertificate = null;
    $uploadedLogo = null;

    try {
      $data = $this->requireArray($data);
      $this->requireFields($data, [
        'cnpj', 'razao_social', 'uf', 'codigo_municipio', 'codigo_uf',
        'crt', 'certificado', 'senha',
      ]);

      if (array_key_exists('logo', $data) && $data['logo'] !== null) {
        if (!is_string($data['logo'])) {
          throw new \InvalidArgumentException('Logo precisa ser uma string Base64 ou null.');
        }
        $uploadedLogo = $this->logoStorage->store($data['logo']);
        $data['logo'] = $uploadedLogo;
      }

      $uploadedCertificate = $this->storeAndValidateCertificate(
        $model,
        $data['certificado'],
        $data['cnpj'],
        $data['senha']
      );
      $data['certificado'] = $uploadedCertificate;

      $result = $model->create($data);
      JsonResponse::send($this->presentCompany((array) $result), 201);
    } catch (\InvalidArgumentException $exception) {
      if ($uploadedCertificate) {
        $model->removeCertificado($uploadedCertificate);
      }
      $this->logoStorage->remove($uploadedLogo);
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      if ($uploadedCertificate) {
        $model->removeCertificado($uploadedCertificate);
      }
      $this->logoStorage->remove($uploadedLogo);
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao criar a empresa.', 500);
    }
  }

  public function update($data): void
  {
    $uploadedCertificate = null;
    $uploadedLogo = null;

    try {
      if (!$this->id) {
        throw new \InvalidArgumentException('ID da empresa não informado.');
      }

      $data = $this->requireArray($data);
      $model = new CompanyModel($this->id);
      $previousCertificate = $model->getCertificado();
      $previousLogo = $model->getLogo();

      if (array_key_exists('logo', $data)) {
        if ($data['logo'] === null) {
          $data['logo'] = null;
        } elseif (!is_string($data['logo'])) {
          throw new \InvalidArgumentException('Logo precisa ser uma string Base64 ou null.');
        } else {
          $uploadedLogo = $this->logoStorage->store($data['logo']);
          $data['logo'] = $uploadedLogo;
        }
      }

      if (isset($data['certificado'])) {
        $uploadedCertificate = $this->storeAndValidateCertificate(
          $model,
          $data['certificado'],
          $data['cnpj'] ?? $model->getCnpj(),
          $data['senha'] ?? $model->getSenha()
        );
        $data['certificado'] = $uploadedCertificate;
      }

      // Identificadores e campos calculados não são alteráveis por esta rota.
      unset($data['id'], $data['cnpj'], $data['datahora'], $data['dados_certificado']);

      $result = $model->update($data);
      if ($uploadedCertificate && $previousCertificate !== $uploadedCertificate) {
        $model->removeCertificado($previousCertificate);
      }
      if (array_key_exists('logo', $data) && $previousLogo !== $uploadedLogo) {
        $this->logoStorage->remove($previousLogo);
      }
      JsonResponse::send($this->presentCompany((array) $result));
    } catch (\InvalidArgumentException $exception) {
      if ($uploadedCertificate && isset($model)) {
        $model->removeCertificado($uploadedCertificate);
      }
      $this->logoStorage->remove($uploadedLogo);
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\RuntimeException $exception) {
      if ($uploadedCertificate && isset($model)) {
        $model->removeCertificado($uploadedCertificate);
      }
      $this->logoStorage->remove($uploadedLogo);
      error_log($exception->getMessage());
      if ($exception->getMessage() === 'Empresa não encontrada.') {
        JsonResponse::error($exception->getMessage(), 404);
      } else {
        JsonResponse::error('Erro interno ao atualizar a empresa.', 500);
      }
    } catch (\Throwable $exception) {
      if ($uploadedCertificate && isset($model)) {
        $model->removeCertificado($uploadedCertificate);
      }
      $this->logoStorage->remove($uploadedLogo);
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao atualizar a empresa.', 500);
    }
  }

  private function storeAndValidateCertificate(
    CompanyModel $model,
    string $certificateBase64,
    string $cnpj,
    string $password
  ): string {
    if (strpos($certificateBase64, 'base64,') !== false) {
      $certificateBase64 = explode('base64,', $certificateBase64, 2)[1];
    }

    if (base64_decode($certificateBase64, true) === false) {
      throw new \InvalidArgumentException('Certificado precisa ser uma string base64 válida.');
    }

    $fileName = $model->uploadCertificado($certificateBase64);
    $validationError = $model->validateCertificate($cnpj, $password, $fileName);

    if ($validationError) {
      $model->removeCertificado($fileName);
      throw new \InvalidArgumentException($validationError);
    }

    return $fileName;
  }

  private function sanitizeCompany(array $company): array
  {
    foreach ([
      'senha', 'certificado', 'csc', 'csc_id', 'csc_homologacao', 'csc_id_homologacao',
    ] as $sensitiveField) {
      unset($company[$sensitiveField]);
    }

    return $company;
  }

  private function presentCompany(array $company): array
  {
    if (array_key_exists('dados_certificado', $company)) {
      $company['dados_certificado'] = $this->certificateSummary($company['dados_certificado']);
    }

    if (array_key_exists('logo', $company)) {
      $logo = $company['logo'];
      unset($company['logo']);
      $company['logo_url'] = is_string($logo) && $logo !== ''
        ? UtilsController::publicUrl('app/storage/logos/' . basename($logo))
        : null;
    }

    return $this->sanitizeCompany($company);
  }

  private function certificateSummary($certificate): ?array
  {
    if (!is_array($certificate)) {
      return null;
    }

    return [
      'titular' => $certificate['subject']['CN'] ?? null,
      'valido_de' => isset($certificate['validFrom_time_t'])
        ? date(DATE_ATOM, $certificate['validFrom_time_t'])
        : null,
      'valido_ate' => isset($certificate['validTo_time_t'])
        ? date(DATE_ATOM, $certificate['validTo_time_t'])
        : null,
    ];
  }

  private function requireArray($data): array
  {
    if (!is_array($data)) {
      throw new \InvalidArgumentException('O corpo da requisição precisa ser um objeto JSON.');
    }

    return $data;
  }

  private function requireFields(array $data, array $fields): void
  {
    foreach ($fields as $field) {
      if (!isset($data[$field]) || $data[$field] === '') {
        throw new \InvalidArgumentException("Campo obrigatório: {$field}");
      }
    }
  }
}
