<?php
namespace SolanaAgent\Features;

use SolanaAgent\Solana\{RPC, Transaction, Keypair, Base58, PDA};
use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\{Crypto, Logger};
use SolanaAgent\Features\Price;
use SolanaAgent\Features\SPLToken;
/**
 * Token Swap — real on both networks
 *
 * MAINNET: Jupiter Aggregator v6 (best rates across all DEXes)
 *
 * DEVNET:  Bot Liquidity Wallet (real SPL token transfers, real transactions)
 *   The bot maintains a funded devnet wallet holding both SOL and devnet USDC.
 *   SOL → USDC: user sends SOL to bot wallet → bot sends devUSDC to user
 *   USDC → SOL: user sends devUSDC to bot wallet → bot sends SOL to user
 *   Rate: live SOL/USD from CoinGecko (same price feed as mainnet display)
 *
 * This means devnet swaps are REAL on-chain transactions — you can verify
 * them in Solana Explorer just like mainnet swaps.
 */
class Swap
{
    private const JUPITER_QUOTE   = 'https://quote-api.jup.ag/v6/quote';
    private const JUPITER_SWAP    = 'https://quote-api.jup.ag/v6/swap';

    // Settings keys for the bot's liquidity wallet
    private const LIQ_SK_KEY      = 'swap_liquidity_sk';
    private const LIQ_ADDR_KEY    = 'swap_liquidity_addr';

    // Minimum SOL the liquidity wallet keeps as reserve (for ATA rent + fees)
    private const LIQ_SOL_RESERVE = 0.05;

    // Mainnet token mints
    public const MAINNET_TOKENS = [
        'SOL'  => 'So11111111111111111111111111111111111111112',
        'USDC' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
        'BONK' => 'DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263',
        'RAY'  => '4k3Dyjzvzp8eMZWUXbBCjEvwSkkk59S5iCNLY3QrkX6R',
        'ORCA' => 'orcaEKTdK7LKz57vaAYr9QeNsVEPfiu6QeMU1kektZE',
    ];

    private RPC      $rpc;
    private Database $db;
    private Crypto   $crypto;
    private string   $network;

    public function __construct(RPC $rpc, Database $db, Crypto $crypto, string $network)
    {
        $this->rpc     = $rpc;
        $this->db      = $db;
        $this->crypto  = $crypto;
        $this->network = $network;
    }

    public function isMainnet(): bool { return $this->network === 'mainnet'; }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Get a swap quote. Returns ['ok'=>bool, 'data'=>array, 'error'=>string]
     */
    public function getQuote(string $fromSym, string $toSym, float $amount): array
    {
        return $this->isMainnet()
            ? $this->jupiterQuote($fromSym, $toSym, $amount)
            : $this->devnetQuote($fromSym, $toSym, $amount);
    }

    /**
     * Execute a confirmed swap.
     * Returns ['success'=>bool, 'signature'=>string, 'message'=>string, 'error'=>string]
     */
    public function executeSwap(array $quoteData, Keypair $userKeypair, string $userAddress): array
    {
        return $this->isMainnet()
            ? $this->jupiterExecute($quoteData, $userKeypair, $userAddress)
            : $this->devnetExecute($quoteData, $userKeypair, $userAddress);
    }

    public function formatQuoteMessage(array $data): string
    {
        return $this->isMainnet()
            ? $this->formatJupiterQuote($data)
            : $this->formatDevnetQuote($data);
    }

    public function supportedPairsMessage(): string
    {
        if ($this->isMainnet()) {
            $msg  = "🔄 <b>Token Swap</b> — <i>Mainnet · Jupiter Aggregator</i>\n\n";
            $msg .= "Usage: <code>/swap [amount] [FROM] [TO]</code>\n\n";
            $msg .= "Examples:\n";
            $msg .= "<code>/swap 0.5 SOL USDC</code>\n";
            $msg .= "<code>/swap 10 USDC SOL</code>\n";
            $msg .= "<code>/swap 1 SOL BONK</code>\n\n";
            $msg .= "Tokens: SOL · USDC · BONK · RAY · ORCA\n";
            $msg .= "⚡ Best rates across all Solana DEXes.";
        } else {
            $liqAddr = $this->db->fetch("SELECT value FROM settings WHERE key_name=?", [self::LIQ_ADDR_KEY]);
            $msg  = "🔄 <b>Token Swap</b> — <i>Devnet · Bot Liquidity</i>\n\n";
            $msg .= "Real on-chain swaps using the bot's liquidity wallet.\n\n";
            $msg .= "Supported pairs:\n";
            $msg .= "• <code>/swap [amount] SOL USDC</code>\n";
            $msg .= "• <code>/swap [amount] USDC SOL</code>\n\n";
            $msg .= "Rate is the live SOL/USD price from CoinGecko.\n";
            $msg .= "Transactions are real — verify on Solana Explorer.\n\n";
            $msg .= "💡 Need devnet USDC? Use: <code>/token faucet</code>";
        }
        return $msg;
    }

