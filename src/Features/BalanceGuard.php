<?php
namespace SolanaAgent\Features;

use SolanaAgent\Storage\Database;
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Utils\Logger;

/**
 * BalanceGuard — tracks committed funds and warns users proactively.
 *
 * "Committed" = SOL/USDC already earmarked for active tasks:
 *   - Pending scheduled sends
 *   - Conditional send_sol goals (not yet triggered)
 *   - Active trading strategies in waiting_buy phase (USDC needed)
 *   - Active trading strategies in holding phase (SOL held)
 */
class BalanceGuard
{
    public function __construct(
        private Database      $db,
        private WalletManager $walletManager,
        private Telegram      $telegram,
        private array         $config
    ) {}

    // ─── Committed balance summary ────────────────────────────────────────────

    /**
     * Returns a summary of committed SOL and USDC for a user.
     * [
     *   'sol'   => float,   // total SOL committed to tasks/strategies
     *   'usdc'  => float,   // total USDC committed to strategies
     *   'items' => array,   // human-readable list of what's committed
     * ]
     */
    public function getCommitted(int $userId): array
    {
        $sol   = 0.0;
        $usdc  = 0.0;
        $items = [];

        // ── Pending scheduled sends ───────────────────────────────────────────
        $sends = $this->db->fetchAll(
            "SELECT * FROM scheduled_tasks WHERE user_id=? AND executed=0 ORDER BY execute_at ASC",
            [$userId]
        );
        foreach ($sends as $t) {
            $p   = json_decode($t['payload'], true) ?? [];
            $amt = (float)($p['amount'] ?? 0);
            if ($amt > 0) {
                $sol  += $amt;
                $short = isset($p['to']) ? substr($p['to'],0,8).'…'.substr($p['to'],-6) : '?';
                $time  = date('M d H:i', strtotime($t['execute_at']));
                $items[] = ['type'=>'scheduled_send','sol'=>$amt,'usdc'=>0,
                    'desc' => "Scheduled: {$amt} SOL → {$short} at {$time}"];
            }
        }

        // ── Conditional send_sol goals ────────────────────────────────────────
        $goals = $this->db->fetchAll(
            "SELECT * FROM conditional_tasks WHERE user_id=? AND triggered=0 AND action_type='send_sol'",
            [$userId]
        );
        foreach ($goals as $g) {
            $p   = json_decode($g['action_payload'], true) ?? [];
            $amt = (float)($p['amount'] ?? 0);
            if ($amt > 0) {
                $sol  += $amt;
                $short = isset($p['to']) ? substr($p['to'],0,8).'…'.substr($p['to'],-6) : '?';
                $dir   = $g['condition_type'] === 'price_above' ? 'above' : 'below';
                $price = '$'.number_format((float)$g['condition_value'],2);
                $items[] = ['type'=>'conditional_send','sol'=>$amt,'usdc'=>0,
                    'desc' => "Goal: send {$amt} SOL → {$short} when SOL {$dir} {$price}"];
            }
        }

        // ── Active strategies ─────────────────────────────────────────────────
        $strategies = $this->db->fetchAll(
            "SELECT * FROM trading_strategies WHERE user_id=? AND status='active'",
            [$userId]
        );
        foreach ($strategies as $s) {
            if ($s['phase'] === 'waiting_buy') {
                // Will spend USDC to buy SOL
                $usdcNeeded = (float)$s['amount_sol'] * (float)$s['buy_price'];
                $usdc += $usdcNeeded;
                $items[] = ['type'=>'strategy_buy','sol'=>0,'usdc'=>$usdcNeeded,
                    'desc' => "Strategy #{$s['id']}: buy {$s['amount_sol']} SOL at \${$s['buy_price']} (~".number_format($usdcNeeded,2)." USDC needed)"];
            } elseif ($s['phase'] === 'holding') {
                // Holding SOL, will sell it
                $sol += (float)$s['amount_sol'];
                $items[] = ['type'=>'strategy_hold','sol'=>(float)$s['amount_sol'],'usdc'=>0,
                    'desc' => "Strategy #{$s['id']}: holding {$s['amount_sol']} SOL (selling at \${$s['sell_price']})"];
            }
        }

        // ── Conditional swap goals ────────────────────────────────────────────
        $swapGoals = $this->db->fetchAll(
            "SELECT * FROM conditional_tasks WHERE user_id=? AND triggered=0 AND action_type IN ('swap_sol_usdc','swap_usdc_sol')",
            [$userId]
        );
        foreach ($swapGoals as $g) {
            $p      = json_decode($g['action_payload'], true) ?? [];
            $amt    = (float)($p['amount'] ?? 0);
            $from   = strtoupper($p['from'] ?? 'SOL');
            $dir    = $g['condition_type'] === 'price_above' ? 'above' : 'below';
            $price  = '$'.number_format((float)$g['condition_value'],2);
            if ($amt > 0) {
                if ($from === 'SOL') {
                    $sol += $amt;
                    $items[] = ['type'=>'conditional_swap','sol'=>$amt,'usdc'=>0,
                        'desc' => "Swap goal: sell {$amt} SOL → USDC when SOL {$dir} {$price}"];
                } else {
                    $usdc += $amt;
                    $items[] = ['type'=>'conditional_swap','sol'=>0,'usdc'=>$amt,
                        'desc' => "Swap goal: sell {$amt} USDC → SOL when SOL {$dir} {$price}"];
                }
            }
        }

        return compact('sol','usdc','items');
    }

