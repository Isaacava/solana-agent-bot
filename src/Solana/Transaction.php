<?php
namespace SolanaAgent\Solana;

use SolanaAgent\Solana\PDA;

/**
 * Solana Transaction Builder
 *
 * Builds and signs legacy transactions in pure PHP (sodium extension).
 *
 * Wire format:
 *   [compact_u16: num_signatures]
 *   [64*n bytes:  signatures    ]
 *   Message:
 *     [3 bytes:   header        ]
 *     [compact_u16 + 32*n: accounts]
 *     [32 bytes:  recent_blockhash]
 *     [compact_u16 + instructions]
 *
 * A Memo instruction (SPL Memo program) is appended automatically.
 * This guarantees every transaction produces a unique signature even when
 * sender, recipient, amount and blockhash are identical across calls.
 */
class Transaction
{
    // System Program (all-zeros pubkey)
    private const SYSTEM_PROGRAM =
        "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
      . "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    // SPL Memo program v2: MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr
    private const MEMO_PROGRAM_B58 = 'MemoSq4gqABAXKb96qnH8TysNcWxMyWCqXgDLGmfcHr';

    /**
     * Build & sign a SOL transfer transaction.
     *
     * A Memo instruction carrying a timestamp+random nonce is appended so
     * that repeated sends to the same address always have a unique signature.
     *
     * @param  Keypair $from             Sender keypair
     * @param  string  $toAddress        Recipient base58 address
     * @param  float   $amountSol        Amount in SOL
     * @param  string  $recentBlockhash  Recent blockhash (base58)
     * @param  string  $memo             Optional human-readable memo (max 566 bytes)
     * @return string  Raw signed transaction bytes (ready for sendTransaction)
     */
    public static function buildSolTransfer(
        Keypair $from,
        string  $toAddress,
        float   $amountSol,
        string  $recentBlockhash,
        string  $memo = ''
    ): string {
        $lamports      = (int) round($amountSol * 1_000_000_000);
        $fromBytes     = $from->getPublicKeyBytes();       // 32 bytes
        $toBytes       = Base58::decode($toAddress);        // 32 bytes
        $blockhashBytes = Base58::decode($recentBlockhash); // 32 bytes
        $memoProgramBytes = Base58::decode(self::MEMO_PROGRAM_B58); // 32 bytes

        if (strlen($toBytes) !== 32) {
            throw new \InvalidArgumentException('Invalid recipient address');
        }

        // Build the unique nonce memo: timestamp + 8 random hex chars
        $uniqueMemo = ($memo !== '' ? $memo . ' | ' : '')
            . 'nonce:' . time() . '-' . bin2hex(random_bytes(4));
        $memoData = $uniqueMemo; // raw UTF-8 string

        // ── Determine unique accounts ─────────────────────────────────────────
        // When from == to (self-transfer) Solana deduplicates accounts, so we
        // must only list the address once and adjust instruction indices.
        $isSelfTransfer = ($fromBytes === $toBytes);

        if ($isSelfTransfer) {
            // 3 accounts: from/to (same, signer+writable), SystemProgram, MemoProgram
            $accounts = [$fromBytes, self::SYSTEM_PROGRAM, $memoProgramBytes];
            // Transfer: prog=index 1, accounts=[0(from),0(to)]
            $transferAccounts = "\x00\x00";
            $transferProgIdx  = "\x01";
            // Memo: prog=index 2, no accounts
            $memoProgIdx = "\x02";
        } else {
            // 4 accounts: from (signer,writable), to (writable), SystemProgram, MemoProgram
            $accounts = [$fromBytes, $toBytes, self::SYSTEM_PROGRAM, $memoProgramBytes];
            // Transfer: prog=index 2, accounts=[0(from),1(to)]
            $transferAccounts = "\x00\x01";
            $transferProgIdx  = "\x02";
            // Memo: prog=index 3, no accounts
            $memoProgIdx = "\x03";
        }

        // ── Message header ────────────────────────────────────────────────────
        // [num_required_sigs=1, num_readonly_signed=0, num_readonly_unsigned=2]
        // The last 2 accounts (SystemProgram + MemoProgram) are readonly unsigned.
        $header = "\x01\x00\x02";

        // ── Instruction 1: SystemProgram::Transfer ────────────────────────────
        // Discriminator 2 (u32 LE) + lamports (u64 LE)
        $transferData = pack('V', 2) . self::packUint64($lamports);
        $transferIx   = $transferProgIdx
            . self::compactU16(strlen($transferAccounts))
            . $transferAccounts
            . self::compactU16(strlen($transferData))
            . $transferData;

        // ── Instruction 2: Memo ───────────────────────────────────────────────
        // Memo program takes no account keys, just the raw UTF-8 memo as data.
        $memoIx = $memoProgIdx
            . self::compactU16(0)                          // 0 account keys
            . self::compactU16(strlen($memoData))
            . $memoData;

        // ── Assemble message ──────────────────────────────────────────────────
        $message = $header
            . self::compactU16(count($accounts))
            . implode('', $accounts)
            . $blockhashBytes
            . self::compactU16(2)   // 2 instructions
            . $transferIx
            . $memoIx;

        // ── Sign ──────────────────────────────────────────────────────────────
        $signature = $from->sign($message); // 64 bytes (Ed25519)

        // ── Wire format ───────────────────────────────────────────────────────
        return self::compactU16(1) . $signature . $message;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Pack a 64-bit unsigned int as little-endian 8 bytes.
     * Uses two 32-bit LE words because PHP integers are signed 64-bit.
     */
    private static function packUint64(int $value): string
    {
        $lo = $value & 0xFFFFFFFF;
        $hi = ($value >> 32) & 0xFFFFFFFF;
        return pack('VV', $lo, $hi);
    }

    /**
     * Solana compact-u16 encoding.
     */
    private static function compactU16(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        if ($n < 0x4000) {
            return chr(($n & 0x7f) | 0x80) . chr($n >> 7);
        }
        return chr(($n & 0x7f) | 0x80)
             . chr((($n >> 7) & 0x7f) | 0x80)
             . chr($n >> 14);
    }

    public static function toSol(int $lamports): float
    {
        return round($lamports / 1_000_000_000, 9);
    }

    public static function toLamports(float $sol): int
    {
        return (int) round($sol * 1_000_000_000);
    }

    // ─── SPL Token instructions ───────────────────────────────────────────────

    /**
     * Build & sign a CreateAssociatedTokenAccount instruction.
     * Creates the ATA for $ownerAddress holding $mintAddress tokens.
     * Idempotent: safe to call even if ATA already exists (uses create_idempotent = 1).
     */
    public static function buildCreateATA(
        Keypair $payer,
        string  $ownerAddress,
        string  $mintAddress,
        string  $ataAddress,
        string  $recentBlockhash
    ): string {
        $payerBytes  = $payer->getPublicKeyBytes();
        $ownerBytes  = Base58::decode($ownerAddress);
        $mintBytes   = Base58::decode($mintAddress);
        $ataBytes    = Base58::decode($ataAddress);
        $sysBytes    = Base58::decode(PDA::SYSTEM_PROGRAM);
        $tokBytes    = Base58::decode(PDA::TOKEN_PROGRAM);
        $ataProgBytes= Base58::decode(PDA::ATA_PROGRAM);
        $bhBytes     = Base58::decode($recentBlockhash);

        // accounts: payer(signer,writable), ata(writable), owner, mint, system, token, ataProgram
        $accounts = [
            $payerBytes,   // 0 signer+writable
            $ataBytes,     // 1 writable
            $ownerBytes,   // 2
            $mintBytes,    // 3
            $sysBytes,     // 4
            $tokBytes,     // 5
            $ataProgBytes, // 6 (needed by some validators)
        ];

        // Header: 1 signer, 0 readonly-signed, 5 readonly-unsigned
        $header = "\x01\x00\x05";

        // ATA program instruction = 1 (create_idempotent, won't fail if ATA exists)
        $ixData = "\x01";
        $ixProgIdx = "\x06"; // ataProgram is account index 6

        // Account indices for the instruction: 0,1,2,3,4,5
        $ixAccounts = "\x00\x01\x02\x03\x04\x05";

        $ix = $ixProgIdx
            . self::compactU16(strlen($ixAccounts))
            . $ixAccounts
            . self::compactU16(strlen($ixData))
            . $ixData;

        $message = $header
            . self::compactU16(count($accounts))
            . implode('', $accounts)
            . $bhBytes
            . self::compactU16(1)
            . $ix;

        $signature = $payer->sign($message);
        return self::compactU16(1) . $signature . $message;
    }

    /**
     * Build & sign a Token MintTo instruction.
     * Mints $amount token units (raw, not decimal-adjusted) to $destATA.
     * $mintAuthority must be the keypair that has mint authority.
     */
    public static function buildMintTo(
        Keypair $mintAuthority,
        string  $mintAddress,
        string  $destATA,
        int     $amount,
        string  $recentBlockhash
    ): string {
        $authBytes  = $mintAuthority->getPublicKeyBytes();
        $mintBytes  = Base58::decode($mintAddress);
        $destBytes  = Base58::decode($destATA);
        $tokBytes   = Base58::decode(PDA::TOKEN_PROGRAM);
        $bhBytes    = Base58::decode($recentBlockhash);

        // accounts: mint(writable), dest_ata(writable), mint_authority(signer)
        $accounts = [$mintBytes, $destBytes, $authBytes, $tokBytes];

        // Header: 1 signer, 0 readonly-signed, 1 readonly-unsigned (token program)
        $header = "\x01\x00\x01";

        // MintTo instruction discriminator = 7, data = [7] + uint64_le(amount)
        $ixData    = "\x07" . self::packUint64($amount);
        $ixProgIdx = "\x03"; // token program at index 3

        // account indices: mint=0, dest=1, authority=2
        $ixAccounts = "\x00\x01\x02";

        $ix = $ixProgIdx
            . self::compactU16(strlen($ixAccounts))
            . $ixAccounts
            . self::compactU16(strlen($ixData))
            . $ixData;

        $message = $header
            . self::compactU16(count($accounts))
            . implode('', $accounts)
            . $bhBytes
            . self::compactU16(1)
            . $ix;

        $signature = $mintAuthority->sign($message);
        return self::compactU16(1) . $signature . $message;
    }

    /**
     * Build & sign a TransferChecked instruction.
     * Transfers $amount raw token units from $srcATA to $destATA.
     * $authority is the wallet that owns $srcATA.
     */
    public static function buildTokenTransfer(
        Keypair $authority,
        string  $srcATA,
        string  $mintAddress,
        string  $destATA,
        int     $amount,
        int     $decimals,
        string  $recentBlockhash
    ): string {
        $authBytes  = $authority->getPublicKeyBytes();
        $srcBytes   = Base58::decode($srcATA);
        $mintBytes  = Base58::decode($mintAddress);
        $destBytes  = Base58::decode($destATA);
        $tokBytes   = Base58::decode(PDA::TOKEN_PROGRAM);
        $bhBytes    = Base58::decode($recentBlockhash);

        // Solana account ordering rules (REQUIRED):
        //   1. Writable signers first   → authority (index 0)
        //   2. Readonly signers         → (none)
        //   3. Writable non-signers     → src ATA (index 1), dest ATA (index 2)
        //   4. Readonly non-signers     → mint (index 3), token_program (index 4)
        $accounts = [$authBytes, $srcBytes, $destBytes, $mintBytes, $tokBytes];

        // Header: num_required_signatures=1, num_readonly_signed=0, num_readonly_unsigned=2
        // readonly-unsigned = mint (3) + token_program (4)
        $header = "\x01\x00\x02";

        // TransferChecked discriminator = 12 (0x0c) + amount uint64 LE + decimals uint8
        $ixData    = "\x0c" . self::packUint64($amount) . chr($decimals);
        $ixProgIdx = "\x04"; // token_program is at account index 4

        // TransferChecked accounts: src=1, mint=3, dest=2, authority=0
        $ixAccounts = "\x01\x03\x02\x00";

        $ix = $ixProgIdx
            . self::compactU16(strlen($ixAccounts))
            . $ixAccounts
            . self::compactU16(strlen($ixData))
            . $ixData;

        $message = $header
            . self::compactU16(count($accounts))
            . implode('', $accounts)
            . $bhBytes
            . self::compactU16(1)
            . $ix;

        $signature = $authority->sign($message);
        return self::compactU16(1) . $signature . $message;
    }
}