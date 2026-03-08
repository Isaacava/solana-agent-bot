<?php
/**
 * Cron Job Handler
 *
 * Run every minute via system cron:
 *   * * * * * php /path/to/solana-agent/cron.php >> /path/to/logs/cron.log 2>&1
 *
 * Or for hosting without shell cron, call this endpoint from an external cron service
 * like cron-job.org every minute.
 *
 * Web endpoint: GET /cron.php?secret=YOUR_CRON_SECRET
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

// ─── Auth (web mode only) ─────────────────────────────────────────────────────
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $cronSecret = $config['security']['cron_secret'] ?? '';
    $provided   = $_GET['secret'] ?? '';
    if ($cronSecret && !hash_equals($cronSecret, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}

// ─── Prevent parallel runs (file lock) ───────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/solana_agent_cron.lock';
$lock     = fopen($lockFile, 'w');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    Logger::warn('Cron: another instance is running, skipping.');
    exit;
}

try {
    Logger::info('Cron: starting run at ' . date('Y-m-d H:i:s'));

    $db            = Database::getInstance($config['database']['file']);
    $crypto        = new Crypto($config['security']['encryption_key']);
    $telegram      = new Telegram($config['telegram']);
    $walletManager = new WalletManager($db, $crypto, $config['solana'] + ['features' => $config['features']]);
    $scheduler     = new Scheduler($db, $walletManager, $telegram, $config);

    $scheduler->run();

    Logger::info('Cron: completed successfully');
    echo "OK\n";

} catch (\Throwable $e) {
    Logger::error('Cron error: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
