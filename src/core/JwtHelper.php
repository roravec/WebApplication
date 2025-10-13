<?php
class JwtUtils
{
    public static function encode(array $payload, string $secret): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($payload)));
        $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $secret, true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        return "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
    }

    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        list($header, $payload, $signature) = $parts;
        $decodedPayload = base64_decode(str_pad(strtr($payload, '-_', '+/'), strlen($payload) % 4, '=', STR_PAD_RIGHT));
        $decodedSignature = base64_decode(str_pad(strtr($signature, '-_', '+/'), strlen($signature) % 4, '=', STR_PAD_RIGHT));
        $expectedSignature = hash_hmac('sha256', "$header.$payload", $secret, true);

        if (!hash_equals($decodedSignature, $expectedSignature)) return null;

        $data = json_decode($decodedPayload, true);
        return is_array($data) ? $data : null;
    }

    /// API client authorization functions

    /**
     * Checks if the JWT token is expired based on the 'exp' claim.
     * @param array $payload The decoded JWT payload.
     * @return bool True if expired, false otherwise.
     */
    public static function isExpired(array $payload): bool
    {
        return isset($payload['exp']) && time() > $payload['exp'];
    }

    /**
     * Retrieves the subject ('sub' claim) from the JWT payload.
     * @param array $payload The decoded JWT payload.
     * @return string The subject value, or empty string if not set.
     */
    public static function getSubject(array $payload): string
    {
        return isset($payload['sub']) ? $payload['sub'] : '';
    }

    /**
     * Validates that the JWT token is intended for the expected application ID.
     * @param array $payload The decoded JWT payload.
     * @param string $expectedAppId The expected application ID.
     * @return bool True if valid, false otherwise.
     */
    public static function isValidApp(array $payload, string $expectedAppId): bool
    {
        return isset($payload['appid']) && $payload['appid'] === $expectedAppId;
    }
}
?>