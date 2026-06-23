<?php
declare(strict_types=1);

return [
  'name'  => 'labos',
  'env'   => getenv('APP_ENV') ?: 'local',
  'debug' => (bool)(getenv('APP_DEBUG') ?: false),
  'url'   => getenv('APP_URL') ?: 'http://localhost/labos/public',
];