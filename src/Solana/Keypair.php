<?php
namespace SolanaAgent\Solana;

use SolanaAgent\Utils\Crypto;

/**
 * Solana Keypair manager
 * Uses PHP's built-in sodium extension for Ed25519 operations
 * Requires: php-sodium (usually bundled with PHP 7.2+)
 */
class Keypair
{
    private string $publicKeyBytes;   // 32 raw bytes
    private string $secretKeyBytes;   // 64 raw bytes (seed || pubkey)

    private function __construct(string $secretKeyBytes)
    {
        $this->secretKeyBytes = $secretKeyBytes;
        $this->publicKeyBytes = substr($secretKeyBytes, 32, 32);
    }

    /**
     * Generate a brand-new random keypair
     */
    public static function generate(): self
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('php-sodium extension is required');
        }
        $keypair       = sodium_crypto_sign_keypair();
        $secretKeyBytes = sodium_crypto_sign_secretkey($keypair);  // 64 bytes
        return new self($secretKeyBytes);
    }

    /**
     * Restore a keypair from its 64-byte secret key (binary)
     */
    public static function fromSecretKey(string $secretKeyBytes): self
    {
        if (strlen($secretKeyBytes) !== 64) {
            throw new \InvalidArgumentException('Secret key must be 64 bytes');
        }
        return new self($secretKeyBytes);
    }

    /**
     * Restore from encrypted secret key (stored in DB)
     */
    public static function fromEncrypted(string $encryptedSk, Crypto $crypto): self
    {
        $secretKeyBytes = $crypto->decrypt($encryptedSk);
        return self::fromSecretKey($secretKeyBytes);
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    /** Raw 32-byte public key */
    public function getPublicKeyBytes(): string { return $this->publicKeyBytes; }

    /** Raw 64-byte secret key */
    public function getSecretKeyBytes(): string { return $this->secretKeyBytes; }

    /** Base58-encoded public key (wallet address) */
    public function getPublicKey(): string { return Base58::encode($this->publicKeyBytes); }

    /** Array of bytes (for JSON export like Phantom) */
    public function exportAsArray(): array
    {
        return array_values(unpack('C*', $this->secretKeyBytes));
    }

    // ─── Signing ──────────────────────────────────────────────────────────────

    /**
     * Sign a message (raw bytes) → 64-byte signature
     */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKeyBytes);
    }

    /**
     * Encrypt the secret key for DB storage
     */
    public function encryptSecretKey(Crypto $crypto): string
    {
        return $crypto->encrypt($this->secretKeyBytes);
    }

    /**
     * Verify a signature
     */
    public static function verify(string $message, string $signature, string $publicKeyBytes): bool
    {
        return sodium_crypto_sign_verify_detached($signature, $message, $publicKeyBytes);
    }
}