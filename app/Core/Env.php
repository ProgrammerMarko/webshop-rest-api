<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
  public static function load(string $path): void
  {
    if (!file_exists($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) continue;
      if (!str_contains($line, '=')) continue;

      [$key, $value] = explode('=', $line, 2);
      $key   = trim($key);
      $value = trim($value);

      if ($key === '') continue;

      putenv("$key=$value");
      $_ENV[$key]    = $value;
      $_SERVER[$key] = $value;
    }
  }
}