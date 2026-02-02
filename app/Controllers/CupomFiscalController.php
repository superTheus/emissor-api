<?php

namespace App\Controllers;

use App\Controllers\CupomFiscal\CRT1CupomController;
use App\Controllers\CupomFiscal\CRT2CupomController;
use App\Controllers\CupomFiscal\CRT3CupomController;
use App\Controllers\CupomFiscal\CRT4CupomController;
use App\Models\CompanyModel;
use Dotenv\Dotenv;

class CupomFiscalController
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
        return new CRT1CupomController($data);
      case 2:
        return new CRT2CupomController($data);
      case 3:
        return new CRT3CupomController($data);
      case 4:
        return new CRT4CupomController($data);
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

  public function cancelNfce($data)
  {
    $handler = $this->handlerFromRequestData($data);
    if (!$handler) {
      return;
    }

    return $handler->cancelNfce($data);
  }
}
