<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

Env::load(__DIR__ . '/../.env');

$router = new Router();

$routes = require __DIR__ . '/../routes/web.php';
$routes($router);

$routes = require __DIR__ . '/../routes/api.php';
$routes($router);

$req = new Request();
$res = new Response();

$router->dispatch($req, $res);
