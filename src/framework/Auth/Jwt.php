<?php
namespace WebApplication\Auth;

/** Minimal HS256 JSON Web Token encode/decode. */
class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        if ($secret === '') {
            throw new \RuntimeException('JWT secret is not configured');
        }
        $header = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $body = self::b64(json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', "$header.$body", $secret, true));
        return "$header.$body.$sig";
    }

    /** Payload if the signature is valid and the token is not expired, otherwise null. */
    public static function decode(string $token, string $secret): ?array
    {
        if ($secret === '') {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        list($header, $body, $sig) = $parts;
        $h = json_decode(self::unb64($header), true);
        if (!is_array($h) || ($h['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $expected = hash_hmac('sha256', "$header.$body", $secret, true);
        if (!hash_equals($expected, self::unb64($sig))) {
            return null;
        }
        $payload = json_decode(self::unb64($body), true);
        if (!is_array($payload) || self::isExpired($payload)) {
            return null;
        }
        return $payload;
    }

    public static function isExpired(array $payload): bool
    {
        return isset($payload['exp']) && time() > (int)$payload['exp'];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function unb64(string $data): string
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return (string)base64_decode(strtr($data, '-_', '+/'), true);
    }
}
