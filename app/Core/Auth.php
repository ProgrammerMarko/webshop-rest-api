<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function guard(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            throw new \RuntimeException('Token nedostaje ili nije Bearer format');
        }

        $token = substr($header, 7);
        $data  = Jwt::decode($token);

        $conn = \App\Services\Db::connect();
        $sql  = 'SELECT COUNT(*) AS cnt FROM token_blacklist WHERE token = :token';
        $stmt = oci_parse($conn, $sql);
        oci_bind_by_name($stmt, ':token', $token);
        oci_execute($stmt);
        $red = oci_fetch_assoc($stmt);
        oci_free_statement($stmt);

        if ((int) ($red['CNT'] ?? 0) > 0) {
            throw new \RuntimeException('Token je poništen');
        }

        return $data;
    }
}
