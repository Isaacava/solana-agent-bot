<?php
namespace SolanaAgent\Features;

use SolanaAgent\Storage\Database;
use SolanaAgent\Features\BalanceGuard;
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Utils\{Logger, Crypto};

/**
 * Trading Strategies — autonomous buy/sell/stop-loss engine
 *
 * Strategy Types:
 *   CONSERVATIVE  — sideways market, safe dip-buy
 *   AGGRESSIVE    — notable dip, deeper entry, bigger target
 *   SCALP         — mild pump, quick small profits
 *   MOMENTUM      — strong pump, ride the wave
 *   DEEP_VALUE    — market crash, maximum accumulation
 *
 * Market Condition → Recommended Strategy:
 *   < -7%   → DEEP_VALUE   (market crashed — load up)
 *   -7% to -3% → AGGRESSIVE (notable dip — buy in)
 *   -3% to +2% → CONSERVATIVE (sideways — safe entry)
 *   +2% to +7% → SCALP (pumping — quick profits)
 *   > +7%   → MOMENTUM    (strong bull — ride the wave)
 *
 * Phases:
 *   waiting_buy → price hits buy_price → swap USDC→SOL
 *   holding     → watching sell_price or stop_loss
 *   completed   → sold at profit
 *   stopped     → sold at stop loss
 *   cancelled   → user or system cancelled
 */
class Strategy
{
    // ─── Strategy type definitions ────────────────────────────────────────────

    const TYPES = [
        'CONSERVATIVE' => [
            'name'        => 'Conservative',
            'emoji'       => '🛡️',
            'tagline'     => 'Safe dip-buy — steady growth, protected downside',
            'dip_entry'   => 0.025,   // buy 2.5% below current
            'profit_pct'  => 0.070,   // sell 7% above entry
            'stop_pct'    => 0.020,   // stop 2% below entry
            'best_when'   => 'Sideways or mildly falling market',
        ],
        'AGGRESSIVE' => [
            'name'        => 'Aggressive',
            'emoji'       => '🔥',
            'tagline'     => 'Deeper entry on the dip — big reward, managed risk',
            'dip_entry'   => 0.040,   // buy 4% below current
            'profit_pct'  => 0.150,   // sell 15% above entry
            'stop_pct'    => 0.030,   // stop 3% below entry
            'best_when'   => 'Market down 3–7% — notable dip, likely to bounce',
        ],
        'SCALP' => [
            'name'        => 'Scalp',
            'emoji'       => '⚡',
            'tagline'     => 'Quick in and out — small but fast profits',
            'dip_entry'   => 0.008,   // buy 0.8% below current
            'profit_pct'  => 0.025,   // sell 2.5% above entry
            'stop_pct'    => 0.008,   // stop 0.8% below entry
            'best_when'   => 'Market rising steadily — scalp momentum bounces',
        ],
        'MOMENTUM' => [
            'name'        => 'Momentum',
            'emoji'       => '🚀',
            'tagline'     => 'Ride the wave — buy strength, sell higher',
            'dip_entry'   => 0.005,   // buy just 0.5% below current (almost market price)
            'profit_pct'  => 0.100,   // sell 10% above entry
            'stop_pct'    => 0.025,   // stop 2.5% below entry
            'best_when'   => 'Market up 7%+ — strong bull momentum',
        ],
        'DEEP_VALUE' => [
            'name'        => 'Deep Value',
            'emoji'       => '💎',
            'tagline'     => 'Buy the crash — accumulate at maximum discount',
            'dip_entry'   => 0.020,   // buy 2% further from current (already crashed)
            'profit_pct'  => 0.200,   // sell 20% above entry
            'stop_pct'    => 0.050,   // stop 5% below entry (wider — volatile conditions)
            'best_when'   => 'Market down 7%+ — fear is the entry signal',
        ],
    ];

    // Order strategies are cycled through on "show another"
    const ROTATION_ORDER = ['CONSERVATIVE', 'AGGRESSIVE', 'SCALP', 'MOMENTUM', 'DEEP_VALUE'];

    public function __construct(
        private Database      $db,
        private WalletManager $walletManager,
        private Telegram      $telegram,
        private array         $config
    ) {}

    // ─── Market condition analysis ────────────────────────────────────────────

