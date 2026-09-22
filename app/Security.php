<?php
final class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }

    public static function validateCsrf(): void
    {
        $token = $_POST['_csrf'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(419);
            exit('Session expired. Refresh the page and try again.');
        }
    }

    public static function encrypt(string $plaintext, string $appKey): string
    {
        $key = self::keyBytes($appKey);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) throw new RuntimeException('Unable to encrypt secret.');
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload, string $appKey): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Invalid encrypted secret.');
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', self::keyBytes($appKey), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('Unable to decrypt secret.');
        return $plain;
    }

    private static function keyBytes(string $key): string
    {
        $decoded = ctype_xdigit($key) && strlen($key) === 64 ? hex2bin($key) : null;
        return $decoded ?: hash('sha256', $key, true);
    }

    public static function reference(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim($prefix)) ?: 'REF');
        return $prefix . '-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    public static function maskSecret(?string $secret): string
    {
        if (!$secret) return 'Not configured';
        $tail = substr($secret, -4);
        return '••••••••••••' . $tail;
    }
}
