<?php

namespace App\Controllers;

use App\Controllers\Fiscal\BaseFiscalController;
use App\Controllers\Fiscal\CRT1Controller;
use App\Controllers\Fiscal\CRT2Controller;
use App\Controllers\Fiscal\CRT3Controller;
use App\Controllers\Fiscal\CRT4Controller;
use App\Http\HttpException;
use App\Models\CompanyModel;

final class FiscalController
{
  private BaseFiscalController $handler;

  public function __construct($data = null)
  {
    if (!is_array($data) || $data === []) {
      throw new HttpException('Dados não fornecidos.', 400);
    }

    if (empty($data['cnpj'])) {
      throw new HttpException('CNPJ não informado.', 422);
    }

    $this->handler = $this->handlerFor($data);
  }

  public function createNfe(bool $preview = false)
  {
    return $this->handler->createNfe($preview);
  }

  public function cancelNfe(array $data)
  {
    return $this->handler->cancelNfe($data);
  }

  public function gerarCC(array $data)
  {
    return $this->handler->gerarCC($data);
  }

  private function handlerFor(array $data): BaseFiscalController
  {
    $crt = $this->resolveCrtByCnpj($data['cnpj']);

    return match ($crt) {
      1 => new CRT1Controller($data),
      2 => new CRT2Controller($data),
      3 => new CRT3Controller($data),
      4 => new CRT4Controller($data),
      default => throw new HttpException('CRT inválido ou não suportado.', 422, ['crt' => $crt]),
    };
  }

  private function resolveCrtByCnpj(string $cnpj): int
  {
    $companies = (new CompanyModel())->find([
      'cnpj' => UtilsController::soNumero($cnpj),
    ], 1);

    if ($companies === []) {
      throw new HttpException('Empresa não encontrada.', 404);
    }

    return (int) (new CompanyModel($companies[0]['id']))->getCrt();
  }
}
