<?php
namespace SolanaAgent\Solana;

/**
 * Base58 encoder / decoder used for Solana public keys and signatures
 * Pure PHP — no extensions required
 */
class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * Encode binary string → Base58 string
     */
    public static function encode(string $bytes): string
    {
        if (strlen($bytes) === 0) return '';

        $alphabet = self::ALPHABET;
        $intBytes = array_values(unpack('C*', $bytes));
        $decimal  = gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);

        $output = '';
        $base   = gmp_init(58);
        $zero   = gmp_init(0);

        while (gmp_cmp($decimal, $zero) > 0) {
            [$decimal, $remainder] = gmp_div_qr($decimal, $base);
            $output = $alphabet[gmp_intval($remainder)] . $output;
        }

        // Leading zeros → '1'
        foreach ($intBytes as $byte) {
            if ($byte !== 0) break;
            $output = '1' . $output;
        }

        return $output;
    }

    /**
     * Decode Base58 string → binary string
     */
    public static function decode(string $input): string
    {
        if (strlen($input) === 0) return '';

        $alphabet = self::ALPHABET;
        $map      = array_flip(str_split($alphabet));

        $decimal  = gmp_init(0);
        $base     = gmp_init(58);

        foreach (str_split($input) as $char) {
            if (!isset($map[$char])) {
                throw new \InvalidArgumentException("Invalid Base58 character: {$char}");
            }
            $decimal = gmp_add(gmp_mul($decimal, $base), gmp_init($map[$char]));
        }

        // Count leading '1' chars — each represents one leading 0x00 byte
        $leadingZeros = 0;
        foreach (str_split($input) as $char) {
            if ($char !== '1') break;
            $leadingZeros++;
        }

        // If the entire input is '1' chars (e.g. system program = all zeros),
        // gmp gives decimal=0 → hex='0' → hex2bin gives "\x00" (1 extra byte).
        // Avoid that by returning the zeros directly.
        if (gmp_cmp($decimal, gmp_init(0)) === 0) {
            return str_repeat("\x00", $leadingZeros);
        }

        $hex   = gmp_strval($decimal, 16);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        $bytes = hex2bin($hex);

        return str_repeat("\x00", $leadingZeros) . $bytes;
    }

    /**
     * Validate that a string looks like a valid Solana address (base58, 32 bytes)
     */
    public static function isValidAddress(string $address): bool
    {
        try {
            $bytes = self::decode($address);
            return strlen($bytes) === 32;
        } catch (\Throwable $ignored) {
            return false;
        }
    }
}