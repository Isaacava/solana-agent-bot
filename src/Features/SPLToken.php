<?php
namespace SolanaAgent\Features;

use SolanaAgent\Solana\{RPC, Transaction, Keypair, Base58, PDA};
use SolanaAgent\Utils\Logger;

/**
 * SPL Token — balance checks, transfers, faucet.
 *
 * Uses USDC-Dev from spl-token-faucet.com
 * Mint: Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr
 * Unlimited free devnet tokens — always available.
 */
class SPLToken
{
    // USDC-Dev mint (spl-token-faucet.com)
    public const DEVNET_USDC_MINT  = 'Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr';
    // Mainnet USDC mint (Circle)
    public const MAINNET_USDC_MINT = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    public const USDC_DECIMALS     = 6;
    public const USDC_SYMBOL       = 'USDC';

    private const FAUCET_URL = 'https://spl-token-faucet.com/api/faucet/v1';

    private RPC    $rpc;
    private string $network;

    public function __construct(RPC $rpc, string $network = 'devnet')
    {
        $this->rpc     = $rpc;
        $this->network = $network;
    }

    public function usdcMint(): string
    {
        return $this->network === 'mainnet'
            ? self::MAINNET_USDC_MINT
            : self::DEVNET_USDC_MINT;
    }

    // ─── Faucet ───────────────────────────────────────────────────────────────

