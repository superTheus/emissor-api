<?php

namespace App\Controllers;

use App\Controllers\Fiscal\CRT1Controller;
use App\Controllers\Fiscal\CRT2Controller;
use App\Controllers\Fiscal\CRT3Controller;
use App\Controllers\Fiscal\CRT4Controller;
use App\Models\CompanyModel;
use Dotenv\Dotenv;

class FiscalController
{
  private $data;
  private $crt;
  private $handler;

  public function __construct($data = null)
  {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
    $dotenv->load();

    if (!$data) {
      http_response_code(400);
      echo json_encode(['error' => 'Dados não fornecidos']);
      exit;
    }

    if (!isset($data['cnpj']) || empty($data['cnpj'])) {
      http_response_code(400);
      echo json_encode(['error' => 'CNPJ não informado']);
      exit;
    }

    $this->data = $data;

    $crt = $this->resolveCrtByCnpj($data['cnpj']);
    if ($crt === null) {
      http_response_code(404);
      echo json_encode(['error' => 'Empresa não encontrada']);
      exit;
    }

    $this->crt = $crt;
    $this->handler = $this->makeHandlerByCrt($this->crt, $this->data);
  }

  private function resolveCrtByCnpj($cnpj)
  {
    $companyModel = new CompanyModel();
    $company = $companyModel->find([
      "cnpj" => UtilsController::soNumero($cnpj)
    ]);

    if (!$company) {
      return null;
    }

    $companyObj = new CompanyModel($company[0]['id']);
    return (int)$companyObj->getCrt();
  }

  private function makeHandlerByCrt($crt, $data)
  {
    switch ((int)$crt) {
      case 1:
        return new CRT1Controller($data);
      case 2:
        return new CRT2Controller($data);
      case 3:
        return new CRT3Controller($data);
      case 4:
        return new CRT4Controller($data);
      default:
        http_response_code(400);
        echo json_encode([
          'error' => 'CRT inválido ou não suportado',
          'crt' => $crt
        ]);
        exit;
    }
  }

  private function handlerFromRequestData($data)
  {
    if (!isset($data['cnpj']) || empty($data['cnpj'])) {
      http_response_code(400);
      echo json_encode(['error' => 'CNPJ não informado']);
      return null;
    }

    $crt = $this->resolveCrtByCnpj($data['cnpj']);
    if ($crt === null) {
      http_response_code(404);
      echo json_encode(['error' => 'Empresa não encontrada']);
      return null;
    }

    return $this->makeHandlerByCrt($crt, $data);
  }

  public function createNfe()
  {
    return $this->handler->createNfe();
  }

  public function cancelNfe($data)
  {
    // Para cancelamento, o payload geralmente é diferente da emissão.
    // Então resolvemos o handler baseado no CNPJ do payload.
    $handler = $this->handlerFromRequestData($data);
    if (!$handler) {
      return;
    }

    return $handler->cancelNfe($data);
  }

  public function gerarCC($data)
  {
    $handler = $this->handlerFromRequestData($data);
    if (!$handler) {
      return;
    }

    return $handler->gerarCC($data);
  }
}
