<?php

namespace App\Routers;

use App\Controllers\CestController;
use App\Controllers\CfopController;
use App\Controllers\CompanyController;
use App\Controllers\CupomFiscalController;
use App\Controllers\EmissoesController;
use App\Controllers\FiscalController;
use App\Controllers\FormaPagamentoController;
use App\Controllers\IbptController;
use App\Controllers\NcmController;
use App\Controllers\OrigemController;
use App\Controllers\SituacaoTributariaController;
use App\Controllers\UtilsController;
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

      $router->post('/nfce', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $cupomfiscalController = new CupomFiscalController($data);
        $cupomfiscalController->createNfe();
      });

      $router->post('/emissoes', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $emissoesController = new EmissoesController();
        $emissoesController->find([
          "filter" => $data
        ]);
      });

      $router->mount('/certicate', function () use ($router) {
        $router->get('/', function () {
          echo 'Certificado';
        });

        $router->post('/test', function () {
          $data = json_decode(file_get_contents('php://input'), true);
          (new EmissoesController)->verifyCertificate($data['certificado'], $data['senha']);
        });

        $router->get('/test/{cnpj}', function ($cnpj) {
          UtilsController::testCertificate($cnpj);
        });
      });
    });

    $router->mount('/cest', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $cestController = new CestController();
        $cestController->find($data);
      });
    });

    $router->mount('/cfop', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $cfopController = new CfopController();
        $cfopController->find($data);
      });
    });

    $router->mount('/formas', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $formaPagamentoController = new FormaPagamentoController();
        $formaPagamentoController->find($data);
      });
    });

    $router->mount('/ibpt', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $ibptController = new IbptController();
        $ibptController->find($data);
      });
    });

    $router->mount('/ncm', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $ncmController = new NcmController();
        $ncmController->find($data);
      });
    });

    $router->mount('/origem', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $origemController = new OrigemController();
        $origemController->find($data);
      });
    });

    $router->mount('/situacao', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $situacaoTributariaController = new SituacaoTributariaController();
        $situacaoTributariaController->find($data);
      });
    });

    $router->run($callback);
  }
}