    /**
     * Determine the best strategy type based on 24h price change.
     * Returns the strategy key (CONSERVATIVE, AGGRESSIVE, etc.)
     */
    public static function recommendType(float $change24h): string
    {
        if ($change24h < -7.0)  return 'DEEP_VALUE';
        if ($change24h < -3.0)  return 'AGGRESSIVE';
        if ($change24h < 2.0)   return 'CONSERVATIVE';
        if ($change24h < 7.0)   return 'SCALP';
        return 'MOMENTUM';
    }

    /**
     * Human-readable market condition summary.
     */
    public static function describeMarket(float $change24h): string
    {
        if ($change24h < -7.0)  return "🔴 Market is crashing ({$change24h}% today)";
        if ($change24h < -3.0)  return "📉 Market is dipping ({$change24h}% today)";
        if ($change24h < 0.0)   return "😐 Market slightly down ({$change24h}% today)";
        if ($change24h < 2.0)   return "😐 Market is sideways ({$change24h}% today)";
        if ($change24h < 7.0)   return "📈 Market is rising ({$change24h}% today)";
        return "🟢 Market is pumping ({$change24h}% today)";
    }

    // ─── Strategy generation ──────────────────────────────────────────────────

    /**
     * Generate strategy params for a given type and current price.
     */
    public static function generateByType(float $currentPrice, string $type): array
    {
        $def       = self::TYPES[$type] ?? self::TYPES['CONSERVATIVE'];
        $buyPrice  = round($currentPrice * (1 - $def['dip_entry']), 2);
        $sellPrice = round($buyPrice     * (1 + $def['profit_pct']), 2);
        $stopLoss  = round($buyPrice     * (1 - $def['stop_pct']), 2);
        $profitPct = round($def['profit_pct'] * 100, 1);
        $lossPct   = round(-$def['stop_pct'] * 100, 1);
        $rr        = round($def['profit_pct'] / $def['stop_pct'], 1);

        return [
            'type'           => $type,
            'type_name'      => $def['name'],
            'emoji'          => $def['emoji'],
            'tagline'        => $def['tagline'],
            'best_when'      => $def['best_when'],
            'current_price'  => $currentPrice,
            'buy_price'      => $buyPrice,
            'sell_price'     => $sellPrice,
            'stop_loss'      => $stopLoss,
            'est_profit_pct' => $profitPct,
            'est_loss_pct'   => $lossPct,
            'risk_reward'    => $rr,
        ];
    }

    /**
     * Original single-type generation kept for backward compat.
     */
    public static function generateSuggestion(float $currentPrice): array
    {
        return self::generateByType($currentPrice, 'CONSERVATIVE');
    }

    // ─── Formatting ───────────────────────────────────────────────────────────

    /**
     * Format a strategy suggestion as a Telegram message.
     * Includes market context and which strategy this is out of 5.
     */
    public static function formatSuggestion(
        array  $s,
        float  $amountSol,
        float  $change24h = 0.0,
        int    $index     = 1,   // which suggestion (1-5) for "show another" tracking
        bool   $isRecommended = true
    ): string {
        $amountUsd   = round($amountSol * $s['current_price'], 2);
        $marketDesc  = self::describeMarket($change24h);
        $recTag      = $isRecommended ? ' ← <b>recommended for current market</b>' : '';
        $totalTypes  = count(self::TYPES);

        $msg  = "{$s['emoji']} <b>Strategy {$index}/{$totalTypes} — {$s['type_name']}</b>{$recTag}\n";
        $msg .= "<i>{$s['tagline']}</i>\n";
        $msg .= "─────────────────────\n";
        $msg .= "📊 Market: {$marketDesc}\n";
        $msg .= "💰 SOL now: <b>\${$s['current_price']}</b>\n\n";
        $msg .= "🟢 Buy at:    <b>\${$s['buy_price']}</b>\n";
        $msg .= "🎯 Sell at:   <b>\${$s['sell_price']}</b>  (+{$s['est_profit_pct']}%)\n";
        $msg .= "🛑 Stop loss: <b>\${$s['stop_loss']}</b>  ({$s['est_loss_pct']}%)\n\n";
        $msg .= "📈 Risk/Reward: <b>1:{$s['risk_reward']}</b>\n";
        $msg .= "💸 Trading: <b>{$amountSol} SOL</b> (~\${$amountUsd})\n";
        $msg .= "✅ Best when: <i>{$s['best_when']}</i>\n\n";
        $msg .= "Say <b>YES / Oya run am / activate</b> to start this 🤖\n";
        if ($index < $totalTypes) {
            $msg .= "Or say <b>\"show another strategy\"</b> to see the next option.";
        } else {
            $msg .= "That's all 5 strategies! Say <b>\"show me from the start\"</b> to cycle again.";
        }

        return $msg;
    }

