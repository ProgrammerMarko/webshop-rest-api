<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
  private array $routes = [];

  public function get(string $path, callable $handler): void
  {
    $this->routes['GET'][$path] = $handler;
  }

  public function post(string $path, callable $handler): void
  {
    $this->routes['POST'][$path] = $handler;
  }

  public function put(string $path, callable $handler): void
  {
    $this->routes['PUT'][$path] = $handler;
  }

  public function delete(string $path, callable $handler): void
  {
    $this->routes['DELETE'][$path] = $handler;
  }

  public function dispatch(Request $req, Response $res): void
  {
    $method  = $req->method();
    $path    = $req->path();
    $handler = $this->routes[$method][$path] ?? null;

    if (!$handler) {
      $res->text("404 Not Found: $method $path", 404);
      return;
    }

    $handler($req, $res);
  }
}