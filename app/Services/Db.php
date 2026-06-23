<?php
declare(strict_types=1);

namespace App\Services;

final class Db
{
  private static mixed $conn = null;

  public static function connect(): mixed
  {
    if (self::$conn) return self::$conn;

    $cfg = require __DIR__ . '/../../config/database.php';

    if (($cfg['driver'] ?? '') !== 'oracle') {
      throw new \RuntimeException('DB driver nije oracle');
    }

    if (!function_exists('oci_connect')) {
      throw new \RuntimeException('OCI8 nije dostupan. Provjeri OCI8 ekstenziju.');
    }

    $host    = $cfg['host']    ?? 'localhost';
    $port    = $cfg['port']    ?? '1521';
    $service = $cfg['service'] ?? 'XE';
    $dsn     = "(DESCRIPTION=(ADDRESS=(PROTOCOL=TCP)(HOST={$host})(PORT={$port}))(CONNECT_DATA=(SERVICE_NAME={$service})))";

    $user    = $cfg['username'] ?? '';
    $pass    = $cfg['password'] ?? '';
    $charset = $cfg['charset']  ?? 'AL32UTF8';

    $conn = @oci_connect($user, $pass, $dsn, $charset);
    if (!$conn) {
      $e   = oci_error();
      $msg = $e['message'] ?? 'Nepoznata OCI8 greska';
      throw new \RuntimeException('Oracle connect failed: ' . $msg);
    }

    self::$conn = $conn;
    return self::$conn;
  }

  public static function ping(): bool
  {
    $c = self::connect();
    $s = oci_parse($c, 'SELECT 1 AS ok FROM dual');
    if (!$s) return false;
    $ok = @oci_execute($s);
    @oci_free_statement($s);
    return (bool) $ok;
  }
}