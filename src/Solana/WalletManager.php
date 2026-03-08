<?php
namespace SolanaAgent\Solana;

use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\Crypto;
use SolanaAgent\Utils\Logger;

/**
 * High-level wallet operations
 */
class WalletManager
{
    private Database $db;
    private Crypto   $crypto;
    private RPC      $rpc;
    private array    $config;

    public function __construct(Database $db, Crypto $crypto, array $config)
    {
        $this->db     = $db;
        $this->crypto = $crypto;
        $this->config = $config;
        $this->rpc    = new RPC(
            $config['network'] === 'mainnet'
                ? $config['rpc_mainnet']
                : $config['rpc_devnet']
        );
    }

    // ─── Create ───────────────────────────────────────────────────────────────

    /**
     * Create a new wallet for a user.
     * The NEW wallet does NOT automatically become active —
     * the user must explicitly switch to it with switchWallet().
     * This preserves the previously active wallet.
     */
    public function createWallet(int $userId, string $label = 'Main Wallet'): array
    {
        $maxWallets = (int)($this->config['features']['max_wallets_per_user'] ?? 5);
        $existing   = count($this->db->getUserWallets($userId));

        if ($existing >= $maxWallets) {
            throw new \RuntimeException("Maximum {$maxWallets} wallets allowed per user.");
        }

        // Auto-label: "Wallet 2", "Wallet 3", etc.
        if ($label === 'Main Wallet' && $existing > 0) {
            $label = 'Wallet ' . ($existing + 1);
        }

        $keypair     = Keypair::generate();
        $publicKey   = $keypair->getPublicKey();
        $encryptedSk = $keypair->encryptSecretKey($this->crypto);
        $network     = $this->config['network'];

        // New wallet is inactive by default if user already has one
        $isActive = ($existing === 0) ? 1 : 0;

        $walletId = $this->db->insert('wallets', [
            'user_id'      => $userId,
            'label'        => $label,
            'public_key'   => $publicKey,
            'encrypted_sk' => $encryptedSk,
            'network'      => $network,
            'is_active'    => $isActive,
        ]);

        Logger::info('Wallet created', [
            'user_id'    => $userId,
            'public_key' => $publicKey,
            'active'     => $isActive,
        ]);

        return [
            'id'         => $walletId,
            'public_key' => $publicKey,
            'network'    => $network,
            'label'      => $label,
            'is_active'  => $isActive,
        ];
    }

    /**
     * Switch the active wallet for a user.
     * Deactivates all other wallets, activates the chosen one.
     */
    public function switchWallet(int $userId, int $walletId): array
    {
        // Verify the wallet belongs to this user
        $wallet = $this->db->fetch(
            'SELECT * FROM wallets WHERE id=? AND user_id=?',
            [$walletId, $userId]
        );

        if (!$wallet) {
            throw new \RuntimeException('Wallet not found or does not belong to you.');
        }

        // Deactivate all wallets for this user
        $this->db->query(
            'UPDATE wallets SET is_active=0 WHERE user_id=?',
            [$userId]
        );

        // Activate the chosen wallet
        $this->db->query(
            'UPDATE wallets SET is_active=1 WHERE id=?',
            [$walletId]
        );

        Logger::info('Wallet switched', [
            'user_id'   => $userId,
            'wallet_id' => $walletId,
            'public_key'=> $wallet['public_key'],
        ]);

        return $wallet;
    }

    // ─── Retrieve ─────────────────────────────────────────────────────────────

    public function getActiveWallet(int $userId): ?array
    {
        return $this->db->getActiveWallet($userId);
    }

    public function getKeypair(array $walletRow): Keypair
    {
        return Keypair::fromEncrypted($walletRow['encrypted_sk'], $this->crypto);
    }

    // ─── Balance ──────────────────────────────────────────────────────────────

    public function getBalance(string $publicKey): array
    {
        $lamports = $this->rpc->getBalance($publicKey);
        $sol      = round($lamports / 1_000_000_000, 9);
        return [
            'lamports' => $lamports,
            'sol'      => $sol,
        ];
    }

    // ─── Transfer ─────────────────────────────────────────────────────────────