    /**
     * Format just the active strategy status line.
     */
    public function formatActive(array $s): string
    {
        $phase = match($s['phase']) {
            'waiting_buy' => "⏳ Waiting to buy below \${$s['buy_price']}",
            'holding'     => "📦 Holding — watching for \${$s['sell_price']} or stop \${$s['stop_loss']}",
            default       => $s['phase'],
        };
        $type = $s['strategy_type'] ?? 'CONSERVATIVE';
        $def  = self::TYPES[$type] ?? self::TYPES['CONSERVATIVE'];
        return "{$def['emoji']} <b>{$s['label']}</b> [#{$s['id']}] — {$def['name']}\n"
            . "{$phase}\n"
            . "🟢 Buy: \${$s['buy_price']}  🎯 Sell: \${$s['sell_price']}  🛑 Stop: \${$s['stop_loss']}\n"
            . "💸 Amount: {$s['amount_sol']} SOL  📈 Target: +{$s['est_profit_pct']}%";
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function create(
        int    $userId,
        string $telegramId,
        float  $buyPrice,
        float  $sellPrice,
        float  $stopLoss,
        float  $amountSol,
        string $label        = '',
        string $strategyType = 'CONSERVATIVE'
    ): int {
        $estProfit = round((($sellPrice - $buyPrice) / $buyPrice) * 100, 1);
        $def       = self::TYPES[$strategyType] ?? self::TYPES['CONSERVATIVE'];

        $id = $this->db->createStrategy([
            'user_id'        => $userId,
            'telegram_id'    => $telegramId,
            'label'          => $label ?: "{$def['name']} Strategy #{$userId}",
            'status'         => 'active',
            'buy_price'      => $buyPrice,
            'sell_price'     => $sellPrice,
            'stop_loss'      => $stopLoss,
            'amount_sol'     => $amountSol,
            'phase'          => 'waiting_buy',
            'est_profit_pct' => $estProfit,
            'strategy_type'  => $strategyType,
        ]);

        Logger::info("Strategy #{$id} ({$strategyType}) created", [
            'user'   => $userId,
            'buy'    => $buyPrice,
            'sell'   => $sellPrice,
            'stop'   => $stopLoss,
            'amount' => $amountSol,
        ]);

        return $id;
    }

    // ─── Cron monitoring ──────────────────────────────────────────────────────

    public function checkAll(float $currentPrice): void
    {
        $strategies = $this->db->getActiveStrategies();
        foreach ($strategies as $s) {
            try {
                $this->checkOne($s, $currentPrice);
            } catch (\Throwable $e) {
                Logger::error("Strategy #{$s['id']} check failed: " . $e->getMessage());
            }
        }
    }

    private function checkOne(array $s, float $price): void
    {
        if ($s['phase'] === 'waiting_buy') {
            if ($price <= (float)$s['buy_price']) {
                $this->executeBuy($s, $price);
            }
        } elseif ($s['phase'] === 'holding') {
            if ($price >= (float)$s['sell_price']) {
                $this->executeSell($s, $price, 'profit');
            } elseif (!empty($s['stop_loss']) && $price <= (float)$s['stop_loss']) {
                $this->executeSell($s, $price, 'stop_loss');
            }
        }
    }

    private function executeBuy(array $s, float $price): void
    {
        $userId     = (int)$s['user_id'];
        $telegramId = (int)$s['telegram_id'];
        $type       = $s['strategy_type'] ?? 'CONSERVATIVE';
        $def        = self::TYPES[$type] ?? self::TYPES['CONSERVATIVE'];

        $this->telegram->sendMessage($telegramId,
            "{$def['emoji']} <b>{$def['name']} Strategy #{$s['id']} — Buy triggered!</b>\n\n"
            . "📉 SOL hit <b>\${$price}</b> (buy target: \${$s['buy_price']})\n"
            . "🔄 Swapping USDC → <b>{$s['amount_sol']} SOL</b> now…");

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->notifyFailed($telegramId, $s['id'], 'No active wallet found.');
            return;
        }

        try {
            $keypair  = $this->walletManager->getKeypair($wallet);
            $crypto   = new Crypto($this->config['security']['encryption_key']);
            $network  = $this->config['solana']['network'];
            $swap     = new Swap($this->walletManager->getRpc(), $this->db, $crypto, $network);
            $spl      = new SPLToken($this->walletManager->getRpc(), $network, $this->config);

            $usdcNeeded = $s['amount_sol'] * $price;
            $usdcBal    = $spl->getUsdcBalance($wallet['public_key']);

            if ($usdcBal < $usdcNeeded) {
                $this->db->updateStrategy($s['id'], ['status' => 'cancelled', 'phase' => 'cancelled']);
                $this->telegram->sendMessage($telegramId,
                    "🤖 <b>{$def['name']} Strategy #{$s['id']} cancelled — not enough USDC</b>\n\n"
                    . "Your buy condition (SOL ≤ \${$s['buy_price']}) was met but you only have "
                    . "<b>" . number_format($usdcBal, 2) . " USDC</b>. "
                    . "I need <b>" . number_format($usdcNeeded, 2) . " USDC</b>.\n\n"
                    . "Top up your USDC and I'll set up a fresh strategy for you. 💪");
                return;
            }

            $quoteResult = $swap->getQuote('USDC', 'SOL', $usdcNeeded);
            if (!$quoteResult['ok']) {
                $this->notifyFailed($telegramId, $s['id'], $quoteResult['error'] ?? 'Quote failed');
                return;
            }

            $execResult = $swap->executeSwap($quoteResult['data'], $keypair, $wallet['public_key']);
            if (!$execResult['success']) {
                $this->notifyFailed($telegramId, $s['id'], $execResult['error'] ?? 'Swap failed');
                return;
            }

            $sig     = $execResult['signature'];
            $cluster = $network === 'mainnet' ? '' : '?cluster=devnet';

            $this->db->updateStrategy($s['id'], [
                'phase'        => 'holding',
                'buy_tx'       => $sig,
                'triggered_at' => date('Y-m-d H:i:s'),
            ]);

            $this->telegram->sendMessage($telegramId,
                "✅ <b>{$def['name']} Strategy #{$s['id']} — Bought!</b>\n\n"
                . "💰 Bought at: <b>\${$price}</b>\n"
                . "💸 Spent: ~<b>" . number_format($usdcNeeded, 2) . " USDC</b>\n"
                . "📦 Holding {$s['amount_sol']} SOL\n\n"
                . "🎯 Selling at: <b>\${$s['sell_price']}</b>\n"
                . "🛑 Stop loss: <b>\${$s['stop_loss']}</b>\n\n"
                . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View TX</a>\n\n"
                . "I'll keep watching and sell automatically. 🤖");

        } catch (\Throwable $e) {
            $this->notifyFailed($telegramId, $s['id'], $e->getMessage());
            Logger::error("Strategy #{$s['id']} buy failed: " . $e->getMessage());
        }
    }

