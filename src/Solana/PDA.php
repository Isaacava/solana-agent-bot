<?php
namespace SolanaAgent\Solana;

/**
 * Program Derived Address (PDA) derivation — pure PHP using GMP + sodium
 *
 * Used to find Associated Token Account (ATA) addresses without
 * external SDKs. ATAs are PDAs derived from:
 *   seeds = [owner_pubkey, TOKEN_PROGRAM_ID, mint_pubkey]
 *   program = ASSOCIATED_TOKEN_PROGRAM_ID
 */
class PDA
{
    // ─── Program IDs ─────────────────────────────────────────────────────────

    public const TOKEN_PROGRAM     = 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA';
    public const TOKEN_2022        = 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb';
    public const ATA_PROGRAM       = 'ATokenGPvbdGVxr1b2hvZbsiqW5xWH25efTNsLJA8knL';
    public const SYSTEM_PROGRAM    = '11111111111111111111111111111111';
    public const RENT_SYSVAR       = 'SysvarRent111111111111111111111111111111111';
    public const MEMO_PROGRAM      = 'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr';

    // ─── Well-known token mints ───────────────────────────────────────────────

    // SOL (wrapped)
    public const WSOL_MINT    = 'So11111111111111111111111111111111111111112';
    // Mainnet USDC
    public const USDC_MINT    = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    // USDC-Dev (spl-token-faucet.com — unlimited devnet USDC)
    public const DEVNET_USDC  = 'Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr';
    // Mainnet BONK
    public const BONK_MINT    = 'DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263';

    // ─── PDA / ATA derivation ─────────────────────────────────────────────────

    /**
     * Derive the Associated Token Account address for a wallet + mint.
     * Returns the ATA address as a base58 string.
     *
     * This is a pure-PHP implementation of:
     *   PublicKey.findProgramAddressSync(
     *     [owner.toBuffer(), TOKEN_PROGRAM_ID.toBuffer(), mint.toBuffer()],
     *     ASSOCIATED_TOKEN_PROGRAM_ID
     *   )
     */
    public static function findATA(string $ownerBase58, string $mintBase58): string
    {
        $owner      = Base58::decode($ownerBase58);
        $mint       = Base58::decode($mintBase58);
        $tokenProg  = Base58::decode(self::TOKEN_PROGRAM);
        $ataProg    = Base58::decode(self::ATA_PROGRAM);

        [$address] = self::findProgramAddress([$owner, $tokenProg, $mint], $ataProg);
        return Base58::encode($address);
    }

    /**
     * Solana findProgramAddress: iterate bump 255→0 until we find a point
     * that is NOT on the Ed25519 curve (PDAs are intentionally off-curve).
     *
     * @param  string[] $seeds    Array of raw binary seed strings
     * @param  string   $programId  Raw 32-byte program ID
     * @return array [address_bytes_32, bump_byte]
     */
    public static function findProgramAddress(array $seeds, string $programId): array
    {
        for ($bump = 255; $bump >= 0; $bump--) {
            // Solana spec: SHA256(seeds... || programId || "ProgramDerivedAddress" || bump)
            // Bump is appended AFTER the marker, not merged into seeds before programId.
            $data    = implode('', $seeds) . $programId . 'ProgramDerivedAddress' . chr($bump);
            $address = hash('sha256', $data, true);
            if (!self::isOnEd25519Curve($address)) {
                return [$address, $bump];
            }
        }
        throw new \RuntimeException('Could not find valid PDA (exhausted all bumps)');
    }

    /**
     * Create a program address from fixed seeds (no bump search).
     * Returns the 32-byte address, or null if the result lands on the curve.
     *
     * Solana spec: SHA256(seed1 || seed2 || ... || programId || "ProgramDerivedAddress")
     * (No bump — bump is only used in findProgramAddress)
     */
    public static function createProgramAddress(array $seeds, string $programId): ?string
    {
        $data = implode('', $seeds) . $programId . 'ProgramDerivedAddress';
        $hash = hash('sha256', $data, true);

        // Valid PDA must NOT be a point on the Ed25519 curve
        if (self::isOnEd25519Curve($hash)) {
            return null;
        }
        return $hash;
    }

    // ─── Ed25519 on-curve check ───────────────────────────────────────────────

    /**
     * Returns true if the 32-byte compressed point lies on the Ed25519 curve.
     * PDAs must NOT be on the curve, so we look for points where this is false.
     *
     * Ed25519 curve: -x² + y² = 1 + d·x²·y²  (mod p), p = 2^255 - 19
     * Given compressed y with sign bit: check if x² = (y²-1)/(d·y²+1) has a solution.
     *
     * Uses PHP GMP extension (always available on modern PHP).
     */
    public static function isOnCurve(string $bytes): bool
    {
        return self::isOnEd25519Curve($bytes);
    }

    private static function isOnEd25519Curve(string $bytes): bool
    {
        if (strlen($bytes) !== 32) return false;

        // Ed25519 field prime p = 2^255 - 19
        static $p = null;
        static $d = null;

        if ($p === null) {
            $p = gmp_sub(gmp_pow(gmp_init(2), 255), gmp_init(19));

            // d = -121665 * modInverse(121666, p) mod p
            $inv = gmp_invert(gmp_init(121666), $p);
            $d   = gmp_mod(gmp_mul(gmp_init(-121665), $inv), $p);
        }

        // Decode y: little-endian, top bit is x-sign
        $byteArr = array_values(unpack('C*', $bytes));
        $byteArr[31] &= 0x7F;  // clear sign bit
        // Convert little-endian bytes to GMP integer
        $yHex = '';
        for ($i = 31; $i >= 0; $i--) {
            $yHex .= sprintf('%02x', $byteArr[$i]);
        }
        $y = gmp_init($yHex, 16);

        // Check y < p
        if (gmp_cmp($y, $p) >= 0) return false;

        // u = y^2 - 1
        $y2 = gmp_mod(gmp_mul($y, $y), $p);
        $u  = gmp_mod(gmp_sub($y2, gmp_init(1)), $p);

        // v = d * y^2 + 1
        $v  = gmp_mod(gmp_add(gmp_mul($d, $y2), gmp_init(1)), $p);

        // Compute candidate x^2 = u / v
        // For p ≡ 5 (mod 8) [which 2^255-19 satisfies]:
        //   x = (u * v^3) * (u * v^7)^((p-5)/8)  mod p
        $v3 = gmp_mod(gmp_mul(gmp_mul($v, $v), $v), $p);
        $v7 = gmp_mod(gmp_mul(gmp_mul($v3, $v3), $v), $p);

        $exp = gmp_div(gmp_sub($p, gmp_init(5)), gmp_init(8));
        $uv7 = gmp_mod(gmp_mul($u, $v7), $p);
        $x   = gmp_mod(
            gmp_mul(gmp_mul($u, $v3), gmp_powm($uv7, $exp, $p)),
            $p
        );

        // Check: v * x^2 == u (mod p) — if so, x exists and point is on curve
        $x2    = gmp_mod(gmp_mul($x, $x), $p);
        $check = gmp_mod(gmp_mul($v, $x2), $p);

        return gmp_cmp($check, $u) === 0;
    }
}