<?php

declare(strict_types=1);

// Prima installazione: senza .env non c'e' database da interrogare, quindi
// si passa dalla procedura guidata (finche' e' presente sul server).
if (!is_file(__DIR__ . '/../.env') && is_dir(__DIR__ . '/install')) {
    header('Location: /install/');
    exit;
}

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    http_response_code(500);
    exit('Dipendenze non installate: esegui "composer update" nella root del progetto.');
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use App\Core\Router;

session_start();

$router = new Router();
require __DIR__ . '/../app/routes.php';

$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'
);