    private function executeSell(array $s, float $price, string $reason): void
    {
        $userId     = (int)$s['user_id'];
        $telegramId = (int)$s['telegram_id'];
        $type       = $s['strategy_type'] ?? 'CONSERVATIVE';
        $def        = self::TYPES[$type] ?? self::TYPES['CONSERVATIVE'];

        $emoji  = $reason === 'profit' ? '🎉' : '🛑';
        $title  = $reason === 'profit' ? 'Profit target hit!' : 'Stop loss triggered!';

        $this->telegram->sendMessage($telegramId,
            "{$emoji} <b>{$def['name']} Strategy #{$s['id']} — {$title}</b>\n\n"
            . "📊 SOL at: <b>\${$price}</b>\n"
            . "🔄 Selling <b>{$s['amount_sol']} SOL</b> → USDC now…");

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->notifyFailed($telegramId, $s['id'], 'No active wallet found.');
            return;
        }

        try {
            $keypair = $this->walletManager->getKeypair($wallet);
            $crypto  = new Crypto($this->config['security']['encryption_key']);
            $network = $this->config['solana']['network'];
            $swap    = new Swap($this->walletManager->getRpc(), $this->db, $crypto, $network);

            $quoteResult = $swap->getQuote('SOL', 'USDC', (float)$s['amount_sol']);
            if (!$quoteResult['ok']) {
                $this->notifyFailed($telegramId, $s['id'], $quoteResult['error'] ?? 'Quote failed');
                return;
            }

            $execResult = $swap->executeSwap($quoteResult['data'], $keypair, $wallet['public_key']);
            if (!$execResult['success']) {
                $this->notifyFailed($telegramId, $s['id'], $execResult['error'] ?? 'Swap failed');
                return;
            }

            $sig       = $execResult['signature'];
            $cluster   = $network === 'mainnet' ? '' : '?cluster=devnet';
            $buyPrice  = (float)$s['buy_price'];
            $pnlPct    = round((($price - $buyPrice) / $buyPrice) * 100, 2);
            $pnlEmoji  = $pnlPct >= 0 ? '📈' : '📉';
            $newStatus = $reason === 'profit' ? 'completed' : 'stopped';

            $this->db->updateStrategy($s['id'], [
                'status'  => $newStatus,
                'phase'   => $newStatus,
                'sell_tx' => $sig,
            ]);

            $this->telegram->sendMessage($telegramId,
                "{$emoji} <b>{$def['name']} Strategy #{$s['id']} — Done!</b>\n\n"
                . "🟢 Bought at: <b>\${$buyPrice}</b>\n"
                . "🔴 Sold at:   <b>\${$price}</b>\n"
                . "{$pnlEmoji} P&L: <b>" . ($pnlPct >= 0 ? '+' : '') . "{$pnlPct}%</b>\n\n"
                . ($reason === 'profit'
                    ? "Profit secured! Well done 🎉"
                    : "Stop loss protected your position 🛡️") . "\n\n"
                . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View TX</a>\n\n"
                . "Say <b>\"grow my SOL\"</b> to run another strategy. 🤖");

        } catch (\Throwable $e) {
            $this->notifyFailed($telegramId, $s['id'], $e->getMessage());
            Logger::error("Strategy #{$s['id']} sell failed: " . $e->getMessage());
        }
    }

    private function notifyFailed(int $telegramId, int $strategyId, string $reason): void
    {
        $this->telegram->sendMessage($telegramId,
            "❌ <b>Strategy #{$strategyId} — Action failed</b>\n\n"
            . htmlspecialchars($reason) . "\n\n"
            . "Strategy is still active — I'll retry next tick.");
    }
}


