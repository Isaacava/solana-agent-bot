<?php
/**
 * register-webhook.php
 * One-click webhook registration helper
 * Visit this page ONCE to register your webhook with Telegram.
 * You can delete it afterwards.
 */

declare(strict_types=1);

$config  = require __DIR__ . '/config/config.php';
$token   = $config['telegram']['bot_token'] ?? '';
$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');
$secret  = $config['telegram']['webhook_secret'] ?? '';

$webhookUrl = $baseUrl . '/webhook.php';
$result     = null;
$error      = null;
$currentInfo = null;

// Fetch current webhook info
if ($token) {
    $ch = curl_init("https://api.telegram.org/bot{$token}/getWebhookInfo");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true]);
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res) {
        $data = json_decode($res, true);
        $currentInfo = $data['result'] ?? null;
    }
}

// Handle registration action
$action = $_GET['action'] ?? '';

if ($action === 'set' && $token) {
    $params = ['url' => $webhookUrl];
    if ($secret) $params['secret_token'] = $secret;

    $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $result = $res ? json_decode($res, true) : null;

    if ($result && $result['ok']) {
        header('Location: register-webhook.php?registered=1');
        exit;
    } else {
        $error = $result['description'] ?? 'Request failed. Check your token and base URL.';
    }
}

if ($action === 'delete' && $token) {
    $ch = curl_init("https://api.telegram.org/bot{$token}/deleteWebhook");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 8]);
    curl_exec($ch);
    curl_close($ch);
    header('Location: register-webhook.php?deleted=1');
    exit;
}

