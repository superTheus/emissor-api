<?php

require_once __DIR__ . '/vendor/autoload.php';

// Configura timezone para América/Manaus (UTC-4)
date_default_timezone_set('America/Manaus');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization');
    header('HTTP/1.1 200 OK');
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization');

use App\Routers\Routers;
use App\Http\HttpException;
use App\Http\JsonResponse;

try {
    Routers::execute();
} catch (HttpException $exception) {
    JsonResponse::error($exception->getMessage(), $exception->status(), $exception->context());
} catch (InvalidArgumentException $exception) {
    JsonResponse::error($exception->getMessage(), 422);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    JsonResponse::error('Erro interno do servidor.', 500);
}