// ─── TrailingStop — standalone helper ────────────────────────────────────────

/**
 * Trailing stop logic — tracked in settings table.
 * Updates the stop_loss in trading_strategies as price rises.
 */
class TrailingStop
{
    public static function update(Database $db, WalletManager $wm, Telegram $tg, float $price): void
    {
        // Find holding strategies that have a trailing_pct set
        $rows = $db->fetchAll(
            "SELECT s.*, se.value as trailing_cfg
             FROM trading_strategies s
             LEFT JOIN settings se ON se.key_name = CONCAT('trailing_', s.id)
             WHERE s.phase = 'holding' AND s.status = 'active'"
        );

        foreach ($rows as $row) {
            if (empty($row['trailing_cfg'])) continue;
            $cfg = json_decode($row['trailing_cfg'], true) ?? [];
            $trailingPct = (float)($cfg['pct'] ?? 0);
            if ($trailingPct <= 0) continue;

            $peakPrice   = max((float)($cfg['peak'] ?? 0), $price);
            $newStop     = round($peakPrice * (1 - $trailingPct / 100), 2);
            $currentStop = (float)$row['stop_loss'];

            // Only move stop UP, never down
            if ($newStop > $currentStop) {
                $db->update('trading_strategies', ['stop_loss' => $newStop], ['id' => $row['id']]);
                $telegramId = (int)$row['telegram_id'];
                $tg->sendMessage($telegramId,
                    "📈 <b>Trailing stop updated — Strategy #{$row['id']}</b>\n\n"
                    . "🔝 Peak price: <b>\${$peakPrice}</b>\n"
                    . "🛑 New stop loss: <b>\${$newStop}</b> (+{$trailingPct}% trail)\n"
                    . "Your gains are being protected automatically 🛡️");
            }

            // Update peak
            if ($price > (float)($cfg['peak'] ?? 0)) {
                $cfg['peak'] = $price;
                $db->query(
                    "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                    ["trailing_{$row['id']}", json_encode($cfg)]
                );
            }
        }
    }