$registered = isset($_GET['registered']);
$deleted    = isset($_GET['deleted']);
$isSet      = !empty($currentInfo['url']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register Webhook — Solana Agent Bot</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#050A14;color:#E8F4F8;font-family:'Courier New',monospace;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,163,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,163,.03) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
  .card{background:#0D1B2E;border:1px solid rgba(0,255,163,.2);border-radius:12px;padding:36px;max-width:560px;width:100%;position:relative;overflow:hidden}
  .card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,transparent,#00FFA3,transparent)}
  h1{font-size:20px;color:#00FFA3;margin-bottom:4px}
  .sub{color:#4A6480;font-size:12px;margin-bottom:28px}
  .row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
  .row:last-child{border-bottom:none}
  .label{color:#8CA5BE}
  .val{color:#E8F4F8;font-size:12px;max-width:300px;text-align:right;word-break:break-all}
  .ok{color:#00FFA3} .bad{color:#FF4757} .warn{color:#FFB800}
  .alert{padding:12px 16px;border-radius:8px;margin:16px 0;font-size:13px}
  .alert-ok{background:rgba(0,255,163,.1);border:1px solid rgba(0,255,163,.3);color:#00FFA3}
  .alert-err{background:rgba(255,71,87,.1);border:1px solid rgba(255,71,87,.3);color:#FF4757}
  .alert-warn{background:rgba(255,184,0,.1);border:1px solid rgba(255,184,0,.3);color:#FFB800}
  .actions{display:flex;gap:12px;margin-top:28px;flex-wrap:wrap}
  .btn{padding:12px 24px;border:none;border-radius:8px;font-family:monospace;font-size:14px;font-weight:bold;cursor:pointer;text-decoration:none;transition:all .2s;display:inline-block}
  .btn-primary{background:#00FFA3;color:#050A14}
  .btn-primary:hover{background:#14F195;transform:translateY(-1px);box-shadow:0 4px 15px rgba(0,255,163,.3)}
  .btn-danger{background:rgba(255,71,87,.15);color:#FF4757;border:1px solid rgba(255,71,87,.3)}
  .btn-danger:hover{background:rgba(255,71,87,.25)}
  .btn-muted{background:rgba(255,255,255,.07);color:#8CA5BE}
  .btn-muted:hover{background:rgba(255,255,255,.12)}
  .info-box{margin-top:24px;background:#050A14;border:1px solid rgba(0,255,163,.1);border-radius:8px;padding:18px}
  .info-box h3{color:#00FFA3;font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px}
  .step{font-size:12px;color:#8CA5BE;padding:4px 0;line-height:1.7}
  code{background:#0A1628;padding:3px 8px;border-radius:4px;color:#00FFA3;font-size:11px;word-break:break-all}
  .missing{padding:16px;background:rgba(255,71,87,.07);border:1px solid rgba(255,71,87,.2);border-radius:8px;margin-bottom:20px;font-size:13px;color:#FF4757}
</style>
</head>
<body>
<div class="card">
  <h1>◈ Webhook Registration</h1>
  <p class="sub">Solana Agent Bot — Telegram Setup</p>

  <?php if ($registered): ?>
  <div class="alert alert-ok">✅ Webhook registered successfully! Your bot is now live.</div>
  <?php elseif ($deleted): ?>
  <div class="alert alert-warn">⚠️ Webhook deleted. Bot will stop receiving messages.</div>
  <?php elseif ($error): ?>
  <div class="alert alert-err">❌ <?= htmlspecialchars($error) ?></div>
  <?php endif ?>

  <?php if (!$token): ?>
  <div class="missing">❌ <strong>Bot Token not configured.</strong><br>
  Add your token to <code>config/config.php</code> or run <a href="setup.php" style="color:#FF4757">setup.php</a> first.</div>
  <?php elseif (!$baseUrl): ?>
  <div class="missing">❌ <strong>Base URL not configured.</strong><br>
  Set <code>'base_url' => 'https://yourdomain.com'</code> in <code>config/config.php</code></div>
  <?php endif ?>

  <!-- Current Status -->
  <div class="row">
    <span class="label">Webhook Status</span>
    <span class="val <?= $isSet ? 'ok' : 'bad' ?>"><?= $isSet ? '✅ Active' : '❌ Not Set' ?></span>
  </div>
  <div class="row">
    <span class="label">Current URL</span>
    <span class="val"><?= $isSet ? htmlspecialchars($currentInfo['url']) : '—' ?></span>
  </div>
  <div class="row">
    <span class="label">Will Register</span>
    <span class="val ok"><?= htmlspecialchars($webhookUrl) ?></span>
  </div>
  <?php if (!empty($currentInfo['last_error_message'])): ?>
  <div class="row">
    <span class="label">Last Error</span>
    <span class="val bad"><?= htmlspecialchars($currentInfo['last_error_message']) ?></span>
  </div>
  <?php endif ?>
  <div class="row">
    <span class="label">Pending Updates</span>
    <span class="val"><?= $currentInfo['pending_update_count'] ?? 0 ?></span>
  </div>
  <div class="row">
    <span class="label">Secret Header</span>
    <span class="val <?= $secret ? 'ok' : 'warn' ?>"><?= $secret ? '✅ Set' : '⚠️ Not set (optional)' ?></span>
  </div>

  <!-- Action Buttons -->
  <div class="actions">
    <?php if ($token && $baseUrl): ?>
    <a href="?action=set" class="btn btn-primary">⚡ <?= $isSet ? 'Re-Register' : 'Register' ?> Webhook</a>
    <?php if ($isSet): ?>
    <a href="?action=delete" class="btn btn-danger" onclick="return confirm('Delete webhook? Bot will stop working.')">🗑 Delete Webhook</a>
    <?php endif ?>
    <?php endif ?>
    <a href="index.php" class="btn btn-muted">← Admin Panel</a>
  </div>

  <!-- Manual Options -->
  <div class="info-box">
    <h3>Manual Registration (Alternative)</h3>
    <div class="step">Paste this URL directly in your browser:</div>
    <code>https://api.telegram.org/bot<?= htmlspecialchars($token ?: 'YOUR_TOKEN') ?>/setWebhook?url=<?= urlencode($webhookUrl) ?></code>
    <div class="step" style="margin-top:10px">Or via curl in terminal:</div>
    <code>curl "https://api.telegram.org/bot<?= htmlspecialchars($token ?: 'YOUR_TOKEN') ?>/setWebhook?url=<?= urlencode($webhookUrl) ?>"</code>
  </div>

  <?php if ($registered || $isSet): ?>
  <div class="info-box" style="margin-top:12px;border-color:rgba(0,255,163,.2)">
    <h3>✅ Next Steps</h3>
    <div class="step">1. Open Telegram and search for your bot</div>
    <div class="step">2. Send <code>/start</code> to test it</div>
    <div class="step">3. Set up cron for price alerts & scheduled tasks</div>
    <div class="step">4. ⚠️ Delete <code>register-webhook.php</code> from your server when done</div>
  </div>
  <?php endif ?>
</div>
</body>
</html>
