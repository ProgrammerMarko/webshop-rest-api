<?php
declare(strict_types=1);

return [
  'session' => [
    'cookie_httponly' => true,
    'cookie_secure'   => (bool)(getenv('SESSION_SECURE') ?: false),
    'same_site'       => getenv('SESSION_SAMESITE') ?: 'Lax',
  ],
];