<?php

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Models\EmissoesModel;

final class EmissoesController
{
  public function find($data): void
  {
    try {
      $data = is_array($data) ? $data : [];
      $hasEnvelope = array_key_exists('filter', $data) || array_key_exists('limit', $data);
      $filter = $hasEnvelope ? ($data['filter'] ?? []) : $data;
      $limit = $hasEnvelope ? ($data['limit'] ?? null) : null;

      $results = (new EmissoesModel())->find($filter, $limit);
      JsonResponse::send($results);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao consultar emissões.', 500);
    }
  }

  public function verifyCertificate($certificadoBase64, $senha): void
  {
    try {
      if (!is_string($certificadoBase64) || $certificadoBase64 === '' || !is_string($senha)) {
        throw new \InvalidArgumentException('Certificado e senha são obrigatórios.');
      }

      if (strpos($certificadoBase64, 'base64,') !== false) {
        $certificadoBase64 = explode('base64,', $certificadoBase64, 2)[1];
      }

      $certificateContent = base64_decode($certificadoBase64, true);
      if ($certificateContent === false) {
        throw new \InvalidArgumentException('Falha ao decodificar o certificado base64.');
      }

      $certificates = UtilsController::openCertificate($certificateContent, $senha);
      if (!$certificates) {
        throw new \InvalidArgumentException('Senha incorreta ou certificado inválido.');
      }

      $certificate = openssl_x509_parse($certificates['cert']);
      if ($certificate === false) {
        throw new \InvalidArgumentException('Não foi possível analisar o certificado.');
      }

      $now = time();
      $validFrom = (int) ($certificate['validFrom_time_t'] ?? 0);
      $validTo = (int) ($certificate['validTo_time_t'] ?? 0);
      if ($validFrom === 0 || $validTo === 0 || $now < $validFrom || $now > $validTo) {
        throw new \InvalidArgumentException('Certificado expirado ou ainda não válido.');
      }

      $commonName = $certificate['subject']['CN'] ?? '';
      $parts = explode(':', $commonName);
      $document = UtilsController::soNumero(end($parts) ?: '');
      $company = count($parts) > 1 ? implode(':', array_slice($parts, 0, -1)) : $commonName;

      JsonResponse::send([
        'empresa' => $company,
        'cnpj' => $document,
        'valido_de' => date(DATE_ATOM, $validFrom),
        'valido_ate' => date(DATE_ATOM, $validTo),
      ]);
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 422);
    } catch (\Throwable $exception) {
      error_log($exception->getMessage());
      JsonResponse::error('Erro interno ao validar o certificado.', 500);
    }
  }
}
