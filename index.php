<?php

require_once './vendor/autoload.php';

// Configura timezone para América/Manaus (UTC-4)
date_default_timezone_set('America/Manaus');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization');
    header('HTTP/1.1 200 OK');
    exit;
}

//header("Content-type: application/json; charset=utf-8");
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, Content-Type, X-Auth-Token, Authorization');

use App\Routers\Routers;

try {
    // Run routes
    try {
        Routers::execute();
    } catch (Exception $ex) {
        echo "Error: " . $ex->getMessage();
    }
} catch (Exception $ex) {
    error_log($ex->getMessage());
}