    // ─── Devnet: liquidity wallet ─────────────────────────────────────────────

    /**
     * Get or create the bot's devnet liquidity wallet.
     * On first call: generates keypair, airdrops 2 SOL, requests devUSDC from faucet.
     * Returns ['keypair'=>Keypair, 'address'=>string]
     */
    public function getLiquidityWallet(): array
    {
        $row = $this->db->fetch("SELECT value FROM settings WHERE key_name=?", [self::LIQ_SK_KEY]);

        if ($row && !empty($row['value'])) {
            $skBytes = $this->crypto->decrypt($row['value']);
            $keypair = Keypair::fromSecretKey($skBytes);
            $address = $this->db->fetch(
                "SELECT value FROM settings WHERE key_name=?",
                [self::LIQ_ADDR_KEY]
            )['value'] ?? $keypair->getPublicKey();
            return ['keypair' => $keypair, 'address' => $address];
        }

        // First time: create and fund it
        Logger::info('Swap: Creating devnet liquidity wallet');
        $keypair = Keypair::generate();
        $address = $keypair->getPublicKey();

        // Store encrypted
        $encSk = $this->crypto->encrypt($keypair->getSecretKeyBytes());
        $this->db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?,?,?)",
            [self::LIQ_SK_KEY, $encSk, date('Y-m-d H:i:s')]
        );
        $this->db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?,?,?)",
            [self::LIQ_ADDR_KEY, $address, date('Y-m-d H:i:s')]
        );

        // Airdrop SOL
        try {
            $this->rpc->requestAirdrop($address, 2.0);
            sleep(4);
            $this->rpc->requestAirdrop($address, 2.0); // second airdrop for more liquidity
            sleep(3);
        } catch (\Throwable $e) {
            Logger::warn('Swap: Liquidity wallet airdrop failed', ['error' => $e->getMessage()]);
        }

        // Request devnet USDC from faucet for liquidity
        $spl = new SPLToken($this->rpc, 'devnet');
        try {
            $spl->requestFaucet($address, 1000.0);
            sleep(3);
        } catch (\Throwable $e) {
            Logger::warn('Swap: Liquidity USDC faucet failed', ['error' => $e->getMessage()]);
        }

        Logger::info('Swap: Liquidity wallet ready', ['address' => $address]);
        return ['keypair' => $keypair, 'address' => $address];
    }

    /**
     * Get liquidity wallet balances. Returns ['sol'=>float, 'usdc'=>float, 'address'=>string]
     */
    public function getLiquidityBalances(): array
    {
        try {
            $liq     = $this->getLiquidityWallet();
            $spl     = new SPLToken($this->rpc, 'devnet');
            $solBal  = round($this->rpc->getBalanceSol($liq['address']), 6);
            $usdcBal = $spl->getUsdcBalance($liq['address']);
            return ['sol' => $solBal, 'usdc' => $usdcBal, 'address' => $liq['address']];
        } catch (\Throwable $e) {
            return ['sol' => 0, 'usdc' => 0, 'address' => ''];
        }
    }

    // ─── Devnet quote + execute ───────────────────────────────────────────────

    private function devnetQuote(string $fromSym, string $toSym, float $amount): array
    {
        $from = strtoupper($fromSym);
        $to   = strtoupper($toSym);

        if (!in_array($from, ['SOL', 'USDC']) || !in_array($to, ['SOL', 'USDC']) || $from === $to) {
            return ['ok' => false, 'error' =>
                "On devnet only SOL ↔ USDC swaps are supported.\n"
                . "Use <code>/swap 1 SOL USDC</code> or <code>/swap 50 USDC SOL</code>"];
        }

        $solPrice = $this->getSolPriceUsd();
        if ($solPrice <= 0) {
            return ['ok' => false, 'error' => 'Could not fetch live SOL price. Try again in a moment.'];
        }

        // Check liquidity wallet has enough
        $liqBal = $this->getLiquidityBalances();

        if ($from === 'SOL') {
            $outAmount = round($amount * $solPrice, 2);
            if ($liqBal['usdc'] < $outAmount) {
                return ['ok' => false, 'error' =>
                    "Swap pool is low on devnet USDC (has " . number_format($liqBal['usdc'], 2) . " USDC).\n"
                    . "Try a smaller amount, or use <code>/token faucet</code> and try again shortly."];
            }
        } else {
            $outAmount = round($amount / $solPrice, 6);
            if ($liqBal['sol'] - self::LIQ_SOL_RESERVE < $outAmount) {
                return ['ok' => false, 'error' =>
                    "Swap pool is low on devnet SOL. Try a smaller amount."];
            }
        }

        return [
            'ok'   => true,
            'data' => [
                'type'      => 'devnet',
                'fromSym'   => $from,
                'toSym'     => $to,
                'fromAmount'=> $amount,
                'toAmount'  => $outAmount,
                'solPrice'  => $solPrice,
                'liqAddr'   => $liqBal['address'],
            ],
        ];
    }

    private function formatDevnetQuote(array $d): string
    {
        $msg  = "🔄 <b>Swap Quote</b> — <i>Devnet</i>\n\n";
        $msg .= "📤 You send: <b>{$d['fromAmount']} {$d['fromSym']}</b>\n";
        $msg .= "📥 You receive: <b>{$d['toAmount']} {$d['toSym']}</b>\n\n";
        $msg .= "📈 Rate: <b>1 SOL = \${$d['solPrice']}</b> (live)\n\n";
        $msg .= "✅ Real on-chain swap — verify on Solana Explorer.\n";
        $msg .= "Confirm to proceed?";
        return $msg;
    }

    private function devnetExecute(array $d, Keypair $userKeypair, string $userAddress): array
    {
        $spl = new SPLToken($this->rpc, 'devnet');
        $liq = $this->getLiquidityWallet();

        if ($d['fromSym'] === 'SOL') {
            // Step 1: User sends SOL → liquidity wallet
            try {
                $blockhash = $this->rpc->getLatestBlockhash()['blockhash'];
                $solTx = Transaction::buildSolTransfer(
                    $userKeypair,
                    $liq['address'],
                    $d['fromAmount'],
                    $blockhash,
                    'SolanaBot swap'
                );
                $solSig = $this->rpc->sendTransaction($solTx);
                Logger::info('Swap devnet: SOL received', ['sig' => $solSig]);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => 'Failed to receive SOL: ' . $e->getMessage()];
            }

            // Brief pause then send USDC
            usleep(800_000);

            // Step 2: Liquidity wallet sends USDC → user
            $result = $spl->transfer($liq['keypair'], $liq['address'], $userAddress, $d['toAmount']);
            if (!$result['success']) {
                return ['success' => false, 'error' =>
                    "SOL received but USDC transfer failed: " . $result['error']
                    . "\nContact support — your SOL was sent to the liquidity pool."];
            }

            return [
                'success'   => true,
                'signature' => $result['signature'],
                'message'   => "✅ <b>Swap Complete!</b>\n\n"
                    . "📤 Sent: <b>{$d['fromAmount']} SOL</b>\n"
                    . "📥 Received: <b>{$d['toAmount']} USDC</b>\n"
                    . "📈 Rate: <b>1 SOL = \${$d['solPrice']}</b>",
            ];

        } else {
            // USDC → SOL
            // Step 1: User sends USDC → liquidity wallet
            $result = $spl->transfer($userKeypair, $userAddress, $liq['address'], $d['fromAmount']);
            if (!$result['success']) {
                return ['success' => false, 'error' => 'Failed to receive USDC: ' . $result['error']];
            }

            Logger::info('Swap devnet: USDC received', ['sig' => $result['signature']]);
            usleep(800_000);

            // Step 2: Liquidity wallet sends SOL → user
            try {
                $blockhash = $this->rpc->getLatestBlockhash()['blockhash'];
                $solTx = Transaction::buildSolTransfer(
                    $liq['keypair'],
                    $userAddress,
                    $d['toAmount'],
                    $blockhash,
                    'SolanaBot swap'
                );
                $solSig = $this->rpc->sendTransaction($solTx);
            } catch (\Throwable $e) {
                return ['success' => false, 'error' =>
                    "USDC received but SOL transfer failed: " . $e->getMessage()
                    . "\nContact support — your USDC was sent to the liquidity pool."];
            }

            return [
                'success'   => true,
                'signature' => $solSig,
                'message'   => "✅ <b>Swap Complete!</b>\n\n"
                    . "📤 Sent: <b>{$d['fromAmount']} USDC</b>\n"
                    . "📥 Received: <b>{$d['toAmount']} SOL</b>\n"
                    . "📈 Rate: <b>1 SOL = \${$d['solPrice']}</b>",
            ];
        }
    }

    // ─── Jupiter (mainnet) ────────────────────────────────────────────────────

    private function jupiterQuote(string $fromSym, string $toSym, float $amount): array
    {
        $inputMint  = self::MAINNET_TOKENS[strtoupper($fromSym)]  ?? null;
        $outputMint = self::MAINNET_TOKENS[strtoupper($toSym)]    ?? null;

        if (!$inputMint)  return ['ok' => false, 'error' => "Unknown token: {$fromSym}\nSupported: SOL, USDC, BONK, RAY, ORCA"];
        if (!$outputMint) return ['ok' => false, 'error' => "Unknown token: {$toSym}\nSupported: SOL, USDC, BONK, RAY, ORCA"];

        $decimals  = ['SOL' => 9, 'USDC' => 6, 'BONK' => 5, 'RAY' => 6, 'ORCA' => 6];
        $inDec     = $decimals[strtoupper($fromSym)] ?? 9;
        $outDec    = $decimals[strtoupper($toSym)]   ?? 9;
        $amountRaw = (int)round($amount * pow(10, $inDec));

        $url  = self::JUPITER_QUOTE . '?' . http_build_query([
            'inputMint'   => $inputMint,
            'outputMint'  => $outputMint,
            'amount'      => $amountRaw,
            'slippageBps' => 50,
        ]);
        $data = $this->httpGet($url);

        if (!$data || isset($data['error'])) {
            return ['ok' => false, 'error' => $data['error'] ?? 'Jupiter unavailable. Try again.'];
        }

        $outAmt  = round($data['outAmount'] / pow(10, $outDec), 6);
        $impact  = round((float)($data['priceImpactPct'] ?? 0) * 100, 4);
        $routes  = array_map(fn($r) => $r['swapInfo']['label'] ?? '?', $data['routePlan'] ?? []);

        return [
            'ok'   => true,
            'data' => [
                'type'       => 'jupiter',
                'quote'      => $data,
                'fromSym'    => strtoupper($fromSym),
                'toSym'      => strtoupper($toSym),
                'fromAmount' => $amount,
                'toAmount'   => $outAmt,
                'impact'     => $impact,
                'route'      => implode(' → ', $routes) ?: 'Direct',
            ],
        ];
    }

    private function formatJupiterQuote(array $d): string
    {
        $msg  = "🔄 <b>Swap Quote</b> — <i>Mainnet · Jupiter</i>\n\n";
        $msg .= "📤 You send: <b>{$d['fromAmount']} {$d['fromSym']}</b>\n";
        $msg .= "📥 You receive: <b>~{$d['toAmount']} {$d['toSym']}</b>\n\n";
        $msg .= "📊 Price impact: <b>{$d['impact']}%</b>\n";
        $msg .= "🛣️ Route: <b>{$d['route']}</b>\n";
        $msg .= "⚡ Slippage: <b>0.5%</b>\n\n";
        $msg .= "⚠️ Rates move fast. Confirm to swap at best available price.";
        return $msg;
    }

    private function jupiterExecute(array $d, Keypair $userKeypair, string $userAddress): array
    {
        $payload = json_encode([
            'quoteResponse'             => $d['quote'],
            'userPublicKey'             => $userAddress,
            'wrapAndUnwrapSol'          => true,
            'dynamicComputeUnitLimit'   => true,
            'prioritizationFeeLamports' => 'auto',
        ]);

        $txData = $this->httpPost(self::JUPITER_SWAP, $payload);
        if (!$txData || empty($txData['swapTransaction'])) {
            return ['success' => false, 'error' => $txData['error'] ?? 'Jupiter build failed'];
        }

        $txBytes = base64_decode($txData['swapTransaction']);
        if (!$txBytes) return ['success' => false, 'error' => 'Could not decode transaction'];

        $sk  = $userKeypair->getSecretKeyBytes();
        if (strlen($sk) !== 64) return ['success' => false, 'error' => 'Invalid keypair'];

        $numSigs  = ord($txBytes[0]);
        $msgOff   = 1 + ($numSigs * 64);
        $msgBytes = substr($txBytes, $msgOff);
        $sig      = sodium_crypto_sign_detached($msgBytes, $sk);
        $signed   = chr($numSigs) . $sig . str_repeat(chr(0), ($numSigs - 1) * 64) . $msgBytes;

        try {
            $txSig = $this->rpc->sendRawBase64Transaction(base64_encode($signed));
            return [
                'success'   => true,
                'signature' => $txSig,
                'message'   => "✅ <b>Swap Executed!</b>\n\n"
                    . "📤 Sent: <b>{$d['fromAmount']} {$d['fromSym']}</b>\n"
                    . "📥 Received: <b>~{$d['toAmount']} {$d['toSym']}</b>",
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getSolPriceUsd(): float
    {
        // Reuse the same Price class that works for /price command
        try {
            $price = Price::getSolPrice();
            return (float)($price['usd'] ?? 0);
        } catch (\Throwable $ignored) {
            return 0.0;
        }
    }

    private function httpGet(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$res || $code !== 200) return null;
        return json_decode($res, true);
    }

    private function httpPost(string $url, string $payload): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Accept: application/json']]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$res || $code !== 200) return null;
        return json_decode($res, true);
    }
}