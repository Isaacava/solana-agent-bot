<?php
/**
 * ============================================================
 * CRON.PHP — Agent Task Runner
 * ============================================================
 *
 * Add to crontab (runs every minute):
 *
 *   * * * * * php /path/to/cron.php >> /path/to/logs/cron.log 2>&1
 *
 * Or via HTTP with a secret (useful for shared hosting):
 *
 *   https://yourdomain.com/cron.php?secret=YOUR_CRON_SECRET
 *
 * What it runs each tick (once per minute):
 *   ✓ Price alerts        — notify users when SOL hits their target
 *   ✓ Conditional tasks   — execute pending send/swap goals
 *   ✓ Trading strategies  — buy/sell at targets across all 5 strategy types
 *   ✓ Trailing stops      — move stop loss up as price rises
 *   ✓ Price cascades      — multi-target partial sell execution
 *   ✓ Scheduled sends     — time-based SOL/USDC sends
 *   ✓ DCA tasks           — recurring dollar-cost-average buys
 *   ✓ Wallet monitor      — alert on unexpected SOL outflows
 *   ✓ Balance guard       — warn on underfunded upcoming tasks
 *   ✓ Recurring reports   — scheduled SOL price updates to users
 *   ✓ Daily P&L digest    — end-of-day strategy performance summary
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\{Crypto, Logger};
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Features\Scheduler;

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Africa/Lagos');

// ─── Auth (HTTP mode only) ────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $cronSecret = $config['security']['cron_secret'] ?? '';
    $provided   = $_GET['secret'] ?? '';
    if ($cronSecret && !hash_equals($cronSecret, $provided)) {
        http_response_code(403); exit('Forbidden');
    }
    header('Content-Type: text/plain');
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────
try {
    $db            = Database::getInstance($config['database']['file']);
    $crypto        = new Crypto($config['security']['encryption_key']);
    $telegram      = new Telegram($config['telegram']);
    $walletManager = new WalletManager($db, $crypto, $config['solana'] + ['features' => $config['features']]);
    $scheduler     = new Scheduler($db, $walletManager, $telegram, $config);
} catch (\Throwable $e) {
    Logger::error('Cron bootstrap failed: ' . $e->getMessage());
    exit(1);
}

// ─── Run all tasks ────────────────────────────────────────────────────────────
Logger::info('Cron started at ' . date('Y-m-d H:i:s'));

try {
    $scheduler->run();
    Logger::info('Cron finished OK at ' . date('Y-m-d H:i:s'));
    echo "OK\n";
} catch (\Throwable $e) {
    Logger::error('Cron run() failed: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
