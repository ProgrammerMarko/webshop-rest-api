<?php
declare(strict_types=1);

use App\Core\Router;
use App\Controllers\HomeController;

return function (Router $router): void {
  $c = new HomeController();

  $router->get('/',         [$c, 'index']);
  $router->get('/health',   [$c, 'health']);
  $router->get('/db-check', [$c, 'dbCheck']);
};