    /**
     * Check if a user has enough FREE (uncommitted) balance for a new action.
     * Returns ['ok'=>true] or ['ok'=>false, 'message'=>'...']
     */
    public function checkFreeBalance(int $userId, float $solNeeded = 0.0, float $usdcNeeded = 0.0): array
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) return ['ok' => false, 'message' => 'No active wallet found.'];

        $committed = $this->getCommitted($userId);

        if ($solNeeded > 0) {
            try {
                $bal     = $this->walletManager->getBalance($wallet['public_key']);
                $totalSol = (float)$bal['sol'];
                $freeSol  = $totalSol - $committed['sol'] - 0.001; // keep 0.001 for fees
                if ($freeSol < $solNeeded) {
                    return [
                        'ok'        => false,
                        'type'      => 'sol',
                        'total'     => $totalSol,
                        'committed' => $committed['sol'],
                        'free'      => max(0, $freeSol),
                        'needed'    => $solNeeded,
                        'items'     => $committed['items'],
                        'message'   => $this->buildWarning($solNeeded, $totalSol, $committed),
                    ];
                }
            } catch (\Throwable $e) {
                Logger::warn('BalanceGuard SOL check failed: '.$e->getMessage());
            }
        }

        return ['ok' => true];
    }

    private function buildWarning(float $needed, float $total, array $committed): string
    {
        $free = max(0, $total - $committed['sol'] - 0.001);
        $msg  = "⚠️ <b>Not enough free SOL</b>\n\n"
            . "💰 Total balance: <b>" . number_format($total, 4) . " SOL</b>\n"
            . "🔒 Committed: <b>" . number_format($committed['sol'], 4) . " SOL</b>\n"
            . "✅ Free: <b>" . number_format($free, 4) . " SOL</b>\n"
            . "💸 Needed: <b>" . number_format($needed, 4) . " SOL</b>\n\n";

        if (!empty($committed['items'])) {
            $msg .= "🔒 <b>Your SOL is at work:</b>\n";
            foreach ($committed['items'] as $item) {
                if ($item['sol'] > 0) {
                    $msg .= "  • " . $item['desc'] . "\n";
                }
            }
            $msg .= "\nTop up your wallet or cancel an existing task first.\n"
                . "Say <i>show my tasks</i> to see what's running.";
        }

        return $msg;
    }

    // ─── Cron: proactive low-balance warnings ─────────────────────────────────

    /**
     * Called by Scheduler every cron cycle.
     * Warns users about upcoming tasks they may not have funds for.
     */
    public function runProactiveChecks(float $currentPrice): void
    {
        // Check scheduled sends due in next 10 minutes
        $upcoming = $this->db->fetchAll(
            "SELECT t.*, u.telegram_id tid FROM scheduled_tasks t
             JOIN users u ON t.user_id=u.id
             WHERE t.executed=0 AND t.execute_at <= ?",
            [date('Y-m-d H:i:s', time() + 600)]
        );

        foreach ($upcoming as $task) {
            $this->checkScheduledSendBalance($task, $currentPrice);
        }
    }

    private function checkScheduledSendBalance(array $task, float $price): void
    {
        $userId     = (int)$task['user_id'];
        $telegramId = (int)($task['tid'] ?? $task['telegram_id']);
        $payload    = json_decode($task['payload'], true) ?? [];
        $amount     = (float)($payload['amount'] ?? 0);
        $to         = $payload['to'] ?? '';

        if ($amount <= 0) return;

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) return;

        try {
            $bal     = $this->walletManager->getBalance($wallet['public_key']);
            $solBal  = (float)$bal['sol'];
            if ($solBal >= $amount + 0.001) return; // enough, no warning needed

            $short   = $to ? substr($to,0,8).'…'.substr($to,-6) : '?';
            $timeStr = date('M d H:i', strtotime($task['execute_at']));

            // Check if we already warned recently (avoid spam — use a settings key)
            $warnKey  = "warned_task_{$task['id']}";
            $lastWarn = $this->db->fetch("SELECT value FROM settings WHERE key_name=?", [$warnKey]);
            if ($lastWarn && (time() - strtotime($lastWarn['value'])) < 3600) return; // warn once per hour

            $this->db->query(
                "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?,?,CURRENT_TIMESTAMP)",
                [$warnKey, date('Y-m-d H:i:s')]
            );

            $this->telegram->sendMessage($telegramId,
                "⚠️ <b>Heads up!</b>\n\n"
                . "I have a scheduled send coming up:\n"
                . "💸 <b>{$amount} SOL</b> → <code>{$to}</code>\n"
                . "🕐 Due at: <b>{$timeStr}</b>\n\n"
                . "But your current balance is only <b>" . number_format($solBal,4) . " SOL</b>.\n\n"
                . "Fund your wallet before {$timeStr} so I can send it on time. "
                . "If you don't top up, I'll cancel the task and let you know. 🤖");

        } catch (\Throwable $e) {
            Logger::warn("BalanceGuard proactive check failed for task #{$task['id']}: ".$e->getMessage());
        }
    }

    // ─── Cancel task + notify (called when execution fails due to empty balance) ─

    public function cancelScheduledTaskAndNotify(array $task, array $payload, string $reason = 'empty_balance'): void
    {
        $telegramId = (int)$task['telegram_id'];
        $to         = $payload['to'] ?? '?';
        $amount     = (float)($payload['amount'] ?? 0);
        $short      = strlen($to) > 16 ? substr($to,0,8).'…'.substr($to,-6) : $to;

        $this->db->update('scheduled_tasks', ['executed' => 1], ['id' => $task['id']]);

        $this->telegram->sendMessage($telegramId,
            "🤖 <b>Task cancelled — wallet empty</b>\n\n"
            . "I was about to send <b>{$amount} SOL</b> → <code>{$to}</code> "
            . "but I checked your wallet and your balance is empty.\n\n"
            . "I've cancelled this task for now.\n\n"
            . "Once you refill your wallet, just tell me what to do and I'll set it up again. 💪");
    }

    public function cancelConditionalTaskAndNotify(array $task, array $payload, string $actionDesc): void
    {
        $telegramId = (int)$task['telegram_id'];

        $this->db->update('conditional_tasks', ['triggered' => 1], ['id' => $task['id']]);

        $this->telegram->sendMessage($telegramId,
            "🤖 <b>Goal cancelled — wallet empty</b>\n\n"
            . "Your condition was met and I tried to execute:\n"
            . "<b>{$actionDesc}</b>\n\n"
            . "But when I checked your wallet, your balance is empty.\n\n"
            . "I've cancelled this goal. Once you top up your wallet, tell me again and I'll set it up fresh. 💪");
    }
}
