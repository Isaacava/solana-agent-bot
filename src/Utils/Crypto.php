<?php
namespace SolanaAgent\Utils;

/**
 * Symmetric encryption for private keys
 * Uses AES-256-GCM via OpenSSL — no external libs needed
 */
class Crypto
{
    private string $key;
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LEN = 16;

    public function __construct(string $encryptionKey)
    {
        // Derive a 32-byte key from whatever the user provides
        $this->key = substr(hash('sha256', $encryptionKey, true), 0, 32);
    }

    /**
     * Encrypt plaintext → base64(iv + tag + ciphertext)
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12); // GCM recommended IV size
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt base64(iv + tag + ciphertext) → plaintext
     */
    public function decrypt(string $encoded): string
    {
        $data       = base64_decode($encoded, true);
        $iv         = substr($data, 0, 12);
        $tag        = substr($data, 12, self::TAG_LEN);
        $ciphertext = substr($data, 12 + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed — wrong key or corrupted data');
        }
        return $plaintext;
    }

    /**
     * Hash a password for admin panel
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate a secure random token
     */
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
