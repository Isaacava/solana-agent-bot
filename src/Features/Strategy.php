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
 * Phases:
 *   waiting_buy  → price drops to buy_price → swap USDC→SOL
 *   holding      → bought, watching for sell_price or stop_loss
 *   completed    → sold at profit (sell_price hit)
 *   stopped      → sold at stop_loss
 *   cancelled    → user cancelled
 */
class Strategy
{
    public function __construct(
        private Database      $db,
        private WalletManager $walletManager,
        private Telegram      $telegram,
        private array         $config
    ) {}

    // ─── Strategy generation (AI feeds this data) ─────────────────────────────

    /**
     * Generate a suggested strategy based on current SOL price.
     * Returns data for the AI to format and present to the user.
     */
    public static function generateSuggestion(float $currentPrice): array
    {
        // Conservative: buy ~2% below current, sell ~7% above entry, stop 2% below entry
        $buyPrice  = round($currentPrice * 0.975, 2);   // 2.5% dip entry
        $sellPrice = round($buyPrice     * 1.070, 2);   // 7% profit target
        $stopLoss  = round($buyPrice     * 0.980, 2);   // 2% stop loss
        $estProfit = round((($sellPrice - $buyPrice) / $buyPrice) * 100, 1);
        $estLoss   = round((($stopLoss  - $buyPrice) / $buyPrice) * 100, 1);

        return [
            'current_price' => $currentPrice,
            'buy_price'     => $buyPrice,
            'sell_price'    => $sellPrice,
            'stop_loss'     => $stopLoss,
            'est_profit_pct'=> $estProfit,
            'est_loss_pct'  => $estLoss,
            'risk_reward'   => round($estProfit / abs($estLoss), 1),
        ];
    }