    public static function enable(Database $db, int $strategyId, float $trailingPct, float $currentPrice): void
    {
        $db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
            ["trailing_{$strategyId}", json_encode(['pct' => $trailingPct, 'peak' => $currentPrice])]
        );
    }
}


// ─── PriceCascade — multi-target sell ─────────────────────────────────────────

/**
 * Cascade sell — sell portions of SOL at multiple price targets.
 * Stored in settings as cascade_{userId}.
 *
 * Example:
 *   Sell 30% at $120, 30% at $140, 40% at $160
 */
class PriceCascade
{
    public static function create(Database $db, int $userId, string $telegramId, array $targets): void
    {
        // targets = [['price' => 120, 'pct' => 30], ['price' => 140, 'pct' => 30], ...]
        $db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
            [
                "cascade_{$userId}",
                json_encode([
                    'telegram_id' => $telegramId,
                    'targets'     => $targets,
                    'active'      => true,
                ])
            ]
        );
    }

    public static function check(Database $db, WalletManager $wm, Telegram $tg, array $config, float $price): void
    {
        $rows = $db->fetchAll("SELECT key_name, value FROM settings WHERE key_name LIKE 'cascade_%'");
        foreach ($rows as $row) {
            $data = json_decode($row['value'], true) ?? [];
            if (empty($data['active'])) continue;

            $userId     = (int)str_replace('cascade_', '', $row['key_name']);
            $telegramId = (int)($data['telegram_id'] ?? 0);
            $targets    = $data['targets'] ?? [];
            $modified   = false;

            foreach ($targets as $i => &$target) {
                if (!empty($target['executed'])) continue;
                if ($price < (float)$target['price']) continue;

                // Execute sell
                $pct    = (float)$target['pct'];
                $wallet = $db->getActiveWallet($userId);
                if (!$wallet) continue;

                try {
                    $bal     = $wm->getBalance($wallet['public_key']);
                    $solBal  = (float)($bal['sol'] ?? 0);
                    $sellAmt = round($solBal * ($pct / 100), 6);

                    if ($sellAmt < 0.001) {
                        $target['executed'] = true;
                        $modified           = true;
                        continue;
                    }

                    $network     = $config['solana']['network'];
                    $crypto      = new \SolanaAgent\Utils\Crypto($config['security']['encryption_key']);
                    $swap        = new Swap($wm->getRpc(), $db, $crypto, $network);
                    $keypair     = $wm->getKeypair($wallet);
                    $quoteResult = $swap->getQuote('SOL', 'USDC', $sellAmt);

                    if (!$quoteResult['ok']) continue;

                    $result  = $swap->executeSwap($quoteResult['data'], $keypair, $wallet['public_key']);
                    if (!$result['success']) continue;

                    $cluster = $network === 'mainnet' ? '' : '?cluster=devnet';
                    $sig     = $result['signature'];

                    $tg->sendMessage($telegramId,
                        "🎯 <b>Cascade target hit!</b>\n\n"
                        . "💰 SOL price: <b>\${$price}</b>\n"
                        . "📤 Sold: <b>{$pct}%</b> of your SOL ({$sellAmt} SOL)\n"
                        . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View TX</a>\n\n"
                        . (count(array_filter($targets, fn($t) => empty($t['executed']))) > 1
                            ? "⏭️ Watching for remaining targets…"
                            : "✅ All cascade targets executed!"));

                    $target['executed'] = true;
                    $modified = true;

                } catch (\Throwable $e) {
                    \SolanaAgent\Utils\Logger::error("Cascade sell error: " . $e->getMessage());
                }
            }
            unset($target);

            if ($modified) {
                // Check if all done
                $allDone = !array_filter($targets, fn($t) => empty($t['executed']));
                if ($allDone) $data['active'] = false;
                $data['targets'] = $targets;
                $db->query(
                    "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                    [$row['key_name'], json_encode($data)]
                );
            }
        }
    }

    public static function format(array $targets, float $currentPrice): string
    {
        $msg = "🎯 <b>Price Cascade</b>\n\n";
        foreach ($targets as $t) {
            $done  = !empty($t['executed']);
            $icon  = $done ? '✅' : ($currentPrice >= $t['price'] ? '🔄' : '⏳');
            $msg  .= "{$icon} Sell <b>{$t['pct']}%</b> at <b>\${$t['price']}</b>\n";
        }
        return $msg;
    }
}
