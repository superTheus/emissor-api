<?php

namespace App\Routers;

use App\Controllers\CestController;
use App\Controllers\CfopController;
use App\Controllers\CompanyController;
use App\Controllers\CupomFiscalController;
use App\Controllers\EmissoesController;
use App\Controllers\EstadosController;
use App\Controllers\Fiscal\NotaServicoController;
use App\Controllers\FiscalController;
use App\Controllers\FormaPagamentoController;
use App\Controllers\IbptController;
use App\Controllers\MunicipiosController;
use App\Controllers\NcmController;
use App\Controllers\OrigemController;
use App\Controllers\SituacaoTributariaController;
use App\Controllers\UnidadesController;
use App\Controllers\UtilsController;
use App\Http\ApiTokenMiddleware;
use App\Http\JsonRequest;
use App\Http\JsonResponse;
use Bramus\Router\Router;

final class Routers
{
  public static function execute($callback = null): void
  {
    $router = new Router();

    $router->set404(static function (): void {
      JsonResponse::error('Rota não encontrada.', 404);
    });

    $router->before('GET', '/.*', static function (): void {
      header('X-Powered-By: bramus/router');
    });
    $router->before('GET|POST|PUT|PATCH|DELETE', '/.*', static function (): void {
      ApiTokenMiddleware::handle();
    });

    $router->get('/', static fn() => JsonResponse::send(['message' => 'Emissor API']));
    $router->post('/', static fn() => JsonResponse::send(['message' => 'Emissor API']));

    $router->mount('/company', static function () use ($router): void {
      $router->get('/', static fn() => JsonResponse::send(['resource' => 'company']));
      $router->post('/', static fn() => JsonResponse::send(['resource' => 'company']));

      $router->post('/list', static function (): void {
        (new CompanyController())->find(self::requestData());
      });

      $router->post('/create', static function (): void {
        (new CompanyController())->create(self::requestData());
      });

      $router->put('/update/{id}', static function ($id): void {
        (new CompanyController($id))->update(self::requestData());
      });
    });

    $router->mount('/fiscal', static function () use ($router): void {
      $router->get('/', static fn() => JsonResponse::send(['resource' => 'fiscal']));

      $router->mount('/nfe', static function () use ($router): void {
        $router->get('/', static fn() => JsonResponse::send(['resource' => 'nfe']));

        $router->post('/', static function (): void {
          (new FiscalController(self::requestData()))->createNfe();
        });

        $router->post('/cancel', static function (): void {
          $data = self::requestData();
          (new FiscalController($data))->cancelNfe($data);
        });

        $router->post('/carta', static function (): void {
          $data = self::requestData();
          (new FiscalController($data))->gerarCC($data);
        });

        $router->post('/preview', static function (): void {
          (new FiscalController(self::requestData()))->createNfe(true);
        });
      });

      $router->mount('/nfce', static function () use ($router): void {
        $router->post('/', static function (): void {
          (new CupomFiscalController(self::requestData()))->createNfe();
        });

        $router->post('/cancel', static function (): void {
          $data = self::requestData();
          (new CupomFiscalController($data))->cancelNfce($data);
        });
      });

      $router->mount('/nfse', static function () use ($router): void {
        $router->get('/', static fn() => JsonResponse::send(['resource' => 'nfse']));
        $router->post('/', static function (): void {
          (new NotaServicoController(self::requestData()))->emitir();
        });
      });

      $router->post('/emissoes', static function (): void {
        (new EmissoesController())->find(self::requestData());
      });

      $router->mount('/certicate', static function () use ($router): void {
        $router->get('/', static fn() => JsonResponse::send(['resource' => 'certificate']));

        $router->post('/test', static function (): void {
          $data = self::requestData();
          (new EmissoesController())->verifyCertificate(
            $data['certificado'] ?? null,
            $data['senha'] ?? null
          );
        });

        $router->get('/test/{cnpj}', static function ($cnpj): void {
          UtilsController::testCertificate($cnpj);
        });
      });
    });

    self::mountLookup($router, '/cest', CestController::class);
    self::mountLookup($router, '/cfop', CfopController::class);
    self::mountLookup($router, '/formas', FormaPagamentoController::class);
    self::mountLookup($router, '/ibpt', IbptController::class);
    self::mountLookup($router, '/ncm', NcmController::class);
    self::mountLookup($router, '/origem', OrigemController::class);
    self::mountLookup($router, '/situacao', SituacaoTributariaController::class);
    self::mountLookup($router, '/unidades', UnidadesController::class);

    $router->mount('/estados', static function () use ($router): void {
      $router->post('/', static function (): void {
        (new EstadosController())->find(self::requestData());
      });

      $router->post('/{uf}', static function ($uf): void {
        (new EstadosController())->find(['filter' => ['uf' => $uf]]);
      });

      $router->get('/{uf}', static function ($uf): void {
        (new EstadosController())->findunique(['filter' => ['uf' => $uf]]);
      });
    });

    $router->mount('/municipios', static function () use ($router): void {
      $router->post('/', static function (): void {
        (new MunicipiosController())->find(self::requestData());
      });

      $router->get('/{uf}/{cidade}', static function ($uf, $cidade): void {
        try {
          $estado = (new EstadosController())->findOnly([
            'filter' => ['uf' => $uf],
            'limit' => 1,
          ]);

          if (!$estado || !isset($estado['id'])) {
            JsonResponse::error('Estado não encontrado.', 404);
            return;
          }

          (new MunicipiosController())->findunique([
            'filter' => [
              'nome' => $cidade,
              'id_estado' => $estado['id'],
            ],
          ]);
        } catch (\Throwable $exception) {
          error_log($exception->getMessage());
          JsonResponse::error('Erro interno ao consultar o município.', 500);
        }
      });

      $router->post('/{uf}', static function ($uf): void {
        (new MunicipiosController())->findByUf($uf);
      });
    });

    $router->run($callback);
  }

  private static function requestData(): array
  {
    try {
      return JsonRequest::body();
    } catch (\InvalidArgumentException $exception) {
      JsonResponse::error($exception->getMessage(), 400);
      exit;
    }
  }

  private static function mountLookup(Router $router, string $path, string $controllerClass): void
  {
    $router->mount($path, static function () use ($router, $controllerClass): void {
      $router->post('/', static function () use ($controllerClass): void {
        (new $controllerClass())->find(self::requestData());
      });
    });
  }
}