    /**
     * Format a strategy suggestion as a Telegram message.
     */
    public static function formatSuggestion(array $s, float $amountSol): string
    {
        $amountUsd = round($amountSol * $s['current_price'], 2);
        return "📊 <b>Strategy Suggestion</b>\n"
            . "─────────────────────\n"
            . "💰 SOL now: <b>\${$s['current_price']}</b>\n\n"
            . "🟢 Buy at:    <b>\${$s['buy_price']}</b>  (wait for dip)\n"
            . "🎯 Sell at:   <b>\${$s['sell_price']}</b>  (+{$s['est_profit_pct']}% profit)\n"
            . "🛑 Stop loss: <b>\${$s['stop_loss']}</b>  ({$s['est_loss_pct']}% max loss)\n\n"
            . "📈 Risk/Reward: <b>1:{$s['risk_reward']}</b>\n"
            . "💸 Trading: <b>{$amountSol} SOL</b> (~\${$amountUsd})\n\n"
            . "Reply <b>YES</b> to activate — I'll run this automatically 🤖\n"
            . "Or say <i>\"set buy at $X, sell at $Y\"</i> to customise.";
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function create(
        int    $userId,
        string $telegramId,
        float  $buyPrice,
        float  $sellPrice,
        float  $stopLoss,
        float  $amountSol,
        string $label = ''
    ): int {
        $estProfit = round((($sellPrice - $buyPrice) / $buyPrice) * 100, 1);

        $id = $this->db->createStrategy([
            'user_id'        => $userId,
            'telegram_id'    => $telegramId,
            'label'          => $label ?: "Strategy #{$userId}",
            'status'         => 'active',
            'buy_price'      => $buyPrice,
            'sell_price'     => $sellPrice,
            'stop_loss'      => $stopLoss,
            'amount_sol'     => $amountSol,
            'phase'          => 'waiting_buy',
            'est_profit_pct' => $estProfit,
        ]);

        Logger::info("Strategy #{$id} created", [
            'user'   => $userId,
            'buy'    => $buyPrice,
            'sell'   => $sellPrice,
            'stop'   => $stopLoss,
            'amount' => $amountSol,
        ]);

        return $id;
    }

    public function formatActive(array $s): string
    {
        $phase = match($s['phase']) {
            'waiting_buy' => "⏳ Waiting to buy below \${$s['buy_price']}",
            'holding'     => "📦 Holding — watching for \${$s['sell_price']} or stop \${$s['stop_loss']}",
            default       => $s['phase'],
        };
        return "🤖 <b>{$s['label']}</b> [#{$s['id']}]\n"
            . "{$phase}\n"
            . "🟢 Buy: \${$s['buy_price']}  🎯 Sell: \${$s['sell_price']}  🛑 Stop: \${$s['stop_loss']}\n"
            . "💸 Amount: {$s['amount_sol']} SOL  📈 Target: +{$s['est_profit_pct']}%";
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
        $phase = $s['phase'];

        if ($phase === 'waiting_buy') {
            // Trigger buy when price drops to or below buy_price
            if ($price <= (float)$s['buy_price']) {
                $this->executeBuy($s, $price);
            }
        } elseif ($phase === 'holding') {
            // Trigger sell at profit
            if ($price >= (float)$s['sell_price']) {
                $this->executeSell($s, $price, 'profit');
            // Trigger stop loss
            } elseif (!empty($s['stop_loss']) && $price <= (float)$s['stop_loss']) {
                $this->executeSell($s, $price, 'stop_loss');
            }
        }
    }

    private function executeBuy(array $s, float $price): void
    {
        $userId     = (int)$s['user_id'];
        $telegramId = (int)$s['telegram_id'];

        $this->telegram->sendMessage($telegramId,
            "🤖 <b>Strategy #{$s['id']} — Buy triggered!</b>\n\n"
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

            // Check USDC balance
            $usdcNeeded = $s['amount_sol'] * $price;
            $usdcBal    = $spl->getUsdcBalance($wallet['public_key']);
            if ($usdcBal < $usdcNeeded) {
                // Cancel strategy and notify — don't keep silently skipping
                $this->db->updateStrategy($s['id'], ['status' => 'cancelled', 'phase' => 'cancelled']);
                $this->telegram->sendMessage($telegramId,
                    "🤖 <b>Strategy #{$s['id']} cancelled — not enough USDC</b>\n\n"
                    . "Your buy condition (SOL ≤ \${$s['buy_price']}) was met but I checked your wallet "
                    . "and you only have <b>" . number_format($usdcBal, 2) . " USDC</b>. "
                    . "I need <b>" . number_format($usdcNeeded, 2) . " USDC</b> to buy "
                    . "{$s['amount_sol']} SOL.\n\n"
                    . "I've cancelled this strategy. Once you top up your USDC, tell me to set up a new strategy and I'll run it for you. 💪");
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
                'phase'       => 'holding',
                'buy_tx'      => $sig,
                'triggered_at'=> date('Y-m-d H:i:s'),
            ]);

            $this->telegram->sendMessage($telegramId,
                "✅ <b>Strategy #{$s['id']} — Bought!</b>\n\n"
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

        $emoji   = $reason === 'profit' ? '🎉' : '🛑';
        $title   = $reason === 'profit' ? 'Profit target hit!' : 'Stop loss triggered!';

        $this->telegram->sendMessage($telegramId,
            "{$emoji} <b>Strategy #{$s['id']} — {$title}</b>\n\n"
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
                "{$emoji} <b>Strategy #{$s['id']} — Done!</b>\n\n"
                . "🟢 Bought at: <b>\${$buyPrice}</b>\n"
                . "🔴 Sold at:   <b>\${$price}</b>\n"
                . "{$pnlEmoji} P&L: <b>" . ($pnlPct >= 0 ? '+' : '') . "{$pnlPct}%</b>\n\n"
                . ($reason === 'profit'
                    ? "Profit secured! Well done 🎉"
                    : "Stop loss protected your position 🛡️") . "\n\n"
                . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View TX</a>");

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
            . "Strategy is still active — I'll retry next minute.");
    }
}