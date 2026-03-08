<?php
namespace SolanaAgent\Features;

use SolanaAgent\Storage\Database;
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Utils\{Logger, Crypto};
use SolanaAgent\Features\{Swap, SPLToken, Strategy, BalanceGuard};

/**
 * Scheduler — autonomous task engine
 *
 * 1. Price alerts       — notify when SOL hits a price
 * 2. Scheduled sends    — send SOL at a specific time
 * 3. Conditional tasks  — if price hits X → execute action
 *    Actions: send_sol | swap_sol_usdc | swap_usdc_sol
 */
class Scheduler
{
    private Database      $db;
    private WalletManager $walletManager;
    private Telegram      $telegram;
    private array         $config;

    public function __construct(Database $db, WalletManager $walletManager, Telegram $telegram, array $config)
    {
        $this->db            = $db;
        $this->walletManager = $walletManager;
        $this->telegram      = $telegram;
        $this->config        = $config;
    }

    public function run(): void
    {
        $currentPrice = null;
        try {
            $price        = Price::getSolPrice();
            $currentPrice = (float)$price['usd'];
        } catch (\Throwable $e) {
            Logger::warn('Price fetch failed in scheduler: ' . $e->getMessage());
        }

        if ($currentPrice !== null) {
            $this->checkPriceAlerts($currentPrice);
            $this->checkConditionalTasks($currentPrice);
            $this->checkTradingStrategies($currentPrice);
        }

        $this->executeScheduledTasks();

        // Proactive: warn users about upcoming tasks they may lack funds for
        if ($currentPrice !== null) {
            $guard = new BalanceGuard($this->db, $this->walletManager, $this->telegram, $this->config);
            $guard->runProactiveChecks($currentPrice);
        }
    }

    // ─── Price Alerts ─────────────────────────────────────────────────────────

    public function addPriceAlert(int $userId, string $telegramId, float $targetPrice, string $direction): int
    {
        return $this->db->insert('price_alerts', [
            'user_id'      => $userId,
            'telegram_id'  => $telegramId,
            'target_price' => $targetPrice,
            'direction'    => $direction,
        ]);
    }

    public function listAlerts(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM price_alerts WHERE user_id=? AND triggered=0 ORDER BY id DESC', [$userId]);
    }

    public function cancelAlert(int $alertId, int $userId): bool
    {
        return $this->db->update('price_alerts', ['triggered' => 1], ['id' => $alertId, 'user_id' => $userId]) > 0;
    }

    private function checkPriceAlerts(float $currentPrice): void
    {
        $alerts = $this->db->fetchAll('SELECT * FROM price_alerts WHERE triggered=0');
        foreach ($alerts as $alert) {
            $hit = ($alert['direction'] === 'above' && $currentPrice >= $alert['target_price'])
                || ($alert['direction'] === 'below' && $currentPrice <= $alert['target_price']);
            if ($hit) {
                $this->db->update('price_alerts', ['triggered' => 1], ['id' => $alert['id']]);
                $this->sendPriceAlertNotification($alert, $currentPrice);
            }
        }
    }

    private function sendPriceAlertNotification(array $alert, float $currentPrice): void
    {
        $dir     = $alert['direction'] === 'above' ? '📈 above' : '📉 below';
        $target  = '$' . number_format($alert['target_price'], 2);
        $current = '$' . number_format($currentPrice, 2);
        $msg  = "🚨 <b>Price Alert Triggered!</b>\n\n";
        $msg .= "SOL is now {$dir} your target!\n\n";
        $msg .= "🎯 Target: <b>{$target}</b>\n";
        $msg .= "💰 Current: <b>{$current}</b>\n\n";
        $msg .= "Use /price to see full details.";
        try { $this->telegram->sendMessage((int)$alert['telegram_id'], $msg); }
        catch (\Throwable $e) { Logger::error('Price alert send failed: ' . $e->getMessage()); }
    }

    // ─── Conditional Tasks ────────────────────────────────────────────────────

    public function addConditionalTask(
        int    $userId,
        string $telegramId,
        string $conditionType,
        float  $conditionValue,
        string $actionType,
        array  $actionPayload,
        string $label = ''
    ): int {
        return $this->db->insert('conditional_tasks', [
            'user_id'         => $userId,
            'telegram_id'     => $telegramId,
            'condition_type'  => $conditionType,
            'condition_value' => $conditionValue,
            'action_type'     => $actionType,
            'action_payload'  => json_encode($actionPayload),
            'label'           => $label,
        ]);
    }

