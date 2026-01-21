<?php

namespace App\Routers;

use App\Controllers\CestController;
use App\Controllers\CfopController;
use App\Controllers\CompanyController;
use App\Controllers\CupomFiscalController;
use App\Controllers\EmissoesController;
use App\Controllers\EstadosController;
use App\Controllers\FiscalController;
use App\Controllers\FormaPagamentoController;
use App\Controllers\IbptController;
use App\Controllers\MunicipiosController;
use App\Controllers\NcmController;
use App\Controllers\OrigemController;
use App\Controllers\SituacaoTributariaController;
use App\Controllers\UnidadesController;
use App\Controllers\UtilsController;
use Bramus\Router\Router;

header('Content-Type: application/json');

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

      $router->mount('/nfe', function () use ($router) {
        $router->get('/', function () {
          echo "Emitir NFe";
        });

        $router->post('/', function () {
          $data = json_decode(file_get_contents('php://input'), true);
          $fiscalController = new FiscalController($data);
          $fiscalController->createNfe();
        });

        $router->post('/cancel', function () {
          $data = json_decode(file_get_contents('php://input'), true);
          $cupomfiscalController = new FiscalController($data);
          $cupomfiscalController->cancelNfe($data);
        });

        $router->post('/carta', function () {
          $data = json_decode(file_get_contents('php://input'), true);
          $fiscalController = new FiscalController($data);
          $fiscalController->gerarCC($data);
        });
      });

      $router->mount('/nfce', function () use ($router) {
        $router->post('/', function () {
          $data = json_decode(file_get_contents('php://input'), true);
          $cupomfiscalController = new CupomFiscalController($data);
          $cupomfiscalController->createNfe();
        });

        $router->post('/cancel', function () {
          $data = json_decode(file_get_contents('php://input'), true);
          $cupomfiscalController = new CupomFiscalController($data);
          $cupomfiscalController->cancelNfce($data);
        });
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

        $router->get('/debug/{cnpj}', function ($cnpj) {
          UtilsController::debugCertificate($cnpj);
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

    $router->mount('/unidades', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $unidadesTributariaController = new UnidadesController();
        $unidadesTributariaController->find($data);
      });
    });

    $router->mount('/estados', function () use ($router) {
      $router->post('/', function () {
        $data = json_decode(file_get_contents('php://input'), true);
        $estadosController = new EstadosController();
        $estadosController->find($data);
      });

      $router->post('/{uf}', function ($uf) {
        $estadosController = new EstadosController();
        $estadosController->find([
          "filter" => ["uf" => $uf]
        ]);
      });

      $router->get('/{uf}', function ($uf) {
        $estadosController = new EstadosController();
        $estadosController->findunique([
          "filter" => ["uf" => $uf]
        ]);
      });
    });

    $router->mount('/municipios', function () use ($router) {
      $router->post('/{uf}', function ($uf) {
        $municipiosController = new MunicipiosController();
        $municipiosController->findByUf($uf);
      });

      $router->get('/{cidade}', function ($cidade) {
        $municipiosController = new MunicipiosController();
        $municipiosController->findunique([
          "filter" => ["nome" => $cidade]
        ]);
      });
    });

    $router->run($callback);
  }
}
