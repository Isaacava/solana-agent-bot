<?php
namespace SolanaAgent\Features;

use SolanaAgent\Storage\Database;
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Utils\Logger;

/**
 * Portfolio — total value, P&L, multi-token balances
 */
class Portfolio
{
    // Supported tokens with their CoinGecko IDs and devnet mints
    const TOKENS = [
        'SOL'  => ['coingecko' => 'solana',         'decimals' => 9,  'devnet_mint' => null],
        'USDC' => ['coingecko' => 'usd-coin',        'decimals' => 6,  'devnet_mint' => 'Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr'],
        'BONK' => ['coingecko' => 'bonk',            'decimals' => 5,  'devnet_mint' => null],
        'JTO'  => ['coingecko' => 'jito-governance-token', 'decimals' => 9, 'devnet_mint' => null],
        'WIF'  => ['coingecko' => 'dogwifcoin',      'decimals' => 6,  'devnet_mint' => null],
        'RAY'  => ['coingecko' => 'raydium',         'decimals' => 6,  'devnet_mint' => null],
    ];

    public function __construct(
        private Database      $db,
        private WalletManager $walletManager,
        private array         $config
    ) {}

    /**
     * Get full portfolio snapshot for a user.
     * Returns balances, USD values, NGN values.
     */
    public function getSnapshot(int $userId): array
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) return ['error' => 'No active wallet'];

        $network = $this->config['solana']['network'] ?? 'devnet';
        $rpc     = $this->walletManager->getRpc();
        $pubkey  = $wallet['public_key'];

        // Fetch SOL balance
        $solBal = 0.0;
        try {
            $bal    = $this->walletManager->getBalance($pubkey);
            $solBal = (float)($bal['sol'] ?? 0);
        } catch (\Throwable $e) {
            Logger::warn('Portfolio: SOL balance fetch failed: ' . $e->getMessage());
        }

        // Fetch token prices from CoinGecko
        $ids    = implode(',', array_column(self::TOKENS, 'coingecko'));
        $prices = [];
        try {
            $url  = "https://api.coingecko.com/api/v3/simple/price?ids={$ids}&vs_currencies=usd,ngn&include_24hr_change=true";
            $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: SolanaAgent/1.0\r\n", 'timeout' => 8]];
            $res  = @file_get_contents($url, false, stream_context_create($opts));
            if ($res) $prices = json_decode($res, true) ?? [];
        } catch (\Throwable $e) {}

        // Fetch SPL token balances
        // Devnet: only USDC-dev mint is supported
        // Mainnet: full multi-token support
        $tokenBals = [];
        try {
            $spl = new SPLToken($rpc, $network, $this->config);
            if ($network === 'mainnet') {
                $mainnetMints = [
                    'USDC' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'BONK' => 'DezXAZ8z7PnrnRJjz3wXBoRgixCa6xjnB7YaB1pPB263',
                    'JTO'  => 'jtojtomepa8beP8AuQc6eXt5FriJwfFMwQx2v2f9mCL',
                    'WIF'  => 'EKpQGSJtjMFqKZ9KQanSqYXRcF8fBopzLHYxdM65zcjm',
                    'RAY'  => '4k3Dyjzvzp8eMZWUXbBCjEvwSkkk59S5iCNLY3QrkX6R',
                ];
                foreach ($mainnetMints as $sym => $mint) {
                    $bal = $spl->getTokenBalance($pubkey, $mint);
                    if ($bal > 0) $tokenBals[$sym] = $bal;
                }
            } else {
                // Devnet: USDC-dev only (Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr)
                $usdcBal = $spl->getUsdcBalance($pubkey);
                if ($usdcBal > 0) $tokenBals['USDC'] = $usdcBal;
            }
        } catch (\Throwable $e) {
            Logger::warn('Portfolio: token balance fetch failed: ' . $e->getMessage());
        }

        // Build portfolio rows
        $rows     = [];
        $totalUsd = 0.0;
        $totalNgn = 0.0;

        // SOL row
        $solCg  = $prices['solana'] ?? [];
        $solUsd = (float)($solCg['usd'] ?? 0);
        $solNgn = (float)($solCg['ngn'] ?? 0);
        $solChg = round((float)($solCg['usd_24h_change'] ?? 0), 2);
        $solVal = $solBal * $solUsd;
        $rows[] = [
            'symbol'    => 'SOL',
            'balance'   => $solBal,
            'price_usd' => $solUsd,
            'price_ngn' => $solNgn,
            'value_usd' => $solVal,
            'value_ngn' => $solBal * $solNgn,
            'change_24h'=> $solChg,
        ];
        $totalUsd += $solVal;
        $totalNgn += $solBal * $solNgn;

        // Token rows
        foreach ($tokenBals as $sym => $bal) {
            $cgId  = self::TOKENS[$sym]['coingecko'] ?? null;
            $cg    = $cgId ? ($prices[$cgId] ?? []) : [];
            $pUsd  = (float)($cg['usd'] ?? 0);
            $pNgn  = (float)($cg['ngn'] ?? 0);
            $chg   = round((float)($cg['usd_24h_change'] ?? 0), 2);
            $val   = $bal * $pUsd;
            $rows[] = [
                'symbol'    => $sym,
                'balance'   => $bal,
                'price_usd' => $pUsd,
                'price_ngn' => $pNgn,
                'value_usd' => $val,
                'value_ngn' => $bal * $pNgn,
                'change_24h'=> $chg,
            ];
            $totalUsd += $val;
            $totalNgn += $bal * $pNgn;
        }

        // Save snapshot for P&L tracking
        $this->saveSnapshot($userId, $totalUsd);

        return [
            'wallet'    => $pubkey,
            'network'   => $network,
            'tokens'    => $rows,
            'total_usd' => $totalUsd,
            'total_ngn' => $totalNgn,
        ];
    }

    /**
     * Format portfolio as a Telegram message.
     */
    public static function formatMessage(array $snap): string
    {
        if (isset($snap['error'])) return '❌ ' . $snap['error'];

        $netLabel = ($snap['network'] ?? 'devnet') === 'mainnet' ? '' : ' <i>(devnet)</i>';
        $msg  = "💼 <b>Portfolio</b>{$netLabel}\n";
        $msg .= "────────────────────\n";

        foreach ($snap['tokens'] as $t) {
            if ($t['balance'] <= 0) continue;
            $chgEmoji = $t['change_24h'] >= 0 ? '📈' : '📉';
            $chgStr   = ($t['change_24h'] >= 0 ? '+' : '') . $t['change_24h'] . '%';
            $msg .= "\n{$t['symbol']} <b>" . self::fmtBal($t['balance'], $t['symbol']) . "</b>\n";
            $msg .= "  💰 $" . number_format($t['value_usd'], 2) . " · ₦" . number_format($t['value_ngn'], 0) . "\n";
            $msg .= "  {$chgEmoji} {$chgStr} (24h)\n";
        }

        $msg .= "\n────────────────────\n";
        $msg .= "📊 Total: <b>$" . number_format($snap['total_usd'], 2) . "</b>";
        $msg .= " · <b>₦" . number_format($snap['total_ngn'], 0) . "</b>\n";
        $msg .= "<code>" . substr($snap['wallet'], 0, 8) . '…' . substr($snap['wallet'], -6) . "</code>";

        return $msg;
    }

    private static function fmtBal(float $bal, string $sym): string
    {
        return match($sym) {
            'SOL'  => number_format($bal, 4) . ' SOL',
            'USDC' => number_format($bal, 2) . ' USDC',
            'BONK' => number_format($bal, 0) . ' BONK',
            default=> number_format($bal, 4) . " {$sym}",
        };
    }

    private function saveSnapshot(int $userId, float $totalUsd): void
    {
        try {
            $this->db->query(
                "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                ["portfolio_snapshot_{$userId}", json_encode(['usd' => $totalUsd, 'at' => date('Y-m-d H:i:s')])]
            );
        } catch (\Throwable $e) {}
    }

    /**
     * Get P&L vs last snapshot.
     */
    public function getPnl(int $userId, float $currentUsd): array
    {
        $row = $this->db->fetch("SELECT value FROM settings WHERE key_name=?", ["portfolio_snapshot_{$userId}"]);
        if (!$row) return ['has_baseline' => false];
        $snap = json_decode($row['value'], true) ?? [];
        $prev = (float)($snap['usd'] ?? 0);
        if ($prev <= 0) return ['has_baseline' => false];
        $diff    = $currentUsd - $prev;
        $diffPct = round(($diff / $prev) * 100, 2);
        return [
            'has_baseline' => true,
            'prev_usd'     => $prev,
            'curr_usd'     => $currentUsd,
            'diff_usd'     => $diff,
            'diff_pct'     => $diffPct,
            'since'        => $snap['at'] ?? 'unknown',
        ];
    }
}
