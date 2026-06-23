<?php
declare(strict_types=1);

return [
  'driver'   => $_ENV['DB_DRIVER']   ?? getenv('DB_DRIVER')   ?: 'oracle',
  'host'     => $_ENV['DB_HOST']     ?? getenv('DB_HOST')     ?: 'localhost',
  'port'     => $_ENV['DB_PORT']     ?? getenv('DB_PORT')     ?: '1521',
  'service'  => $_ENV['DB_SERVICE']  ?? getenv('DB_SERVICE')  ?: 'XE',
  'username' => $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: '',
  'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
  'charset'  => $_ENV['DB_CHARSET']  ?? getenv('DB_CHARSET')  ?: 'AL32UTF8',
];
