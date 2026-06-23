<?php
declare(strict_types=1);

namespace App\Core;

final class Jwt
{
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function secret(): string
    {
        $s = (string) getenv('JWT_SECRET');
        if ($s === '') {
            throw new \RuntimeException('JWT_SECRET nije postavljen u .env');
        }
        return $s;
    }

    public static function encode(array $payload): string
    {
        $header  = self::b64url((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64url((string) json_encode($payload));
        $sig     = self::b64url(hash_hmac('sha256', "$header.$payload", self::secret(), true));

        return "$header.$payload.$sig";
    }

    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Neispravan format tokena');
        }

        [$header, $payload, $sig] = $parts;

        $expected = self::b64url(hash_hmac('sha256', "$header.$payload", self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            throw new \RuntimeException('Neispravan potpis tokena');
        }

        $data = json_decode(
            (string) base64_decode(strtr($payload, '-_', '+/')),
            true
        );

        if (!is_array($data)) {
            throw new \RuntimeException('Neispravan payload');
        }

        if (isset($data['exp']) && $data['exp'] < time()) {
            throw new \RuntimeException('Token je istekao');
        }

        return $data;
    }
}
