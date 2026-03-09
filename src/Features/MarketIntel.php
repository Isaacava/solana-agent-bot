<?php
namespace SolanaAgent\Features;

use SolanaAgent\Utils\Logger;

/**
 * MarketIntel — Fear & Greed, Whale Alerts, Gas Fees, Staking APY
 */
class MarketIntel
{
    const FEAR_GREED_API  = 'https://api.alternative.me/fng/?limit=1&format=json';
    const HELIUS_BASE     = 'https://api.helius.xyz/v0';
    const SOLANA_BEACON   = 'https://api.solanabeach.io/v1';
    const SOLANA_COMPASS  = 'https://solanacompass.com/api/staking';

    // ─── Fear & Greed Index ───────────────────────────────────────────────────

    public static function getFearGreed(): array
    {
        try {
            $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: SolanaAgent/1.0\r\n", 'timeout' => 6]];
            $res  = @file_get_contents(self::FEAR_GREED_API, false, stream_context_create($opts));
            if (!$res) return ['error' => 'API unreachable'];
            $data = json_decode($res, true);
            $fg   = $data['data'][0] ?? [];
            return [
                'value'       => (int)($fg['value'] ?? 0),
                'label'       => $fg['value_classification'] ?? 'Unknown',
                'timestamp'   => $fg['timestamp'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function formatFearGreed(array $fg): string
    {
        if (isset($fg['error'])) return "❌ Could not fetch Fear & Greed index right now.";
        $val   = $fg['value'];
        $label = $fg['label'];

        $emoji = match(true) {
            $val <= 20  => '😱',  // Extreme Fear
            $val <= 40  => '😰',  // Fear
            $val <= 60  => '😐',  // Neutral
            $val <= 80  => '😄',  // Greed
            default     => '🤑',  // Extreme Greed
        };

        $bar = self::progressBar($val, 100, 10);

        $advice = match(true) {
            $val <= 25  => "Market is in extreme fear — historically a good time to buy.",
            $val <= 45  => "Market is fearful — dip buyers often step in here.",
            $val <= 55  => "Market is neutral — no strong signal either way.",
            $val <= 75  => "Market is greedy — consider taking some profit.",
            default     => "Extreme greed — be careful, corrections often follow.",
        };

        return "{$emoji} <b>Crypto Fear & Greed Index</b>\n\n"
            . "{$bar} <b>{$val}/100</b>\n"
            . "📊 Sentiment: <b>{$label}</b>\n\n"
            . "💡 {$advice}";
    }

    // ─── Gas / Network Fees ───────────────────────────────────────────────────

    public static function getNetworkStatus(string $rpcUrl = 'https://api.devnet.solana.com', string $network = 'devnet'): array
    {
        try {
            $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'getRecentPrioritizationFees', 'params' => [[]]]);
            $opts    = ['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nUser-Agent: SolanaAgent/1.0\r\n",
                'content' => $payload,
                'timeout' => 6,
            ]];
            $res  = @file_get_contents($rpcUrl, false, stream_context_create($opts));
            $data = $res ? (json_decode($res, true) ?? []) : [];
            $fees = $data['result'] ?? [];

            // Get slot height for TPS estimate
            $slotPayload = json_encode(['jsonrpc'=>'2.0','id'=>2,'method'=>'getSlot','params'=>[]]);
            $opts['http']['content'] = $slotPayload;
            $slotRes  = @file_get_contents($rpcUrl, false, stream_context_create($opts));
            $slotData = $slotRes ? (json_decode($slotRes, true) ?? []) : [];
            $slot     = (int)($slotData['result'] ?? 0);

            // Calculate median fee
            $feeValues = array_column($fees, 'prioritizationFee');
            sort($feeValues);
            $median    = !empty($feeValues) ? $feeValues[intval(count($feeValues) / 2)] : 0;

            // Assess congestion
            $congestion = match(true) {
                $median <= 100   => ['level' => 'Low',    'emoji' => '🟢', 'tip' => 'Fast confirmations. Great time to transact.'],
                $median <= 5000  => ['level' => 'Medium', 'emoji' => '🟡', 'tip' => 'Normal network load. Transactions confirm quickly.'],
                $median <= 50000 => ['level' => 'High',   'emoji' => '🟠', 'tip' => 'Network is busy. Consider waiting or using higher priority fee.'],
                default          => ['level' => 'Very High','emoji'=> '🔴', 'tip' => 'Heavy congestion. Transactions may be slow.'],
            };

            return [
                'slot'        => $slot,
                'median_fee'  => $median,
                'congestion'  => $congestion,
                'base_fee'    => 5000, // lamports — Solana fixed base fee
                'network'     => $network,
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function formatNetworkStatus(array $status): string
    {
        if (isset($status['error'])) return "❌ Could not fetch network status right now.";
        $c   = $status['congestion'];
        $fee = $status['median_fee'];
        $baseFeeUsd = 0.000005 * 0.0000001; // rough SOL fee in USD

        $netNote = ($status['network'] ?? 'devnet') !== 'mainnet'
            ? "\n\n<i>⚠️ Showing devnet network status. Devnet fees are near-zero and not representative of mainnet.</i>"
            : "";
        return "{$c['emoji']} <b>Solana Network Status</b>\n\n"
            . "📶 Congestion: <b>{$c['level']}</b>\n"
            . "⛽ Priority fee: <b>" . number_format($fee) . " lamports</b>\n"
            . "💰 Base TX fee: ~0.000005 SOL\n"
            . "🔢 Current slot: " . number_format($status['slot']) . "\n\n"
            . "💡 {$c['tip']}{$netNote}";
    }

    // ─── Staking APY ─────────────────────────────────────────────────────────

    public static function getStakingApy(): array
    {
        try {
            // Fetch from Solana Compass (free public API)
            $url  = 'https://solanacompass.com/api/staking';
            $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: SolanaAgent/1.0\r\n", 'timeout' => 6]];
            $res  = @file_get_contents($url, false, stream_context_create($opts));

            if ($res) {
                $data = json_decode($res, true) ?? [];
                $apy  = round((float)($data['apy'] ?? $data['average_apy'] ?? 0), 2);
                if ($apy > 0) return ['apy' => $apy, 'source' => 'Solana Compass'];
            }

            // Fallback: estimate from inflation schedule (~6.5% base, minus validator commission ~8% → ~6% net)
            return ['apy' => 6.5, 'source' => 'estimated', 'note' => 'actual varies by validator'];

        } catch (\Throwable $e) {
            return ['apy' => 6.5, 'source' => 'estimated'];
        }
    }

    public static function formatStakingApy(array $data): string
    {
        $apy  = $data['apy'];
        $note = isset($data['note']) ? "\n<i>Note: {$data['note']}</i>" : '';
        return "🏦 <b>SOL Staking APY</b>\n\n"
            . "📈 Current APY: <b>{$apy}%</b>\n"
            . "💰 On 10 SOL: +<b>" . round(10 * $apy / 100, 4) . " SOL/year</b>\n"
            . "💰 On 100 SOL: +<b>" . round(100 * $apy / 100, 4) . " SOL/year</b>\n"
            . "📊 Source: {$data['source']}{$note}\n\n"
            . "💡 Staking is low-risk passive income. You keep custody of your SOL.\n"
            . "Say <b>\"how do I stake SOL?\"</b> to learn more.";
    }

    // ─── Whale Alerts (Helius) ────────────────────────────────────────────────

    public static function getRecentWhales(string $heliusKey, int $minSol = 10000, string $network = 'devnet'): array
    {
        if ($network !== 'mainnet') return ['mainnet_only' => true];
        if (empty($heliusKey)) return ['error' => 'Helius API key not configured — add it in config/config.php'];
        try {
            $url  = self::HELIUS_BASE . "/addresses/So11111111111111111111111111111111111111112/transactions?api-key={$heliusKey}&limit=10&type=TRANSFER";
            $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: SolanaAgent/1.0\r\n", 'timeout' => 8]];
            $res  = @file_get_contents($url, false, stream_context_create($opts));
            if (!$res) return ['error' => 'No data'];
            $txs   = json_decode($res, true) ?? [];
            $wales = [];
            foreach ($txs as $tx) {
                $amt = abs((float)($tx['nativeTransfers'][0]['amount'] ?? 0)) / 1e9;
                if ($amt >= $minSol) {
                    $wales[] = [
                        'amount'    => $amt,
                        'signature' => $tx['signature'] ?? '',
                        'timestamp' => $tx['timestamp'] ?? 0,
                        'from'      => $tx['nativeTransfers'][0]['fromUserAccount'] ?? '',
                        'to'        => $tx['nativeTransfers'][0]['toUserAccount'] ?? '',
                    ];
                }
            }
            return ['whales' => $wales, 'threshold' => $minSol];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public static function formatWhales(array $data): string
    {
        if (!empty($data['mainnet_only'])) return "🐋 <b>Whale Alerts</b>\n\nWhale tracking uses Helius and only works on <b>mainnet</b>.\nYou're currently on devnet.\n\nSwitch to mainnet and add a Helius API key in <code>config/config.php</code> to enable this.";
        if (isset($data['error'])) return "❌ Whale tracker: " . $data['error'];
        $whales = $data['whales'] ?? [];
        if (empty($whales)) return "🐋 No large transfers (>{$data['threshold']} SOL) found in recent transactions.";

        $msg = "🐋 <b>Recent Whale Transfers</b> (>{$data['threshold']} SOL)\n\n";
        foreach (array_slice($whales, 0, 5) as $w) {
            $time  = $w['timestamp'] ? date('H:i', $w['timestamp']) : '?';
            $from  = substr($w['from'], 0, 6) . '…' . substr($w['from'], -4);
            $to    = substr($w['to'],   0, 6) . '…' . substr($w['to'],   -4);
            $msg  .= "💰 <b>" . number_format($w['amount'], 0) . " SOL</b>\n";
            $msg  .= "  {$from} → {$to} at {$time}\n\n";
        }
        return $msg;
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private static function progressBar(int $value, int $max, int $width): string
    {
        $filled = (int)round($value / $max * $width);
        return '[' . str_repeat('█', $filled) . str_repeat('░', $width - $filled) . ']';
    }
}
