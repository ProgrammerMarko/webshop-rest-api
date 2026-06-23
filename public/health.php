<?php
declare(strict_types=1);

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
  foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k); $v = trim($v);
    if ($k !== '') { putenv("$k=$v"); $_ENV[$k] = $v; }
  }
}

$checks = [];

$checks['PHP'] = [
  'label' => 'PHP verzija',
  'ok'    => version_compare(PHP_VERSION, '8.1.0', '>='),
  'val'   => PHP_VERSION,
];

$checks['OCI8'] = [
  'label' => 'OCI8 ekstenzija',
  'ok'    => function_exists('oci_connect'),
  'val'   => function_exists('oci_connect') ? 'ucitana' : 'NIJE ucitana',
];

$autoload = __DIR__ . '/../vendor/autoload.php';
$checks['Autoloader'] = [
  'label' => 'Composer autoload',
  'ok'    => file_exists($autoload),
  'val'   => file_exists($autoload) ? 'vendor/ postoji' : 'NEDOSTAJE - pokreni composer install',
];

$env = __DIR__ . '/../.env';
$checks['Env'] = [
  'label' => '.env datoteka',
  'ok'    => file_exists($env),
  'val'   => file_exists($env) ? 'postoji' : 'NEDOSTAJE - kopiraj .env.example u .env',
];

if (function_exists('oci_connect') && file_exists($autoload)) {
  try {
    require $autoload;
    $t0 = microtime(true);
    $ok = \App\Services\Db::ping();
    $ms = round((microtime(true) - $t0) * 1000, 1);
    $checks['DB'] = [
      'label' => 'Oracle DB',
      'ok'    => $ok,
      'val'   => $ok ? "spojen ({$ms} ms)" : 'ping nije uspio',
    ];
  } catch (\Throwable $e) {
    $checks['DB'] = [
      'label' => 'Oracle DB',
      'ok'    => false,
      'val'   => $e->getMessage(),
    ];
  }
} else {
  $checks['DB'] = [
    'label' => 'Oracle DB',
    'ok'    => false,
    'val'   => 'preskoceno (OCI8 ili autoloader nedostupan)',
  ];
}

$allOk = array_reduce($checks, fn($c, $i) => $c && $i['ok'], true);
http_response_code($allOk ? 200 : 500);
?>
<!DOCTYPE html>
<html lang="hr">
<head>
  <meta charset="utf-8">
  <title>Health &mdash; labosi</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #f4f4f5; padding: 2rem; color: #18181b; }
    h1 { font-size: 1.4rem; margin-bottom: 1.5rem; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow: hidden; max-width: 600px; }
    .row { display: flex; align-items: center; gap: 1rem; padding: .85rem 1.2rem; border-bottom: 1px solid #f1f1f1; }
    .row:last-child { border-bottom: none; }
    .icon { font-size: 1.3rem; flex-shrink: 0; }
    .label { font-weight: 600; font-size: .9rem; min-width: 150px; }
    .val { font-size: .85rem; color: #52525b; word-break: break-word; }
    .ok   { background: #f0fdf4; }
    .fail { background: #fff1f2; }
    .banner { max-width: 600px; margin-bottom: 1rem; padding: .75rem 1.2rem; border-radius: 10px; font-weight: 700; font-size: 1rem; }
    .banner.ok   { background: #dcfce7; color: #15803d; }
    .banner.fail { background: #ffe4e6; color: #be123c; }
  </style>
</head>
<body>
  <h1>Health check &mdash; labosi</h1>
  <div class="banner <?= $allOk ? 'ok' : 'fail' ?>">
    <?= $allOk ? '&#x2705; Sve radi ispravno' : '&#x274C; Postoje problemi' ?>
  </div>
  <div class="card">
    <?php foreach ($checks as $check): ?>
    <div class="row <?= $check['ok'] ? 'ok' : 'fail' ?>">
      <span class="icon"><?= $check['ok'] ? '&#x2705;' : '&#x274C;' ?></span>
      <span class="label"><?= htmlspecialchars($check['label']) ?></span>
      <span class="val"><?= htmlspecialchars($check['val']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</body>
</html>