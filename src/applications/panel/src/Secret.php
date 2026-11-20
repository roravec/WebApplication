<?php
namespace Panel;

/**
 * Encryption of secrets stored in the database (bank API tokens …): AES-256-GCM, key derived from config.php
 * `jwtSecret` (never in the database). A database dump alone does not reveal the tokens. Changing jwtSecret makes the
 * stored secrets unreadable → they have to be entered again.
 */
class Secret
{
    /** @var string */ private $key;

    public function __construct(string $appSecret)
    {
        if (strlen($appSecret) < 16) {
            throw new \RuntimeException('Chýba tajný kľúč aplikácie (config.php → jwtSecret) — tajné údaje sa nedajú uložiť.');
        }
        $this->key = hash('sha256', 'panel-secret|' . $appSecret, true);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Šifrovanie zlyhalo.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $stored): ?string
    {
        if (strncmp($stored, 'v1:', 3) !== 0) {
            return null;
        }
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }
}