    public function listConditionalTasks(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM conditional_tasks WHERE user_id=? AND triggered=0 ORDER BY id DESC', [$userId]);
    }

    public function cancelConditionalTask(int $taskId, int $userId): bool
    {
        return $this->db->update('conditional_tasks', ['triggered' => 1], ['id' => $taskId, 'user_id' => $userId]) > 0;
    }

    private function checkConditionalTasks(float $currentPrice): void
    {
        $tasks = $this->db->fetchAll('SELECT * FROM conditional_tasks WHERE triggered=0');

        foreach ($tasks as $task) {
            $condVal  = (float)$task['condition_value'];
            $condType = $task['condition_type'];

            $hit = ($condType === 'price_above' && $currentPrice >= $condVal)
                || ($condType === 'price_below' && $currentPrice <= $condVal);

            if (!$hit) continue;

            // Mark triggered immediately — prevents double execution
            $this->db->update('conditional_tasks', ['triggered' => 1], ['id' => $task['id']]);

            Logger::info("Conditional task #{$task['id']} triggered", [
                'condition' => $condType, 'target' => $condVal, 'price' => $currentPrice,
                'action'    => $task['action_type'],
            ]);

            $payload = json_decode($task['action_payload'], true) ?? [];

            switch ($task['action_type']) {
                case 'send_sol':
                    $this->executeConditionalSend($task, $payload, $currentPrice);
                    break;
                case 'swap_sol_usdc':
                case 'swap_usdc_sol':
                    $this->executeConditionalSwap($task, $payload, $currentPrice);
                    break;
                default:
                    Logger::warn("Unknown conditional action: {$task['action_type']}");
            }
        }
    }

    private function executeConditionalSend(array $task, array $payload, float $triggeredAt): void
    {
        $userId     = (int)$task['user_id'];
        $telegramId = (int)$task['telegram_id'];
        $to         = $payload['to']     ?? '';
        $amount     = (float)($payload['amount'] ?? 0);
        $condDir    = $task['condition_type'] === 'price_above' ? 'above' : 'below';
        $condVal    = '$' . number_format((float)$task['condition_value'], 2);
        $curPrice   = '$' . number_format($triggeredAt, 2);

        $this->telegram->sendMessage($telegramId,
            "🤖 <b>Goal Triggered!</b>\n\n"
            . "📊 SOL reached {$condVal} ({$condDir})\n"
            . "💰 Current price: <b>{$curPrice}</b>\n\n"
            . "⚡ Executing: Send <b>{$amount} SOL</b>\n"
            . "📤 To: <code>{$to}</code>\n\n<i>Sending now…</i>");

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($telegramId,
                "❌ <b>Action Failed</b>\n\nNo active wallet. Create one with /wallet create.");
            return;
        }

        // ── Empty balance check before executing ─────────────────────────────────
        try {
            $wallet = $this->db->getActiveWallet($userId);
            $bal    = $wallet ? $this->walletManager->getBalance($wallet['public_key']) : null;
            if (!$bal || (float)$bal['sol'] < $amount + 0.001) {
                $short      = strlen($to) > 16 ? substr($to,0,8).'…'.substr($to,-6) : $to;
                $actionDesc = "Send {$amount} SOL → {$short}";
                $guard = new BalanceGuard($this->db, $this->walletManager, $this->telegram, $this->config);
                $guard->cancelConditionalTaskAndNotify($task, $payload, $actionDesc);
                return;
            }
        } catch (\Throwable $ignored) {}

