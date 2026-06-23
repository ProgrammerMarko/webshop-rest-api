<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
  public function method(): string
  {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  }

  public function path(): string
  {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?? '/';

    $pos = strpos($path, '/public');
    if ($pos !== false) {
      $path = substr($path, $pos + strlen('/public'));
      if ($path === '') $path = '/';
    }
    return $path;
  }

  public function query(string $key, mixed $default = null): mixed
  {
    return $_GET[$key] ?? $default;
  }

  public function input(string $key, mixed $default = null): mixed
  {
    return $_POST[$key] ?? $default;
  }

  public function body(): ?array
  {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
  }
}
