<?php
/**
 * Telegram Webhook Handler
 * Telegram POSTs updates here automatically once webhook is registered.
 * Visiting this URL in a browser shows a status page (GET).
 */

declare(strict_types=1);

require_once __DIR__ . '/src/autoload.php';

use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\{Crypto, Logger};
use SolanaAgent\Solana\WalletManager;
use SolanaAgent\AI\AIManager;
use SolanaAgent\Bot\{Telegram, Handler};
use SolanaAgent\Features\Scheduler;

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Africa/Lagos');

// ─── Browser GET → show status page ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token   = $config['telegram']['bot_token'] ?? '';
    $baseUrl = $config['app']['base_url'] ?? '';
    $webhookUrl = $baseUrl . '/webhook.php';
    $registered = false;
    $botName    = '';
    $webhookSet = '';

    if ($token) {
        // Check current webhook status
        $ch = curl_init("https://api.telegram.org/bot{$token}/getWebhookInfo");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $res = curl_exec($ch); curl_close($ch);
        $info = $res ? json_decode($res, true) : null;
        $webhookSet = $info['result']['url'] ?? '';
        $registered = !empty($webhookSet);

        // Get bot info
        $ch2 = curl_init("https://api.telegram.org/bot{$token}/getMe");
        curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $res2 = curl_exec($ch2); curl_close($ch2);
        $me = $res2 ? json_decode($res2, true) : null;
        $botName = $me['result']['username'] ?? '';
    }

    $statusColor  = $registered ? '#00FFA3' : '#FF4757';
    $statusText   = $registered ? '✅ Webhook Active' : '❌ Webhook NOT Set';
    $tokenStatus  = $token ? '✅ Configured' : '❌ Missing';
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Solana Agent Bot — Webhook Status</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#050A14;color:#E8F4F8;font-family:'Courier New',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,163,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,163,.03) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
  .card{background:#0D1B2E;border:1px solid rgba(0,255,163,.2);border-radius:12px;padding:40px;max-width:560px;width:100%;position:relative;overflow:hidden}
  .card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#00FFA3,transparent)}
  h1{font-size:22px;color:#00FFA3;margin-bottom:6px;letter-spacing:1px}
  .sub{color:#4A6480;font-size:13px;margin-bottom:30px}
  .status-badge{display:inline-block;padding:8px 18px;border-radius:6px;font-size:15px;font-weight:bold;margin-bottom:28px;background:rgba(0,255,163,.1);color:<?= $statusColor ?>}
  .row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
  .row:last-child{border-bottom:none}
  .label{color:#8CA5BE}
  .val{color:#E8F4F8;font-size:12px;max-width:280px;text-align:right;word-break:break-all}
  .val.ok{color:#00FFA3} .val.bad{color:#FF4757}
  .btn{display:inline-block;margin-top:24px;padding:12px 24px;background:#00FFA3;color:#050A14;border:none;border-radius:8px;font-family:monospace;font-size:14px;font-weight:bold;cursor:pointer;text-decoration:none;transition:all .2s}
  .btn:hover{background:#14F195;transform:translateY(-1px)}
  .instructions{margin-top:28px;background:#050A14;border:1px solid rgba(0,255,163,.1);border-radius:8px;padding:20px}
  .instructions h3{color:#00FFA3;font-size:13px;margin-bottom:14px;text-transform:uppercase;letter-spacing:1px}
  .step{padding:6px 0;font-size:12px;color:#8CA5BE;line-height:1.7}
  .step strong{color:#E8F4F8}
  code{background:#0A1628;padding:2px 8px;border-radius:4px;color:#00FFA3;font-size:11px;display:block;margin:6px 0;padding:8px 12px;word-break:break-all}
</style>
</head>
<body>
<div class="card">
  <h1>◈ Solana Agent Bot</h1>
  <p class="sub">Webhook Endpoint Status</p>

  <div class="status-badge"><?= $statusText ?></div>

  <div class="row"><span class="label">Bot Token</span><span class="val <?= $token ? 'ok' : 'bad' ?>"><?= $tokenStatus ?></span></div>
  <?php if ($botName): ?><div class="row"><span class="label">Bot Username</span><span class="val ok">@<?= htmlspecialchars($botName) ?></span></div><?php endif ?>
  <div class="row"><span class="label">Webhook URL</span><span class="val"><?= htmlspecialchars($webhookUrl) ?></span></div>
  <div class="row"><span class="label">Registered URL</span><span class="val <?= $registered ? 'ok' : 'bad' ?>"><?= $registered ? htmlspecialchars($webhookSet) : 'Not registered' ?></span></div>
  <div class="row"><span class="label">Network</span><span class="val"><?= strtoupper($config['solana']['network'] ?? 'devnet') ?></span></div>
  <div class="row"><span class="label">Endpoint accepts</span><span class="val">POST (from Telegram servers only)</span></div>

  <?php if (!$registered && $token && $baseUrl): ?>
  <a class="btn" href="register-webhook.php">⚡ Register Webhook Now</a>
  <?php endif ?>

  <div class="instructions">
    <h3>How to Register Webhook</h3>
    <?php if (!$token): ?>
    <div class="step">❶ <strong>Add your Bot Token</strong> to <code>config/config.php</code> then run <code>setup.php</code></div>
    <?php elseif (!$baseUrl): ?>
    <div class="step">❶ <strong>Set your base URL</strong> in <code>config/config.php</code>:<br>
    <code>'base_url' => 'https://yourdomain.com'</code></div>
    <?php else: ?>
    <div class="step">❶ <strong>Option A — Use the helper page:</strong><br>Visit <a href="register-webhook.php" style="color:#00FFA3">register-webhook.php</a></div>
    <div class="step">❷ <strong>Option B — Admin panel:</strong><br>Go to <a href="index.php?page=settings" style="color:#00FFA3">Admin → Settings</a> and click "Set Webhook"</div>
    <div class="step">❸ <strong>Option C — Direct API call:</strong>
    <code>https://api.telegram.org/bot<?= htmlspecialchars($token) ?>/setWebhook?url=<?= urlencode($webhookUrl) ?></code>
    Paste that URL in your browser to register instantly.</div>
    <?php endif ?>
  </div>
</div>
</body>
</html>
<?php
    exit;
}

// ─── Only accept POST from Telegram ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ─── Validate webhook secret (optional but recommended) ───────────────────────
$secret = $config['telegram']['webhook_secret'] ?? '';
if ($secret) {
    $receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($secret, $receivedSecret)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// ─── Read body ────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(200); // Always return 200 to Telegram
    exit;
}

// ─── Respond immediately (Telegram requires < 5s response) ───────────────────
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';

// Flush output buffer so Telegram gets the 200 right away
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

// ─── Now process the update ───────────────────────────────────────────────────
try {
    // Init DB
    $db = Database::getInstance($config['database']['file']);

    // Parse Telegram update
    $update = Telegram::parseUpdate($raw);
    if (!$update) {
        Logger::warn('Could not parse Telegram update');
        exit;
    }

    // Init services
    $crypto        = new Crypto($config['security']['encryption_key']);
    $telegram      = new Telegram($config['telegram']);
    $ai            = new AIManager($config);
    $walletManager = new WalletManager($db, $crypto, $config['solana'] + ['features' => $config['features']]);
    $scheduler     = new Scheduler($db, $walletManager, $telegram, $config);

    // Handle the update
    $handler = new Handler($telegram, $ai, $walletManager, $db, $scheduler, $config);
    $handler->handle($update);

} catch (\Throwable $e) {
    Logger::error('Webhook fatal error: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