    public function requestFaucet(string $walletAddress, float $amount = 100.0): array
    {
        if ($this->network !== 'devnet') {
            return ['success' => false, 'error' => 'Faucet only available on devnet.'];
        }
        $amount = min($amount, 1000);
        $url    = self::FAUCET_URL . '?' . http_build_query([
            'cluster'    => 'devnet',
            'mint'       => self::DEVNET_USDC_MINT,
            'token-name' => 'USDC-Dev',
            'wallet'     => $walletAddress,
            'amount'     => (int)round($amount * 1_000_000),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$res) return ['success' => false, 'error' => 'Faucet unreachable.'];

        $data = json_decode($res, true);
        if ($code === 200 && !empty($data['txid'])) {
            return ['success' => true, 'signature' => $data['txid'], 'amount' => $amount];
        }

        $err = $data['message'] ?? $data['error'] ?? "Faucet HTTP {$code}: {$res}";
        return ['success' => false, 'error' => $err];
    }

    // ─── Balance — uses getTokenAccountsByMint (no ATA derivation) ───────────

    /**
     * Get USDC-Dev balance. Returns 0.0 if none, throws on RPC errors.
     */
    public function getUsdcBalance(string $walletAddress): float
    {
        return $this->getTokenBalance($walletAddress, $this->usdcMint());
    }

    /**
     * Get token balance for any mint by querying the chain directly.
     * Does NOT rely on ATA address derivation — works for any token account.
     *
     * Returns the human-readable float amount (e.g. 100.5 for 100.5 USDC).
     * Returns 0.0 when the wallet holds none of that token.
     * Throws on network/RPC errors so callers can surface them.
     */
    public function getTokenBalance(string $walletAddress, string $mintAddress): float
    {
        // Ask the chain: give me all token accounts for this owner+mint combo
        $accounts = $this->rpc->getTokenAccountsByMint($walletAddress, $mintAddress);

        if (empty($accounts)) {
            // No token account at all = balance is 0
            return 0.0;
        }

        $total = 0.0;
        foreach ($accounts as $acc) {
            // Solana RPC jsonParsed path:
            // acc → account → data → parsed → info → tokenAmount → uiAmount
            $parsed   = $acc['account']['data']['parsed'] ?? null;
            if (!$parsed) continue;

            $info     = $parsed['info'] ?? [];
            // uiAmount is NULL when balance is exactly 0, otherwise a float
            $uiAmount = $info['tokenAmount']['uiAmount'] ?? null;

            if ($uiAmount !== null) {
                $total += (float)$uiAmount;
            }
        }

        Logger::info('SPLToken: balance check', [
            'wallet'   => substr($walletAddress, 0, 8),
            'mint'     => substr($mintAddress, 0, 8),
            'accounts' => count($accounts),
            'total'    => $total,
        ]);

        return $total;
    }

    /**
     * Safe balance — never throws, returns 0.0 on any error.
     * Use this when you just want to display a balance without crashing.
     */
    public function safeGetUsdcBalance(string $walletAddress): float
    {
        try {
            return $this->getUsdcBalance($walletAddress);
        } catch (\Throwable $e) {
            Logger::warn('SPLToken: safeGetUsdcBalance failed', [
                'wallet' => substr($walletAddress, 0, 8),
                'error'  => $e->getMessage(),
            ]);
            return 0.0;
        }
    }

    /**
     * Get all SPL token balances for a wallet.
     * Returns array of ['symbol', 'balance', 'mint']
     */
    public function getAllBalances(string $walletAddress): array
    {
        $balances = [];
        try {
            $accounts = $this->rpc->getTokenAccounts($walletAddress);
            foreach ($accounts as $acc) {
                $parsed = $acc['account']['data']['parsed'] ?? null;
                if (!$parsed) continue;

                $info     = $parsed['info'] ?? [];
                $mint     = $info['mint'] ?? '';
                // uiAmount is null at zero balance
                $uiAmount = $info['tokenAmount']['uiAmount'] ?? null;
                if ($uiAmount === null || (float)$uiAmount <= 0) continue;

                $symbol = match($mint) {
                    self::DEVNET_USDC_MINT  => 'USDC-Dev',
                    self::MAINNET_USDC_MINT => 'USDC',
                    PDA::BONK_MINT          => 'BONK',
                    default                 => substr($mint, 0, 4) . '…',
                };

                $balances[] = [
                    'symbol'  => $symbol,
                    'balance' => (float)$uiAmount,
                    'mint'    => $mint,
                ];
            }
        } catch (\Throwable $e) {
            Logger::warn('SPLToken: getAllBalances failed', ['error' => $e->getMessage()]);
        }
        return $balances;
    }

    // ─── ATA lookup (chain-first, no broken PDA derivation) ─────────────────────

    /**
     * Get the real token account address for a wallet+mint directly from the chain.
     * Returns the pubkey string, or null if no account exists.
     *
     * This is more reliable than PDA::findATA() because it queries what actually
     * exists on-chain rather than recomputing the address in PHP.
     */
    private function getTokenAccountAddress(string $walletAddress, string $mintAddress): ?string
    {
        try {
            $accounts = $this->rpc->getTokenAccountsByMint($walletAddress, $mintAddress);
            if (!empty($accounts)) {
                // The RPC returns [{pubkey: '...', account: {...}}, ...]
                return $accounts[0]['pubkey'] ?? null;
            }
        } catch (\Throwable $e) {
            Logger::warn('SPLToken: getTokenAccountAddress failed', [
                'wallet' => substr($walletAddress, 0, 8),
                'error'  => $e->getMessage(),
            ]);
        }
        return null;
    }

    // ─── Transfer ─────────────────────────────────────────────────────────────

    /**
     * Transfer USDC from sender to recipient.
     *
     * Uses chain-queried ATA addresses (getTokenAccountsByMint) instead of
     * off-chain PDA derivation to avoid PHP Ed25519 curve check discrepancies.
     * If the recipient has no ATA yet, falls back to PDA derivation + creation.
     */
    public function transfer(
        Keypair $senderKeypair,
        string  $senderAddress,
        string  $recipientAddress,
        float   $amount
    ): array {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Amount must be > 0'];
        }

        try {
            $mint = $this->usdcMint();

            // Balance check
            try {
                $senderBalance = $this->getTokenBalance($senderAddress, $mint);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Could not check USDC balance: ' . $e->getMessage()];
            }

            if ($senderBalance < $amount) {
                return [
                    'success' => false,
                    'error'   => "Insufficient USDC. Have: {$senderBalance}, need: {$amount}."
                               . ($this->network === 'devnet' ? "\nUse /faucet to get more." : ''),
                ];
            }

            // ── Get src ATA from chain (bypass PDA derivation) ────────────────
            $srcATA = $this->getTokenAccountAddress($senderAddress, $mint);
            if ($srcATA === null) {
                return [
                    'success' => false,
                    'error'   => 'Sender has no USDC token account on-chain.'
                               . ($this->network === 'devnet' ? ' Use /faucet to create one.' : ''),
                ];
            }

            // ── Get dest ATA from chain ───────────────────────────────────────
            $destATA = $this->getTokenAccountAddress($recipientAddress, $mint);
            if ($destATA === null) {
                // Recipient has no ATA yet — try to create it via ATA program
                $derivedATA = PDA::findATA($recipientAddress, $mint);
                Logger::info('SPLToken: creating ATA for recipient', [
                    'recipient' => substr($recipientAddress, 0, 8),
                    'ata'       => substr($derivedATA, 0, 8),
                ]);
                try {
                    $blockhash = $this->rpc->getLatestBlockhash()['blockhash'];
                    $createTx  = Transaction::buildCreateATA(
                        $senderKeypair, $recipientAddress, $mint, $derivedATA, $blockhash
                    );
                    $this->rpc->sendTransaction($createTx);
                    usleep(1_200_000); // wait for ATA to land
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'error'   => 'Recipient has no USDC token account.'
                                   . ($this->network === 'devnet'
                                      ? ' Ask them to use /faucet first to create their account.'
                                      : ' Recipient must have a USDC account before receiving tokens.'),
                    ];
                }
                // Re-query after creation
                $destATA = $this->getTokenAccountAddress($recipientAddress, $mint);
                if ($destATA === null) {
                    return [
                        'success' => false,
                        'error'   => 'Could not create USDC account for recipient.'
                                   . ($this->network === 'devnet' ? ' Ask them to use /faucet first.' : ''),
                    ];
                }
            }

            // ── Execute transfer ──────────────────────────────────────────────
            $rawAmount = (int)round($amount * pow(10, self::USDC_DECIMALS));
            $blockhash = $this->rpc->getLatestBlockhash()['blockhash'];
            $rawTx     = Transaction::buildTokenTransfer(
                $senderKeypair, $srcATA, $mint, $destATA,
                $rawAmount, self::USDC_DECIMALS, $blockhash
            );

            $sig = $this->rpc->sendTransaction($rawTx);
            Logger::info('SPLToken: Transfer OK', [
                'from'   => substr($senderAddress, 0, 8),
                'to'     => substr($recipientAddress, 0, 8),
                'srcATA' => substr($srcATA, 0, 8),
                'dstATA' => substr($destATA, 0, 8),
                'amt'    => $amount,
                'sig'    => substr($sig, 0, 16),
            ]);
            return ['success' => true, 'signature' => $sig];

        } catch (\Throwable $e) {
            Logger::error('SPLToken: Transfer failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Help ─────────────────────────────────────────────────────────────────

    public function helpMessage(): string
    {
        $isDevnet = $this->network === 'devnet';
        $msg  = "🪙 <b>Token Commands</b>\n\n";
        if ($isDevnet) {
            $msg .= "<b>Get free USDC-Dev (unlimited):</b>\n";
            $msg .= "<code>/faucet</code> — Auto-request 100 USDC-Dev\n\n";
        }
        $msg .= "<b>Check balance:</b> <code>/token balance</code>\n";
        $msg .= "<b>Send USDC:</b> <code>/token send [addr] [amount]</code>\n";
        $msg .= "<b>Swap:</b> <code>/swap 1 SOL USDC</code> or <code>/swap 50 USDC SOL</code>\n";
        return $msg;
    }
}