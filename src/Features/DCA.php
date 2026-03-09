<?php
namespace SolanaAgent\Features;

use SolanaAgent\Storage\Database;
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Utils\{Logger, Crypto};

/**
 * DCA — Dollar Cost Averaging
 * Recurring buys of SOL at fixed intervals using USDC.
 *
 * Examples:
 *   "Buy $10 worth of SOL every day at 9am"
 *   "Buy 0.5 SOL every week on Monday"
 *   "DCA $50 into SOL every 3 days"
 */
class DCA
{
    public function __construct(
        private Database      $db,
        private WalletManager $walletManager,
        private Telegram      $telegram,
        private array         $config
    ) {}

    // ─── Create ───────────────────────────────────────────────────────────────

    public function create(
        int    $userId,
        string $telegramId,
        float  $amountUsd,      // USDC to spend per cycle
        string $interval,       // 'daily' | 'weekly' | 'custom'
        int    $intervalHours,  // hours between each buy
        string $label = ''
    ): int {
        $nextRun = date('Y-m-d H:i:s', time() + ($intervalHours * 3600));
        return $this->db->query(
            "INSERT INTO dca_tasks (user_id, telegram_id, amount_usd, interval_hours, next_run, status, label, created_at)
             VALUES (?, ?, ?, ?, ?, 'active', ?, CURRENT_TIMESTAMP)",
            [$userId, $telegramId, $amountUsd, $intervalHours, $nextRun, $label ?: "DCA \${$amountUsd} every {$intervalHours}h"]
        );
    }

    public function list(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM dca_tasks WHERE user_id=? AND status='active' ORDER BY id DESC", [$userId]
        );
    }

    public function cancel(int $id, int $userId): bool
    {
        return $this->db->update('dca_tasks', ['status' => 'cancelled'], ['id' => $id, 'user_id' => $userId]) > 0;
    }

    // ─── Cron execution ───────────────────────────────────────────────────────

    public function runDueTasks(): void
    {
        $tasks = $this->db->fetchAll(
            "SELECT * FROM dca_tasks WHERE status='active' AND next_run <= ?", [date('Y-m-d H:i:s')]
        );
        foreach ($tasks as $task) {
            try {
                $this->executeDCA($task);
            } catch (\Throwable $e) {
                Logger::error("DCA #{$task['id']} failed: " . $e->getMessage());
            }
        }
    }

    private function executeDCA(array $task): void
    {
        $userId     = (int)$task['user_id'];
        $telegramId = (int)$task['telegram_id'];
        $amountUsd  = (float)$task['amount_usd'];

        // Schedule next run
        $nextRun = date('Y-m-d H:i:s', time() + ((int)$task['interval_hours'] * 3600));
        $this->db->update('dca_tasks', ['next_run' => $nextRun, 'runs_count' => (int)$task['runs_count'] + 1], ['id' => $task['id']]);

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($telegramId,
                "❌ <b>DCA #{$task['id']} skipped</b> — no active wallet found.");
            return;
        }

        // Check USDC balance
        $network = $this->config['solana']['network'];
        $spl     = new SPLToken($this->walletManager->getRpc(), $network, $this->config);
        $usdcBal = $spl->getUsdcBalance($wallet['public_key']);

        if ($usdcBal < $amountUsd) {
            $this->telegram->sendMessage($telegramId,
                "⚠️ <b>DCA #{$task['id']} skipped</b> — not enough USDC\n\n"
                . "Need: <b>\${$amountUsd}</b> · Have: <b>" . number_format($usdcBal, 2) . " USDC</b>\n"
                . "Top up your USDC and the next DCA will run in {$task['interval_hours']} hours.");
            return;
        }

        // Notify start
        $this->telegram->sendMessage($telegramId,
            "🔄 <b>DCA #{$task['id']} running</b>\n\nBuying <b>\${$amountUsd}</b> worth of SOL now… ⏳");

        try {
            $crypto      = new Crypto($this->config['security']['encryption_key']);
            $swap        = new Swap($this->walletManager->getRpc(), $this->db, $crypto, $network);
            $keypair     = $this->walletManager->getKeypair($wallet);
            $quoteResult = $swap->getQuote('USDC', 'SOL', $amountUsd);

            if (!$quoteResult['ok']) {
                $this->telegram->sendMessage($telegramId,
                    "❌ <b>DCA #{$task['id']} failed</b> — could not get quote: " . ($quoteResult['error'] ?? 'unknown'));
                return;
            }

            $result  = $swap->executeSwap($quoteResult['data'], $keypair, $wallet['public_key']);
            if (!$result['success']) {
                $this->telegram->sendMessage($telegramId,
                    "❌ <b>DCA #{$task['id']} failed</b> — " . ($result['error'] ?? 'swap failed'));
                return;
            }

            $solBought = round((float)($quoteResult['data']['toAmount'] ?? 0), 6);
            $cluster   = $network === 'mainnet' ? '' : '?cluster=devnet';
            $sig       = $result['signature'];
            $totalRuns = (int)$task['runs_count'] + 1;

            $this->telegram->sendMessage($telegramId,
                "✅ <b>DCA #{$task['id']} executed!</b>\n\n"
                . "💸 Spent: <b>\${$amountUsd} USDC</b>\n"
                . "🪙 Bought: <b>{$solBought} SOL</b>\n"
                . "🔁 Total runs: #{$totalRuns}\n"
                . "⏭️ Next run: <b>" . date('M d, H:i', strtotime($nextRun)) . "</b>\n\n"
                . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View TX</a>");

            Logger::info("DCA #{$task['id']} success", ['sol' => $solBought, 'sig' => $sig]);

        } catch (\Throwable $e) {
            $this->telegram->sendMessage($telegramId,
                "❌ <b>DCA #{$task['id']} failed</b> — " . htmlspecialchars($e->getMessage()));
            Logger::error("DCA execute error: " . $e->getMessage());
        }
    }

    // ─── Formatting ───────────────────────────────────────────────────────────

    public static function formatList(array $tasks): string
    {
        if (empty($tasks)) return "No active DCA tasks. Say <b>\"DCA \$10 into SOL daily\"</b> to start one.";
        $msg = "🔁 <b>Active DCA Tasks</b>\n\n";
        foreach ($tasks as $t) {
            $next = date('M d, H:i', strtotime($t['next_run']));
            $msg .= "#{$t['id']} — <b>\${$t['amount_usd']}</b> every <b>{$t['interval_hours']}h</b>\n";
            $msg .= "  ⏭️ Next: {$next} · Runs: {$t['runs_count']}\n\n";
        }
        $msg .= "Say <b>\"cancel DCA #ID\"</b> to stop one.";
        return $msg;
    }

    /**
     * Parse interval phrase to hours.
     * "daily" → 24, "weekly" → 168, "every 3 days" → 72, "every 6 hours" → 6
     */
    public static function parseInterval(string $text): int
    {
        $text = strtolower(trim($text));
        if (str_contains($text, 'daily') || str_contains($text, 'every day') || str_contains($text, 'per day')) return 24;
        if (str_contains($text, 'weekly') || str_contains($text, 'every week')) return 168;
        if (preg_match('/every\s+(\d+)\s+day/', $text, $m)) return (int)$m[1] * 24;
        if (preg_match('/every\s+(\d+)\s+hour/', $text, $m)) return (int)$m[1];
        if (preg_match('/every\s+(\d+)\s+week/', $text, $m)) return (int)$m[1] * 168;
        return 24; // default daily
    }
}
