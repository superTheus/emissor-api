<?php

namespace App\Routers;

use App\Controllers\CompanyController;
use App\Controllers\FiscalController;
use Bramus\Router\Router;

class Routers
{
  public static function execute($callback = null)
  {
    $router = new Router();

    $router->set404(function () {
      header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
      echo '404, Rota não encontrada';
    });

    $router->before('GET', '/.*', function () {
      header('X-Powered-By: bramus/router');
    });

    $router->get('/', function () {
      echo 'Home';
    });

    $router->post('/', function () {
      echo 'Home';
    });

    $router->mount('/company', function () use ($router) {
      $router->get('/', function () {
        echo 'Empresa';
      });

      $router->post('/', function () {
        echo 'Empresa';
      });

      $router->post('/list', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $userController = new CompanyController();
        $userController->find($data);
      });

      $router->post('/create', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $userController = new CompanyController();
        $userController->create($data);
      });

      $router->put('/update/{id}', function ($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $userController = new CompanyController($id);
        $userController->update($data);
      });
    });

    $router->mount('/fiscal', function () use ($router) {
      $router->get('/', function () {
        echo 'Fiscal';
      });

      $router->post('/nfe', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $fiscalController = new FiscalController($data['cnpj']);
        $fiscalController->createNfe();
      });

      $router->mount('/certicate', function () use ($router) {
        $router->get('/', function () {
          echo 'Certificado';
        });

        $router->get('/test/{cnpj}', function ($cnpj) {
          $fiscalController = new FiscalController();
          $fiscalController->testCertificate($cnpj);
        });
      });
    });

    $router->run($callback);
  }
}
