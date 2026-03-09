<?php
namespace SolanaAgent\Features;

use SolanaAgent\Storage\Database;
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Utils\Logger;

/**
 * WalletMonitor — Wallet alerts, recurring price reports, P&L digest
 *
 * Features:
 *  1. Wallet watcher   — alert if unexpected SOL leaves wallet
 *  2. Recurring report — "Send me SOL price every morning at 8am"
 *  3. P&L daily digest — end-of-day strategy performance summary
 */
class WalletMonitor
{
    public function __construct(
        private Database      $db,
        private WalletManager $walletManager,
        private Telegram      $telegram,
        private array         $config
    ) {}

    // ─── Wallet Watcher ───────────────────────────────────────────────────────

    public function enableWatcher(int $userId, string $telegramId): bool
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) return false;

        // Store current balance as baseline
        $bal = $this->walletManager->getBalance($wallet['public_key']);
        $this->db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
            [
                "wallet_watcher_{$userId}",
                json_encode([
                    'enabled'     => true,
                    'telegram_id' => $telegramId,
                    'pubkey'      => $wallet['public_key'],
                    'baseline_sol'=> (float)($bal['sol'] ?? 0),
                    'last_sig'    => null,
                ])
            ]
        );
        return true;
    }

    public function disableWatcher(int $userId): void
    {
        $this->db->query("DELETE FROM settings WHERE key_name=?", ["wallet_watcher_{$userId}"]);
    }

    public function isWatcherEnabled(int $userId): bool
    {
        $row = $this->db->fetch("SELECT value FROM settings WHERE key_name=?", ["wallet_watcher_{$userId}"]);
        if (!$row) return false;
        $data = json_decode($row['value'], true) ?? [];
        return (bool)($data['enabled'] ?? false);
    }

    public function checkWallets(float $solPrice): void
    {
        // Find all active watchers
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM settings WHERE key_name LIKE 'wallet_watcher_%'"
        );

        foreach ($rows as $row) {
            $data = json_decode($row['value'], true) ?? [];
            if (empty($data['enabled'])) continue;

            $userId     = (int)str_replace('wallet_watcher_', '', $row['key_name']);
            $telegramId = (int)($data['telegram_id'] ?? 0);
            $pubkey     = $data['pubkey'] ?? '';

            if (!$pubkey || !$telegramId) continue;

            try {
                $bal     = $this->walletManager->getBalance($pubkey);
                $current = (float)($bal['sol'] ?? 0);
                $base    = (float)($data['baseline_sol'] ?? 0);
                $diff    = $current - $base;

                // Alert only on significant decrease (> 0.001 SOL)
                if ($diff < -0.001) {
                    $lostSol = abs($diff);
                    $lostUsd = round($lostSol * $solPrice, 2);

                    $this->telegram->sendMessage($telegramId,
                        "⚠️ <b>Wallet Alert!</b>\n\n"
                        . "📉 <b>" . number_format($lostSol, 6) . " SOL</b> left your wallet\n"
                        . "💵 Value: ~\${$lostUsd}\n"
                        . "💰 Balance: <b>" . number_format($current, 4) . " SOL</b>\n\n"
                        . "If you didn't authorise this, check your recent transactions with /history\n"
                        . "<code>" . substr($pubkey, 0, 8) . '…' . substr($pubkey, -6) . "</code>");

                    // Update baseline to current
                    $data['baseline_sol'] = $current;
                    $this->db->query(
                        "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                        [$row['key_name'], json_encode($data)]
                    );
                } elseif ($diff > 0.001) {
                    // SOL received — update baseline silently
                    $data['baseline_sol'] = $current;
                    $this->db->query(
                        "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                        [$row['key_name'], json_encode($data)]
                    );
                }
            } catch (\Throwable $e) {
                Logger::warn("Wallet watcher {$pubkey} check failed: " . $e->getMessage());
            }
        }
    }

    // ─── Recurring Price Reports ──────────────────────────────────────────────

    public function setRecurringReport(int $userId, string $telegramId, string $intervalHours, string $label = ''): void
    {
        $hours   = (int)$intervalHours;
        $nextRun = date('Y-m-d H:i:s', time() + ($hours * 3600));
        $this->db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
            [
                "price_report_{$userId}",
                json_encode([
                    'telegram_id'    => $telegramId,
                    'interval_hours' => $hours,
                    'next_run'       => $nextRun,
                    'enabled'        => true,
                    'label'          => $label ?: "Every {$hours}h price report",
                ])
            ]
        );
    }

    public function cancelRecurringReport(int $userId): void
    {
        $this->db->query("DELETE FROM settings WHERE key_name=?", ["price_report_{$userId}"]);
    }

    public function runRecurringReports(float $currentPrice, float $ngn, float $change24h): void
    {
        $rows = $this->db->fetchAll(
            "SELECT key_name, value FROM settings WHERE key_name LIKE 'price_report_%'"
        );
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            $data = json_decode($row['value'], true) ?? [];
            if (empty($data['enabled'])) continue;
            if (($data['next_run'] ?? '9999') > $now) continue;

            $telegramId = (int)($data['telegram_id'] ?? 0);
            if (!$telegramId) continue;

            $chgEmoji = $change24h >= 0 ? '📈' : '📉';
            $chgStr   = ($change24h >= 0 ? '+' : '') . $change24h . '%';

            $this->telegram->sendMessage($telegramId,
                "⏰ <b>Scheduled Price Report</b>\n\n"
                . "💰 SOL/USD: <b>\$" . number_format($currentPrice, 2) . "</b>\n"
                . "🇳🇬 SOL/NGN: <b>₦" . number_format($ngn, 0) . "</b>\n"
                . "{$chgEmoji} 24h Change: <b>{$chgStr}</b>\n\n"
                . "Say <b>\"cancel price report\"</b> to stop these.");

            // Schedule next
            $hours   = (int)($data['interval_hours'] ?? 24);
            $nextRun = date('Y-m-d H:i:s', time() + ($hours * 3600));
            $data['next_run'] = $nextRun;
            $this->db->query(
                "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                [$row['key_name'], json_encode($data)]
            );
        }
    }

    // ─── P&L Daily Digest ────────────────────────────────────────────────────

    public function runDailyDigest(float $currentPrice): void
    {
        // Only run around midnight (23:00–00:59) once per day
        $hour = (int)date('H');
        if ($hour !== 23 && $hour !== 0) return;

        // Find users who have completed or stopped strategies in the last 24h
        $since = date('Y-m-d H:i:s', time() - 86400);
        $users = $this->db->fetchAll(
            "SELECT DISTINCT u.id, u.telegram_id
             FROM trading_strategies s JOIN users u ON s.user_id=u.id
             WHERE s.status IN ('completed','stopped')
               AND s.updated_at >= ?", [$since]
        );

        foreach ($users as $user) {
            // Check if we already sent digest today
            $key     = "digest_sent_{$user['id']}_" . date('Y-m-d');
            $already = $this->db->fetch("SELECT 1 FROM settings WHERE key_name=?", [$key]);
            if ($already) continue;

            $strategies = $this->db->fetchAll(
                "SELECT * FROM trading_strategies
                 WHERE user_id=? AND status IN ('completed','stopped')
                   AND updated_at >= ?",
                [$user['id'], $since]
            );

            if (empty($strategies)) continue;

            $totalPnl   = 0.0;
            $wins       = 0;
            $losses     = 0;
            $lines      = '';

            foreach ($strategies as $s) {
                $buyP  = (float)($s['buy_price']  ?? 0);
                $pct   = (float)($s['est_profit_pct'] ?? 0);

                if ($s['status'] === 'completed') {
                    $wins++;
                    $totalPnl += $pct;
                    $lines .= "✅ {$s['label']} +{$pct}%\n";
                } else {
                    $losses++;
                    $estLoss = -round((float)(Strategy::TYPES[$s['strategy_type'] ?? 'CONSERVATIVE']['stop_pct'] ?? 0.02) * 100, 1);
                    $totalPnl += $estLoss;
                    $lines .= "🛑 {$s['label']} {$estLoss}%\n";
                }
            }

            $pnlEmoji = $totalPnl >= 0 ? '🟢' : '🔴';
            $pnlStr   = ($totalPnl >= 0 ? '+' : '') . number_format($totalPnl, 1) . '%';

            $this->telegram->sendMessage((int)$user['telegram_id'],
                "📊 <b>Daily P&L Digest</b>\n\n"
                . $lines . "\n"
                . "{$pnlEmoji} Net: <b>{$pnlStr}</b>\n"
                . "🏆 Wins: {$wins} · 📉 Stops: {$losses}\n"
                . "💰 SOL now: \$" . number_format($currentPrice, 2) . "\n\n"
                . "Say <b>\"grow my SOL\"</b> to start a new strategy.");

            // Mark sent
            $this->db->query(
                "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
                [$key, '1']
            );
        }
    }
}