        try {
            $result = $this->walletManager->sendSol($userId, $to, $amount);
            $this->telegram->sendMessage($telegramId,
                "✅ <b>Goal Executed!</b>\n\n"
                . "📊 Trigger: SOL {$condDir} {$condVal}\n"
                . "💰 Price at trigger: <b>{$curPrice}</b>\n\n"
                . "💸 Sent: <b>{$amount} SOL</b>\n"
                . "📤 To: <code>{$to}</code>\n"
                . "🔗 <a href=\"{$result['explorer']}\">View Transaction</a>");
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            if (stripos($errMsg, 'Insufficient') !== false) {
                $short      = strlen($to) > 16 ? substr($to,0,8).'…'.substr($to,-6) : $to;
                $actionDesc = "Send {$amount} SOL → {$short}";
                $guard = new BalanceGuard($this->db, $this->walletManager, $this->telegram, $this->config);
                $guard->cancelConditionalTaskAndNotify($task, $payload, $actionDesc);
            } else {
                $this->telegram->sendMessage($telegramId,
                    "❌ <b>Goal send failed</b>\n\n" . htmlspecialchars($errMsg));
            }
            Logger::error("Conditional send #{$task['id']} failed: " . $errMsg);
        }
    }

    private function executeConditionalSwap(array $task, array $payload, float $triggeredAt): void
    {
        $userId     = (int)$task['user_id'];
        $telegramId = (int)$task['telegram_id'];
        $fromSym    = strtoupper($payload['from']   ?? 'SOL');
        $toSym      = strtoupper($payload['to']     ?? 'USDC');
        $amount     = (float)($payload['amount']    ?? 0);
        $amountType = $payload['amount_type']       ?? 'token';   // 'token' or 'usd'
        $condDir    = $task['condition_type'] === 'price_above' ? 'above' : 'below';
        $condVal    = '$' . number_format((float)$task['condition_value'], 2);
        $curPrice   = '$' . number_format($triggeredAt, 2);

        // If amount_type = 'usd', convert to token amount
        $tokenAmount = $amount;
        if ($amountType === 'usd' && $fromSym === 'SOL' && $triggeredAt > 0) {
            $tokenAmount = round($amount / $triggeredAt, 6); // USD → SOL
        } elseif ($amountType === 'usd' && $fromSym === 'USDC') {
            $tokenAmount = $amount; // USDC is already USD-pegged
        }

        $this->telegram->sendMessage($telegramId,
            "🤖 <b>DeFi Goal Triggered!</b>\n\n"
            . "📊 SOL reached {$condVal} ({$condDir})\n"
            . "💰 Current price: <b>{$curPrice}</b>\n\n"
            . "🔄 Executing: Swap <b>{$tokenAmount} {$fromSym}</b> → <b>{$toSym}</b>\n\n"
            . "<i>Executing swap now…</i>");

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($telegramId,
                "❌ <b>Swap Failed</b>\n\nNo active wallet. Create one with /wallet create.");
            return;
        }

        try {
            $keypair = $this->walletManager->getKeypair($wallet);
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($telegramId,
                "❌ <b>Swap Failed</b>\n\nCould not decrypt wallet: " . htmlspecialchars($e->getMessage()));
            return;
        }

        try {
            $crypto  = new Crypto($this->config['security']['encryption_key']);
            $network = $this->config['solana']['network'];
            $swap    = new Swap($this->walletManager->getRpc(), $this->db, $crypto, $network);

            $quoteResult = $swap->getQuote($fromSym, $toSym, $tokenAmount);
            if (!$quoteResult['ok']) {
                $this->telegram->sendMessage($telegramId,
                    "❌ <b>Swap Failed</b>\n\nCould not get quote: " . ($quoteResult['error'] ?? 'Unknown'));
                return;
            }

            $result = $swap->executeSwap($quoteResult['data'], $keypair, $wallet['public_key']);
            if (!$result['success']) {
                $this->telegram->sendMessage($telegramId,
                    "❌ <b>Swap Failed</b>\n\n" . htmlspecialchars($result['error'] ?? 'Unknown error'));
                return;
            }

            $cluster = $network === 'mainnet' ? '' : '?cluster=devnet';
            $sig     = $result['signature'];
            $toAmt   = $quoteResult['data']['toAmount'] ?? '?';

            $this->telegram->sendMessage($telegramId,
                "✅ <b>DeFi Goal Executed!</b>\n\n"
                . "📊 Trigger: SOL {$condDir} {$condVal}\n"
                . "💰 Price at execution: <b>{$curPrice}</b>\n\n"
                . "🔄 Swapped: <b>{$tokenAmount} {$fromSym}</b> → <b>{$toAmt} {$toSym}</b>\n"
                . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View Transaction</a>");

            Logger::info("Conditional swap #{$task['id']} succeeded", ['sig' => $sig]);

        } catch (\Throwable $e) {
            $this->telegram->sendMessage($telegramId,
                "❌ <b>Swap Failed</b>\n\nCondition met but swap failed:\n" . htmlspecialchars($e->getMessage()));
            Logger::error("Conditional swap #{$task['id']} failed: " . $e->getMessage());
        }
    }

    // ─── Scheduled Tasks ──────────────────────────────────────────────────────

    public function scheduleTask(int $userId, string $telegramId, string $type, array $payload, string $executeAt): int
    {
        return $this->db->insert('scheduled_tasks', [
            'user_id'     => $userId,
            'telegram_id' => $telegramId,
            'type'        => $type,
            'payload'     => json_encode($payload),
            'execute_at'  => $executeAt,
        ]);
    }

    public function listTasks(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM scheduled_tasks WHERE user_id=? AND executed=0 ORDER BY execute_at ASC', [$userId]);
    }

    public function cancelTask(int $taskId, int $userId): bool
    {
        return $this->db->update('scheduled_tasks', ['executed' => 1], ['id' => $taskId, 'user_id' => $userId]) > 0;
    }

    private function executeScheduledTasks(): void
    {
        $tasks = $this->db->fetchAll(
            "SELECT * FROM scheduled_tasks WHERE executed=0 AND execute_at <= ?", [date('Y-m-d H:i:s')]);

        foreach ($tasks as $task) {
            try {
                $this->db->update('scheduled_tasks', ['executed' => 1], ['id' => $task['id']]);
                $payload = json_decode($task['payload'], true);
                switch ($task['type']) {
                    case 'send_sol': $this->executeScheduledSend($task, $payload); break;
                    default: Logger::warn('Unknown task type: ' . $task['type']);
                }
            } catch (\Throwable $e) {
                Logger::error("Scheduled task #{$task['id']} failed: " . $e->getMessage());
            }
        }
    }

    private function executeScheduledSend(array $task, array $payload): void
    {
        $userId     = (int)$task['user_id'];
        $telegramId = (int)$task['telegram_id'];
        $to         = $payload['to']     ?? '';
        $amount     = (float)($payload['amount'] ?? 0);

        if (!$to || $amount <= 0) {
            $this->telegram->sendMessage($telegramId, "❌ Scheduled send had invalid data — task removed.");
            return;
        }

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($telegramId,
                "❌ No active wallet found. Create one and set up the send again.");
            return;
        }

        // ── Empty balance check: cancel gracefully instead of erroring ─────────
        try {
            $bal = $this->walletManager->getBalance($wallet['public_key']);
            if ((float)$bal['sol'] < $amount + 0.001) {
                $guard = new BalanceGuard($this->db, $this->walletManager, $this->telegram, $this->config);
                $guard->cancelScheduledTaskAndNotify($task, $payload);
                return;
            }
        } catch (\Throwable $ignored) {}

        try {
            $result = $this->walletManager->sendSol($userId, $to, $amount);
            $short  = substr($to,0,8).'…'.substr($to,-6);
            $this->telegram->sendMessage($telegramId,
                "⏰ <b>Scheduled Send Executed!</b>\n\n"
                . "✅ Sent <b>{$amount} SOL</b>\n"
                . "📤 To: <code>{$to}</code>\n"
                . "🔗 <a href=\"{$result['explorer']}\">View Transaction</a>");
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            if (stripos($errMsg, 'Insufficient') !== false) {
                // Balance just ran out between check and send (race) — cancel gracefully
                $guard = new BalanceGuard($this->db, $this->walletManager, $this->telegram, $this->config);
                $guard->cancelScheduledTaskAndNotify($task, $payload);
            } else {
                $this->telegram->sendMessage($telegramId,
                    "❌ Scheduled send to <code>{$to}</code> failed: " . htmlspecialchars($errMsg));
            }
            Logger::error("Scheduled send #{$task['id']} failed: " . $errMsg);
        }
    }

    // ─── Time parser ──────────────────────────────────────────────────────────

    public static function parseTime(string $timeStr): string
    {
        $timeStr = trim($timeStr);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $timeStr)) {
            $ts = strtotime($timeStr);
            if ($ts) return date('Y-m-d H:i:s', $ts);
            throw new \InvalidArgumentException("Invalid date: {$timeStr}");
        }
        $normalized = preg_replace('/^in\s+/i', '+', $timeStr);
        if (preg_match('/^(\d+)\s+(second|minute|hour|day|week|month)s?$/i', $normalized, $m))
            $normalized = "+{$m[1]} {$m[2]}s";
        $candidates = array_unique([$normalized, $timeStr, '+' . $timeStr]);
        foreach ($candidates as $candidate) {
            $ts = strtotime($candidate);
            if ($ts && $ts > time()) return date('Y-m-d H:i:s', $ts);
        }
        throw new \InvalidArgumentException(
            "Could not parse time: \"{$timeStr}\".\nTry: \"in 5 minutes\", \"in 2 hours\", \"in 3 days\"");
    }

    // ─── Trading Strategies ───────────────────────────────────────────────────

    private function checkTradingStrategies(float $currentPrice): void
    {
        $strategy = new Strategy(
            $this->db,
            $this->walletManager,
            $this->telegram,
            $this->config
        );
        $strategy->checkAll($currentPrice);
    }
}