    /**
     * Send SOL from a user's active wallet to a recipient.
     * Retries once with a fresh blockhash if Solana rejects as duplicate.
     */
    public function sendSol(
        int    $userId,
        string $toAddress,
        float  $amountSol,
        int    $attempt = 1
    ): array {
        $walletRow = $this->db->getActiveWallet($userId);
        if (!$walletRow) {
            throw new \RuntimeException('No active wallet found. Use /wallet create first.');
        }

        // Validate recipient
        if (!Base58::isValidAddress($toAddress)) {
            throw new \InvalidArgumentException('Invalid Solana address. Please double-check the address.');
        }

        // Validate amount
        if ($amountSol <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than 0.');
        }

        // Check balance (fetch fresh every attempt)
        $balance = $this->getBalance($walletRow['public_key']);
        $fee     = 0.000005; // ~5000 lamports base fee
        if ($balance['sol'] < $amountSol + $fee) {
            throw new \RuntimeException(
                sprintf(
                    "Insufficient balance.\nYou have: %.9f SOL\nNeed: %.9f SOL (including ~0.000005 fee)",
                    $balance['sol'],
                    $amountSol + $fee
                )
            );
        }

        // Always fetch a FRESH blockhash — never cache this
        $blockhash = $this->rpc->getLatestBlockhash();

        // Build & sign transaction
        $keypair  = $this->getKeypair($walletRow);
        $signedTx = Transaction::buildSolTransfer(
            $keypair,
            $toAddress,
            $amountSol,
            $blockhash['blockhash']
        );

        try {
            $signature = $this->rpc->sendTransaction($signedTx);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();

            // Solana-level duplicate: wait for next slot and retry ONCE
            $isDuplicate = strpos($msg, 'AlreadyProcessed') !== false
                        || strpos($msg, 'already been processed') !== false
                        || strpos($msg, 'duplicate') !== false;

            if ($isDuplicate && $attempt === 1) {
                Logger::warn('Duplicate tx detected, retrying with fresh blockhash', [
                    'attempt' => $attempt,
                    'to'      => $toAddress,
                    'amount'  => $amountSol,
                ]);
                usleep(600000); // wait 600ms (1.5 slots) for a new blockhash
                return $this->sendSol($userId, $toAddress, $amountSol, 2);
            }

            throw $e;
        }

        // Log transaction
        $this->db->insert('transactions', [
            'user_id'    => $userId,
            'wallet_id'  => $walletRow['id'],
            'type'       => 'send_sol',
            'amount_sol' => $amountSol,
            'from_addr'  => $walletRow['public_key'],
            'to_addr'    => $toAddress,
            'signature'  => $signature,
            'status'     => 'submitted',
            'network'    => $this->config['network'],
        ]);

        Logger::info('SOL sent', [
            'from'    => $walletRow['public_key'],
            'to'      => $toAddress,
            'sol'     => $amountSol,
            'sig'     => $signature,
            'attempt' => $attempt,
        ]);

        return [
            'signature' => $signature,
            'from'      => $walletRow['public_key'],
            'to'        => $toAddress,
            'amount'    => $amountSol,
            'network'   => $this->config['network'],
            'explorer'  => $this->explorerUrl($signature),
        ];
    }

    // ─── Airdrop (devnet) ─────────────────────────────────────────────────────

    public function requestAirdrop(int $userId, float $sol = 1.0): string
    {
        if ($this->config['network'] !== 'devnet') {
            throw new \RuntimeException('Airdrop only available on devnet.');
        }
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            throw new \RuntimeException('No active wallet. Create one first.');
        }
        return $this->rpc->requestAirdrop($wallet['public_key'], $sol);
    }

    // ─── Transaction history ──────────────────────────────────────────────────

    public function getHistory(int $userId, int $limit = 5): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    // ─── On-chain history ─────────────────────────────────────────────────────

    public function getOnChainHistory(string $publicKey, int $limit = 5): array
    {
        return $this->rpc->getSignaturesForAddress($publicKey, $limit);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function explorerUrl(string $signature): string
    {
        $cluster = $this->config['network'] === 'mainnet' ? '' : '?cluster=devnet';
        return "https://explorer.solana.com/tx/{$signature}{$cluster}";
    }

    public function getRpc(): RPC
    {
        return $this->rpc;
    }
}
