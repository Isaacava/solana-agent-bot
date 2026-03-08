<?php
/**
 * Admin Dashboard — Solana Agent Bot
 */
declare(strict_types=1);
session_start();

require_once __DIR__ . '/src/autoload.php';

use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\Crypto;
use SolanaAgent\Features\Price;
use SolanaAgent\Bot\Telegram;
use SolanaAgent\Solana\RPC;

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Africa/Lagos');

function isLoggedIn(): bool { return !empty($_SESSION['admin_logged_in']); }
function requireLogin(): void { if (!isLoggedIn()) { header('Location: ?page=login'); exit; } }

$page    = $_GET['page'] ?? (isLoggedIn() ? 'dashboard' : 'login');
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'login') {
        $u = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';
        if ($u === $config['security']['admin_username']
            && Crypto::verifyPassword($p, $config['security']['admin_password'])) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: ?page=dashboard'); exit;
        }
        $error = 'Invalid username or password.';
    }
    if ($page === 'set_webhook' && isLoggedIn()) {
        try {
            $tg = new Telegram($config['telegram']);
            $tg->setWebhook($_POST['webhook_url'] ?? '', $config['telegram']['webhook_secret']);
            $message = 'Webhook registered successfully!';
        } catch (\Throwable $e) { $error = $e->getMessage(); }
    }
}
if ($page === 'logout') { session_destroy(); header('Location: ?page=login'); exit; }

$stats = []; $recentTx = []; $recentLogs = []; $solPrice = null;
$dbError = null; $webhookInfo = null; $db = null;

if (isLoggedIn()) {
    try {
        $db         = Database::getInstance($config['database']['file']);
        $stats      = $db->getStats();
        $recentTx   = $db->getRecentTransactions(20);
        $recentLogs = $db->getRecentLogs(50);

        $stats['total_agents']      = $stats['total_users'];
        $stats['active_goals']      = (int)$db->fetch('SELECT COUNT(*) c FROM conditional_tasks WHERE triggered=0')['c'];
        $stats['triggered_goals']   = (int)$db->fetch('SELECT COUNT(*) c FROM conditional_tasks WHERE triggered=1')['c'];
        $stats['pending_tasks']     = (int)$db->fetch('SELECT COUNT(*) c FROM scheduled_tasks WHERE executed=0')['c'];
        // DeFi-specific stats
        $stats['swap_goals']        = (int)$db->fetch("SELECT COUNT(*) c FROM conditional_tasks WHERE triggered=0 AND action_type IN ('swap_sol_usdc','swap_usdc_sol')")['c'];
        $stats['send_goals']        = (int)$db->fetch("SELECT COUNT(*) c FROM conditional_tasks WHERE triggered=0 AND action_type='send_sol'")['c'];
        $stats['total_swaps']       = (int)$db->fetch("SELECT COUNT(*) c FROM conditional_tasks WHERE triggered=1 AND action_type IN ('swap_sol_usdc','swap_usdc_sol')")['c'];
        $stats['active_strategies'] = (int)$db->fetch("SELECT COUNT(*) c FROM trading_strategies WHERE status='active'")['c'];
        $stats['done_strategies']   = (int)$db->fetch("SELECT COUNT(*) c FROM trading_strategies WHERE status IN ('completed','stopped')")['c'];

    } catch (\Throwable $e) { $dbError = $e->getMessage(); }
    try { $solPrice = Price::getSolPrice(); } catch (\Throwable $ignored) {}
    if ($page === 'settings') {
        try {
            $tg = new Telegram($config['telegram']);
            $webhookInfo = $tg->getWebhookInfo()['result'] ?? null;
        } catch (\Throwable $ignored) {}
    }
}

// ── Helper: decode any goal into a human-readable summary ─────────────────────
// ── Token icon helper ─────────────────────────────────────────────────────────
function tokenBadge(string $sym): string {
    $icons = [
        'SOL'  => 'https://assets.coingecko.com/coins/images/4128/small/solana.png',
        'USDC' => 'https://assets.coingecko.com/coins/images/6319/small/usdc.png',
    ];
    $cls  = strtolower($sym);
    $icon = $icons[strtoupper($sym)] ?? '';
    // alt="" prevents doubled text (icon + label) when image loads
    $img  = $icon ? '<img src="'.htmlspecialchars($icon).'" alt="" loading="lazy" onerror="this.style.display=\'none\'">' : '';
    return '<span class="defi-token '.$cls.'">'.$img.htmlspecialchars($sym).'</span>';
}
function tokenFlow(string $from, string $to): string {
    return tokenBadge($from).'<span class="defi-arrow">→</span>'.tokenBadge($to);
}

function describeGoal(array $g): array {
    $pl         = json_decode($g['action_payload'], true) ?? [];
    $actionType = $g['action_type'] ?? 'send_sol';
    $condDir    = $g['condition_type'] === 'price_above' ? 'above' : 'below';
    $condEmoji  = $g['condition_type'] === 'price_above' ? '📈' : '📉';
    $condPrice  = '$' . number_format((float)$g['condition_value'], 2);

    switch ($actionType) {
        case 'swap_sol_usdc':
            $from   = 'SOL';
            $to     = 'USDC';
            $amount = (string)($pl['amount'] ?? '?') . ' SOL';
            $icon   = '🔄';
            $label  = 'DeFi Swap';
            $detail = "Swap {$amount} → USDC when SOL {$condDir} {$condPrice}";
            $type   = 'swap';
            break;
        case 'swap_usdc_sol':
            $from   = 'USDC';
            $to     = 'SOL';
            $amount = (string)($pl['amount'] ?? '?') . ' USDC';
            $icon   = '🔄';
            $label  = 'DeFi Swap';
            $detail = "Swap {$amount} → SOL when SOL {$condDir} {$condPrice}";
            $type   = 'swap';
            break;
        case 'send_sol':
        default:
            $from   = 'SOL';
            $to     = null;
            $amount = (string)($pl['amount'] ?? '?') . ' SOL';
            $dest   = isset($pl['to']) ? substr($pl['to'],0,8).'…'.substr($pl['to'],-6) : '?';
            $icon   = '📤';
            $label  = 'Send SOL';
            $detail = "Send {$amount} → {$dest} when SOL {$condDir} {$condPrice}";
            $type   = 'send';
            break;
    }

    return compact('icon','label','detail','type','condEmoji','condDir','condPrice','amount','from','to','pl','actionType');
}

$navItems = [
    'dashboard'    => ['◉', 'Dashboard'],
    'agents'       => ['◈', 'Agents'],
    'goals'        => ['🎯', 'Goals'],
    'defi'         => ['🔄', 'DeFi'],
    'strategies'   => ['🤖', 'Strategies'],
    'tasks'        => ['⏲', 'Scheduler'],
    'transactions' => ['⇄', 'Transactions'],
    'logs'         => ['≡', 'Logs'],
    'settings'     => ['⚙', 'Settings'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= ucfirst($page) ?> — SolanaAgent Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#030C18;--bg2:#071525;--surface:#0B1D32;--surface2:#0F2540;
  --border:rgba(0,255,163,.09);--borderhl:rgba(0,255,163,.26);
  --accent:#00FFA3;--purple:#9945FF;--red:#FF4757;--yellow:#FFB800;--blue:#00C2FF;--orange:#FF8C42;
  --tx:#EAF4FB;--tx2:#7FA3BF;--tx3:#3A5872;
  --mono:'Space Mono',monospace;--sans:'DM Sans',system-ui,sans-serif;
  --r:10px;--sw:232px;--hh:56px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{font-size:14px}
body{background:var(--bg);color:var(--tx);font-family:var(--sans);min-height:100vh}
a{color:var(--accent);text-decoration:none}a:hover{text-decoration:underline}
body::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(rgba(0,255,163,.018) 1px,transparent 1px),
  linear-gradient(90deg,rgba(0,255,163,.018) 1px,transparent 1px);background-size:48px 48px}

.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;position:relative;z-index:1}
.auth-card{background:var(--surface);border:1px solid var(--borderhl);border-radius:16px;padding:44px 40px;width:100%;max-width:400px;position:relative;overflow:hidden}
.auth-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,transparent,var(--accent),var(--purple),transparent)}
.auth-brand{text-align:center;margin-bottom:36px}
.auth-brand .hex{font-size:52px;color:var(--accent);display:block;line-height:1;margin-bottom:12px}
.auth-brand h1{font-family:var(--mono);font-size:22px}.auth-brand h1 span{color:var(--accent)}
.auth-brand p{color:var(--tx3);font-size:12px;margin-top:5px}

.app{display:flex;min-height:100vh;position:relative;z-index:1}
.mob-header{display:none;position:fixed;top:0;left:0;right:0;height:var(--hh);background:var(--surface);border-bottom:1px solid var(--border);z-index:200;align-items:center;padding:0 14px;gap:10px}
.mob-brand{font-family:var(--mono);font-size:14px;color:var(--accent);flex:1}
.hamburger{width:36px;height:36px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;cursor:pointer;flex-shrink:0;transition:border-color .2s}
.hamburger:hover{border-color:var(--borderhl)}
.hamburger span{display:block;width:18px;height:2px;background:var(--tx2);border-radius:2px;transition:all .25s}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0)}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}

.sidebar{width:var(--sw);flex-shrink:0;position:fixed;top:0;left:0;bottom:0;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:150;transition:transform .3s cubic-bezier(.4,0,.2,1)}
.sidebar-glow{position:absolute;top:0;right:0;bottom:0;width:1px;background:linear-gradient(to bottom,transparent,var(--accent) 40%,var(--purple) 70%,transparent);opacity:.2}
.sb-brand{padding:0 18px;height:62px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-hex{font-size:24px;color:var(--accent);animation:breathe 4s ease-in-out infinite}
@keyframes breathe{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.45;transform:scale(.88)}}
.sb-name{font-family:var(--mono);font-size:14px}.sb-name span{color:var(--accent)}
.sb-section{padding:10px 16px 3px;font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--tx3)}
.sb-nav{list-style:none;padding:6px 10px;flex:1;overflow-y:auto}
.sb-nav li{margin-bottom:1px}
.sb-nav li a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:8px;color:var(--tx2);font-size:13px;font-weight:500;transition:all .16s;border-left:3px solid transparent}
.sb-nav li a .ni{font-size:15px;width:20px;text-align:center;flex-shrink:0}
.sb-nav li a:hover{background:rgba(0,255,163,.06);color:var(--tx);text-decoration:none}
.sb-nav li.active a{background:rgba(0,255,163,.10);color:var(--accent);border-left-color:var(--accent)}
.sb-foot{padding:14px;border-top:1px solid var(--border);flex-shrink:0}
.sol-box{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:10px}
.sol-row{display:flex;align-items:center;justify-content:space-between}
.sol-lbl{font-family:var(--mono);font-size:10px;color:var(--tx3);text-transform:uppercase}
.sol-val{font-family:var(--mono);font-size:15px;font-weight:700}
.sol-ch{font-size:11px;margin-top:2px;text-align:right}
.sol-ch.up{color:var(--accent)}.sol-ch.dn{color:var(--red)}
.logout-a{display:flex;align-items:center;gap:7px;padding:8px 10px;border-radius:8px;color:var(--tx3);font-size:12px;transition:all .16s}
.logout-a:hover{background:rgba(255,71,87,.08);color:var(--red);text-decoration:none}
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:140;backdrop-filter:blur(2px)}
.overlay.open{display:block}

.main{flex:1;margin-left:var(--sw);padding:32px;min-width:0}
.ph{display:flex;align-items:center;gap:12px;margin-bottom:26px;padding-bottom:16px;border-bottom:1px solid var(--border)}
.ph h2{font-family:var(--mono);font-size:18px;flex:1}
.live-badge{display:flex;align-items:center;gap:5px;padding:4px 10px;background:rgba(0,255,163,.1);border:1px solid rgba(0,255,163,.22);border-radius:20px;font-size:11px;color:var(--accent);font-family:var(--mono)}
.live-dot{width:6px;height:6px;border-radius:50%;background:var(--accent);animation:blink 2s ease-in-out infinite;flex-shrink:0}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.15}}

.sg{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:24px}
.sc{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:18px 16px;display:flex;align-items:flex-start;gap:14px;transition:border-color .2s,transform .2s;position:relative;overflow:hidden}
.sc:hover{border-color:var(--borderhl);transform:translateY(-2px)}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.sc.cg::before{background:var(--accent)}.sc.cp::before{background:var(--purple)}
.sc.cy::before{background:var(--yellow)}.sc.cr::before{background:var(--red)}
.sc.cb::before{background:var(--blue)}.sc.co::before{background:var(--orange)}
.sc-icon{font-size:20px;flex-shrink:0;margin-top:2px}
.sc-lbl{font-size:11px;color:var(--tx3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.sc-num{font-family:var(--mono);font-size:24px;font-weight:700;line-height:1}
.sc-sub{font-size:11px;color:var(--tx3);margin-top:4px}
.sc-sub.up{color:var(--accent)}.sc-sub.dn{color:var(--red)}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:20px}
.ch{padding:12px 18px;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);background:rgba(0,255,163,.02)}
.ch h3{font-family:var(--mono);font-size:11px;text-transform:uppercase;letter-spacing:1.1px;color:var(--accent);flex:1}
.ch-badge{font-family:var(--mono);font-size:11px;color:var(--tx3);background:var(--surface2);padding:2px 8px;border-radius:4px}
.cb{padding:18px}.cb-np{padding:0}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px}

.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:480px}
thead th{padding:10px 14px;text-align:left;font-family:var(--mono);font-size:10.5px;text-transform:uppercase;letter-spacing:.8px;color:var(--tx3);border-bottom:1px solid var(--border);white-space:nowrap;background:rgba(0,0,0,.12)}
tbody td{padding:11px 14px;color:var(--tx2);font-size:13px;border-bottom:1px solid rgba(255,255,255,.03);white-space:nowrap}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:rgba(0,255,163,.03);color:var(--tx)}

.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:5px;font-family:var(--mono);font-size:10px;letter-spacing:.3px;font-weight:700;text-transform:uppercase;white-space:nowrap}
.bg{background:rgba(0,255,163,.12);color:#00FFA3}
.by{background:rgba(255,184,0,.12);color:#FFB800}
.br{background:rgba(255,71,87,.12);color:#FF4757}
.bp{background:rgba(153,69,255,.12);color:#BB88FF}
.bb{background:rgba(0,194,255,.12);color:#00C2FF}
.bo{background:rgba(255,140,66,.12);color:#FF8C42}
.bgr{background:rgba(255,255,255,.06);color:var(--tx3)}
.blive{background:rgba(0,255,163,.12);color:var(--accent);animation:blink 2.5s ease-in-out infinite}

/* GOAL CARDS — rich layout */
.goal-list{display:flex;flex-direction:column;gap:10px}
.goal-item{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:0;overflow:hidden;transition:border-color .2s,transform .18s}
.goal-item:hover{border-color:var(--borderhl);transform:translateY(-1px)}
.goal-item.triggered{opacity:.45;pointer-events:none}
.goal-item.type-swap{border-left:3px solid var(--orange)}
.goal-item.type-send{border-left:3px solid var(--blue)}
.goal-header{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)}
.goal-icon{font-size:20px;flex-shrink:0}
.goal-body{flex:1;min-width:0}
.goal-title{font-size:13px;font-weight:600;margin-bottom:3px;line-height:1.4}
.goal-subtitle{font-family:var(--mono);font-size:10px;color:var(--tx3)}
.goal-badges{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.goal-detail{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:0;padding:0}
.goal-detail-cell{padding:10px 16px;border-right:1px solid var(--border)}
.goal-detail-cell:last-child{border-right:none}
.goal-detail-lbl{font-family:var(--mono);font-size:9px;text-transform:uppercase;letter-spacing:.7px;color:var(--tx3);margin-bottom:3px}
.goal-detail-val{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--tx)}
.goal-detail-val.accent{color:var(--accent)}
.goal-detail-val.yellow{color:var(--yellow)}
.goal-detail-val.orange{color:var(--orange)}
.goal-detail-val.blue{color:var(--blue)}

/* AGENT CARD */
.agent-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:20px}
.agent-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;transition:border-color .2s,transform .2s;position:relative;overflow:hidden}
.agent-card:hover{border-color:var(--borderhl);transform:translateY(-2px)}
.agent-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--purple))}
.agent-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.agent-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--purple));display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:16px;color:var(--bg);font-weight:700;flex-shrink:0}
.agent-name{font-weight:600;font-size:14px}
.agent-id{font-family:var(--mono);font-size:10px;color:var(--tx3)}
.agent-status{margin-left:auto;display:flex;align-items:center;gap:4px;font-size:11px}
.agent-status.online{color:var(--accent)}.agent-status.idle{color:var(--tx3)}
.agent-wallet{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:12px}
.agent-wallet-lbl{font-family:var(--mono);font-size:9px;text-transform:uppercase;color:var(--tx3);letter-spacing:.8px;margin-bottom:4px}
.agent-wallet-addr{font-family:var(--mono);font-size:11px;color:var(--accent)}
.agent-wallet-net{font-family:var(--mono);font-size:9px;color:var(--tx3);float:right;margin-top:-14px}
.agent-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
.agent-stat{background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:8px;text-align:center}
.agent-stat-num{font-family:var(--mono);font-size:16px;font-weight:700;color:var(--accent)}
.agent-stat-lbl{font-size:10px;color:var(--tx3);text-transform:uppercase;letter-spacing:.4px;margin-top:2px}
.agent-goals{font-size:12px;color:var(--tx2)}
.agent-goals strong{color:var(--yellow)}

/* DEFI PAGE */
.defi-flow{display:flex;align-items:center;gap:8px;font-family:var(--mono);font-size:13px}
.defi-token{display:inline-flex;align-items:center;gap:5px;padding:3px 8px 3px 4px;border-radius:20px;font-weight:700;font-size:12px;background:var(--surface2);border:1px solid var(--border)}
.defi-token img{width:18px;height:18px;border-radius:50%;flex-shrink:0}
.defi-arrow{color:var(--tx3);font-size:16px}
.defi-token.sol{color:var(--purple);border-color:rgba(153,69,255,.3)}
.defi-token.usdc{color:var(--blue);border-color:rgba(0,194,255,.3)}
.tok-icon{width:20px;height:20px;border-radius:50%;vertical-align:middle}
.sc-icon img{width:28px;height:28px;border-radius:50%}

/* LOGS */
.lf{max-height:300px;overflow-y:auto}
.lfl{max-height:560px;overflow-y:auto}
.ll{display:grid;grid-template-columns:72px 52px 1fr;gap:8px;padding:5px 18px;font-family:var(--mono);font-size:11px;border-bottom:1px solid rgba(255,255,255,.022);align-items:start}
.ll:hover{background:rgba(0,255,163,.025)}
.lts{color:var(--tx3)}.llvl{text-align:center}.lmsg{color:var(--tx2);word-break:break-word;white-space:normal}
.lctx{color:var(--tx3);font-size:10px;margin-top:1px}
.ll.log-error .llvl,.ll.log-error .lmsg{color:var(--red)}
.ll.log-warn .llvl{color:var(--yellow)}

.field{margin-bottom:16px}
.field label{display:block;font-size:11px;font-weight:600;color:var(--tx2);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
.field input,.field select,.field textarea{width:100%;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--tx);font-family:var(--sans);font-size:14px;transition:border-color .2s;outline:none}
.field input:focus,.field select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,255,163,.08)}
.field small{display:block;margin-top:5px;font-size:11px;color:var(--tx3)}

.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .18s;font-family:var(--sans);text-decoration:none}
.btn:hover{text-decoration:none}
.btn-p{background:var(--accent);color:var(--bg)}
.btn-p:hover{background:#14F195;transform:translateY(-1px);box-shadow:0 4px 18px rgba(0,255,163,.3)}
.btn-g{background:var(--surface2);color:var(--tx2);border:1px solid var(--border)}
.btn-g:hover{border-color:var(--borderhl);color:var(--tx)}
.btn-w{display:flex;width:100%;justify-content:center;margin-top:4px}

.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:10px}
.ao{background:rgba(0,255,163,.07);border:1px solid rgba(0,255,163,.22);color:var(--accent)}
.ae{background:rgba(255,71,87,.07);border:1px solid rgba(255,71,87,.22);color:var(--red)}

.il{list-style:none}
.ii{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.ii:last-child{border-bottom:none}
.ik{font-size:12px;color:var(--tx3);text-transform:uppercase;letter-spacing:.5px;min-width:110px}
.iv{font-family:var(--mono);font-size:12px;background:var(--bg);padding:3px 9px;border-radius:5px;border:1px solid var(--border)}
.codeblock{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:13px 15px;font-family:var(--mono);font-size:11.5px;color:var(--accent);overflow-x:auto;white-space:pre-wrap;word-break:break-all;margin:8px 0 14px}
.tlist{list-style:none}
.titem{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:13px}
.titem:last-child{border-bottom:none}
.titem.done{opacity:.4}
.ttype{font-family:var(--mono);font-size:10px;color:var(--accent);background:rgba(0,255,163,.08);padding:2px 7px;border-radius:4px;flex-shrink:0}
.tdetail{color:var(--tx2);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ttime{font-family:var(--mono);font-size:10px;color:var(--tx3);flex-shrink:0}
.mono{font-family:var(--mono);font-size:12px}
.muted{color:var(--tx3)}
.empty{text-align:center;color:var(--tx3);padding:30px 0;font-size:13px;font-style:italic}
.fw{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.section-title{font-family:var(--mono);font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--tx3);margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid var(--border)}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--surface2);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--accent)}

@media(max-width:900px){
  .mob-header{display:flex}
  .sidebar{transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0);box-shadow:6px 0 40px rgba(0,0,0,.5)}
  .main{margin-left:0;padding:20px 14px;margin-top:var(--hh)}
  .g2,.g3{grid-template-columns:1fr}
  .sg{grid-template-columns:repeat(2,1fr)}
  .agent-grid{grid-template-columns:1fr}
  .goal-detail{grid-template-columns:1fr 1fr}
}
@media(max-width:520px){
  .sg{grid-template-columns:1fr 1fr}
  .auth-card{padding:32px 20px}
  .agent-stats{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<?php if ($page === 'login'): ?>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-brand">
      <span class="hex">◈</span>
      <h1>Solana<span>Agent</span></h1>
      <p>Admin Control Panel</p>
    </div>
    <?php if ($error): ?><div class="alert ae">⚠ <?= htmlspecialchars($error) ?></div><?php endif ?>
    <form method="POST" action="?page=login">
      <div class="field"><label>Username</label>
        <input type="text" name="username" placeholder="admin" autocomplete="username" required></div>
      <div class="field"><label>Password</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required></div>
      <button type="submit" class="btn btn-p btn-w">Sign In →</button>
    </form>
    <p style="text-align:center;margin-top:18px;font-size:12px;color:var(--tx3)">
      First time? <a href="setup.php">Run Setup Wizard</a></p>
  </div>
</div>

<?php else: requireLogin(); ?>

<header class="mob-header">
  <button class="hamburger" id="hb"><span></span><span></span><span></span></button>
  <span class="mob-brand">◈ SolanaAgent</span>
  <?php if ($solPrice): ?>
  <span style="font-family:var(--mono);font-size:11px;color:var(--accent)">
    SOL $<?= number_format($solPrice['usd'],2) ?>
    <span style="color:<?= $solPrice['change_24h']>=0?'var(--accent)':'var(--red)' ?>">
      <?= ($solPrice['change_24h']>=0?'+':'').$solPrice['change_24h'] ?>%
    </span>
  </span>
  <?php endif ?>
</header>
<div class="overlay" id="ov"></div>

<aside class="sidebar" id="sb">
  <div class="sidebar-glow"></div>
  <div class="sb-brand">
    <span class="sb-hex">◈</span>
    <span class="sb-name">Solana<span>Agent</span></span>
  </div>
  <div class="sb-section" style="margin-top:6px">Navigation</div>
  <ul class="sb-nav">
    <?php foreach ($navItems as $k => [$ic, $lb]): ?>
    <li class="<?= $page===$k?'active':'' ?>">
      <a href="?page=<?= $k ?>"><span class="ni"><?= $ic ?></span><?= $lb ?></a>
    </li>
    <?php endforeach ?>
  </ul>
  <div class="sb-foot">
    <?php if ($solPrice): ?>
    <div class="sol-box">
      <div class="sol-row">
        <span class="sol-lbl">◉ Solana</span>
        <span class="sol-val">$<?= number_format($solPrice['usd'],2) ?></span>
      </div>
      <div class="sol-ch <?= $solPrice['change_24h']>=0?'up':'dn' ?>">
        <?= $solPrice['change_24h']>=0?'▲':'▼' ?> <?= abs($solPrice['change_24h']) ?>% (24h)
        <?php if (!empty($solPrice['ngn'])): ?>&nbsp;₦<?= number_format($solPrice['ngn'],0) ?><?php endif ?>
      </div>
    </div>
    <?php endif ?>
    <a href="?page=logout" class="logout-a">← Sign Out</a>
  </div>
</aside>

<main class="main">
<?php if ($message): ?><div class="alert ao">✓ <?= htmlspecialchars($message) ?></div><?php endif ?>
<?php if ($error && $page!=='login'): ?><div class="alert ae">⚠ <?= htmlspecialchars($error) ?></div><?php endif ?>
<?php if ($dbError): ?><div class="alert ae">⚠ DB error: <?= htmlspecialchars($dbError) ?> — <a href="setup.php">Run setup.php</a></div><?php endif ?>

<?php switch ($page):

/* ═══════════════════════════════════════ DASHBOARD ══════════════════════════════════════════ */
case 'dashboard': ?>
<div class="ph">
  <h2>Dashboard</h2>
  <div class="live-badge"><div class="live-dot"></div><span id="rt">live</span></div>
</div>

<div class="sg">
  <div class="sc cg"><div class="sc-icon" style="color:var(--accent)">◈</div>
    <div><div class="sc-lbl">Agents</div>
    <div class="sc-num"><?= number_format($stats['total_agents']??0) ?></div>
    <div class="sc-sub">registered users</div></div></div>

  <div class="sc cp"><div class="sc-icon"><img src="https://assets.coingecko.com/coins/images/4128/small/solana.png" alt="SOL" class="tok-icon"></div>
    <div><div class="sc-lbl">Wallets</div>
    <div class="sc-num"><?= number_format($stats['total_wallets']??0) ?></div>
    <div class="sc-sub">on-chain keys</div></div></div>

  <div class="sc cg"><div class="sc-icon" style="color:var(--accent)">⇄</div>
    <div><div class="sc-lbl">Transactions</div>
    <div class="sc-num"><?= number_format($stats['total_tx']??0) ?></div>
    <div class="sc-sub">all time</div></div></div>

  <div class="sc cy"><div class="sc-icon" style="color:var(--yellow)">🎯</div>
    <div><div class="sc-lbl">Active Goals</div>
    <div class="sc-num"><?= number_format($stats['active_goals']??0) ?></div>
    <div class="sc-sub"><?= ($stats['swap_goals']??0) ?> swap · <?= ($stats['send_goals']??0) ?> send</div></div></div>

  <div class="sc co"><div class="sc-icon" style="color:var(--orange)">🔄</div>
    <div><div class="sc-lbl">DeFi Swaps</div>
    <div class="sc-num"><?= number_format($stats['total_swaps']??0) ?></div>
    <div class="sc-sub">goals executed</div></div></div>

  <div class="sc cb"><div class="sc-icon" style="color:var(--blue)">🤖</div>
    <div><div class="sc-lbl">Strategies</div>
    <div class="sc-num"><?= number_format($stats['active_strategies']??0) ?></div>
    <div class="sc-sub"><?= ($stats['done_strategies']??0) ?> completed</div></div></div>

  <?php if ($solPrice): ?>
  <div class="sc cg"><div class="sc-icon" style="color:var(--accent)">◉</div>
    <div><div class="sc-lbl">SOL/USD</div>
    <div class="sc-num">$<?= number_format($solPrice['usd'],2) ?></div>
    <div class="sc-sub <?= $solPrice['change_24h']>=0?'up':'dn' ?>">
      <?= $solPrice['change_24h']>=0?'▲':'▼' ?> <?= abs($solPrice['change_24h']) ?>% 24h
    </div></div></div>
  <?php endif ?>
</div>

<div class="g2">
  <div class="card">
    <div class="ch"><h3>Recent Agent Activity</h3>
      <a href="?page=transactions" class="ch-badge">View all →</a></div>
    <div class="cb cb-np">
      <?php $r5 = array_slice($recentTx,0,6); ?>
      <?php if (empty($r5)): ?><p class="empty">No activity yet</p>
      <?php else: ?>
      <div class="tw"><table>
        <thead><tr><th>Agent</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($r5 as $tx): ?>
        <tr>
          <td><?= htmlspecialchars(($tx['username']?'@'.$tx['username']:null)??$tx['first_name']??'—') ?></td>
          <td class="mono" style="color:var(--accent)"><?= $tx['amount_sol'] ?> SOL</td>
          <td><span class="badge <?= $tx['status']==='submitted'?'bg':'by' ?>"><?= $tx['status'] ?></span></td>
          <td class="muted"><?= date('H:i',strtotime($tx['created_at'])) ?></td>
        </tr>
        <?php endforeach ?>
        </tbody>
      </table></div>
      <?php endif ?>
    </div>
  </div>

  <div class="card">
    <div class="ch"><h3>Active Goals</h3>
      <a href="?page=goals" class="ch-badge">View all →</a></div>
    <div class="cb">
      <?php $activeGoals = $db ? $db->fetchAll(
        'SELECT c.*,u.username,u.first_name FROM conditional_tasks c
         JOIN users u ON c.user_id=u.id WHERE c.triggered=0 ORDER BY c.id DESC LIMIT 6') : []; ?>
      <?php if (empty($activeGoals)): ?>
        <p class="empty">No active goals</p>
        <p style="text-align:center;font-size:12px;color:var(--tx3);margin-top:8px">
          Agents set goals like "swap SOL to USDC when price hits $X"</p>
      <?php else: ?>
        <div class="goal-list">
        <?php foreach ($activeGoals as $g):
          $d     = describeGoal($g);
          $agent = $g['username'] ? '@'.$g['username'] : ($g['first_name'] ?? 'Agent');
        ?>
        <div class="goal-item type-<?= $d['type'] ?>">
          <div class="goal-header">
            <div class="goal-icon"><?= $d['icon'] ?></div>
            <div class="goal-body">
              <div class="goal-title"><?= htmlspecialchars($d['detail']) ?></div>
              <div class="goal-subtitle">👤 <?= htmlspecialchars($agent) ?></div>
            </div>
            <div class="goal-badges">
              <span class="badge <?= $d['type']==='swap'?'bo':'bb' ?>"><?= $d['label'] ?></span>
              <span class="badge by">watching</span>
            </div>
          </div>
        </div>
        <?php endforeach ?>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch"><h3>System Log</h3><span class="live-badge blive" style="font-size:10px"><div class="live-dot"></div>live</span></div>
  <div class="cb cb-np">
    <div class="lf" id="lf">
      <?php foreach (array_slice($recentLogs,0,20) as $l): ?>
      <div class="ll log-<?= $l['level'] ?>">
        <span class="lts"><?= date('H:i:s',strtotime($l['created_at'])) ?></span>
        <span class="llvl">[<?= strtoupper($l['level']) ?>]</span>
        <span class="lmsg"><?= htmlspecialchars($l['message']) ?></span>
      </div>
      <?php endforeach ?>
      <?php if (empty($recentLogs)): ?><p class="empty">No logs yet</p><?php endif ?>
    </div>
  </div>
</div>

<?php break;

/* ═══════════════════════════════════════ AGENTS ═════════════════════════════════════════════ */
case 'agents': ?>
<div class="ph">
  <h2>Agents</h2>
  <span class="badge bg"><?= $stats['total_agents']??0 ?> registered</span>
</div>
<p style="color:var(--tx3);font-size:13px;margin-bottom:20px">
  Each registered user is an autonomous agent — they have an encrypted wallet,
  execute scheduled transactions, and set price-triggered goals and DeFi swaps independently.
</p>

<?php
$agents = $db ? $db->fetchAll(
  'SELECT u.*,
    (SELECT COUNT(*) FROM wallets WHERE user_id=u.id) wc,
    (SELECT COUNT(*) FROM transactions WHERE user_id=u.id) tc,
    (SELECT COUNT(*) FROM scheduled_tasks WHERE user_id=u.id AND executed=0) pending_tasks,
    (SELECT COUNT(*) FROM conditional_tasks WHERE user_id=u.id AND triggered=0) active_goals,
    (SELECT COUNT(*) FROM conditional_tasks WHERE user_id=u.id AND triggered=0 AND action_type IN (\'swap_sol_usdc\',\'swap_usdc_sol\')) swap_goals,
    (SELECT COUNT(*) FROM conditional_tasks WHERE user_id=u.id AND triggered=1) fired_goals,
    (SELECT public_key FROM wallets WHERE user_id=u.id AND is_active=1 LIMIT 1) wallet_addr,
    (SELECT network FROM wallets WHERE user_id=u.id AND is_active=1 LIMIT 1) wallet_net
   FROM users u ORDER BY u.id DESC') : [];
?>

<?php if (empty($agents)): ?>
  <div class="card"><div class="cb"><p class="empty">No agents registered yet.</p></div></div>
<?php else: ?>
<div class="agent-grid">
<?php foreach ($agents as $ag):
  $name     = trim(($ag['first_name']??'') . ' ' . ($ag['last_name']??'')) ?: 'Agent #'.$ag['id'];
  $handle   = $ag['username'] ? '@'.$ag['username'] : 'ID:'.$ag['telegram_id'];
  $letter   = strtoupper(substr($name, 0, 1));
  $isActive = (strtotime($ag['last_seen']) > time() - 3600);
?>
<div class="agent-card">
  <div class="agent-head">
    <div class="agent-avatar"><?= $letter ?></div>
    <div>
      <div class="agent-name"><?= htmlspecialchars($name) ?></div>
      <div class="agent-id"><?= htmlspecialchars($handle) ?></div>
    </div>
    <div class="agent-status <?= $isActive?'online':'idle' ?>">
      <div class="live-dot" style="<?= $isActive?'':'background:var(--tx3);animation:none' ?>"></div>
      <?= $isActive ? 'online' : 'idle' ?>
    </div>
  </div>

  <?php if ($ag['wallet_addr']): ?>
  <div class="agent-wallet">
    <div class="agent-wallet-lbl">Active Wallet</div>
    <span class="agent-wallet-net"><?= strtoupper($ag['wallet_net']??'devnet') ?></span>
    <div class="agent-wallet-addr">
      <?= substr($ag['wallet_addr'],0,10) ?>…<?= substr($ag['wallet_addr'],-10) ?>
    </div>
  </div>
  <?php else: ?>
  <div class="agent-wallet" style="text-align:center;color:var(--tx3);font-size:12px;padding:14px">No wallet yet</div>
  <?php endif ?>

  <div class="agent-stats">
    <div class="agent-stat">
      <div class="agent-stat-num"><?= $ag['tc'] ?></div>
      <div class="agent-stat-lbl">Txs</div>
    </div>
    <div class="agent-stat">
      <div class="agent-stat-num" style="color:var(--yellow)"><?= $ag['active_goals'] ?></div>
      <div class="agent-stat-lbl">Goals</div>
    </div>
    <div class="agent-stat">
      <div class="agent-stat-num" style="color:var(--orange)"><?= $ag['swap_goals'] ?></div>
      <div class="agent-stat-lbl">Swaps</div>
    </div>
    <div class="agent-stat">
      <div class="agent-stat-num" style="color:var(--purple)"><?= $ag['pending_tasks'] ?></div>
      <div class="agent-stat-lbl">Queued</div>
    </div>
  </div>

  <div class="agent-goals">
    <?php if ($ag['swap_goals'] > 0): ?>
      <strong>🔄 <?= $ag['swap_goals'] ?> swap goal<?= $ag['swap_goals']>1?'s':'' ?> active</strong>
    <?php elseif ($ag['active_goals'] > 0): ?>
      <strong>🎯 <?= $ag['active_goals'] ?> goal<?= $ag['active_goals']>1?'s':'' ?> watching</strong>
    <?php elseif ($ag['fired_goals'] > 0): ?>
      <?= $ag['fired_goals'] ?> goal<?= $ag['fired_goals']>1?'s':'' ?> executed ✅
    <?php else: ?>
      <span style="color:var(--tx3)">No active goals</span>
    <?php endif ?>
    <span style="color:var(--tx3);float:right">Last seen <?= date('M d', strtotime($ag['last_seen'])) ?></span>
  </div>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<?php break;

/* ═══════════════════════════════════════ GOALS ══════════════════════════════════════════════ */
case 'goals': ?>
<div class="ph">
  <h2>Goals</h2>
  <span class="badge by"><?= $stats['active_goals']??0 ?> watching</span>
  <span class="badge bg" style="margin-left:4px"><?= $stats['triggered_goals']??0 ?> executed</span>
</div>
<p style="color:var(--tx3);font-size:13px;margin-bottom:20px">
  Autonomous condition-based actions — agents set price triggers for SOL transfers and DeFi swaps.
  The cron monitor checks price every minute and executes automatically when conditions are met.
</p>

<?php
// Tab filter
$filter = $_GET['filter'] ?? 'all';
$whereMap = [
    'all'    => '',
    'swap'   => "AND c.action_type IN ('swap_sol_usdc','swap_usdc_sol')",
    'send'   => "AND c.action_type='send_sol'",
    'active' => 'AND c.triggered=0',
    'done'   => 'AND c.triggered=1',
];
$whereExtra = $whereMap[$filter] ?? '';

$allGoals = $db ? $db->fetchAll(
  "SELECT c.*,u.username,u.first_name FROM conditional_tasks c
   JOIN users u ON c.user_id=u.id WHERE 1=1 {$whereExtra}
   ORDER BY c.triggered ASC, c.id DESC LIMIT 80") : [];

$swapCount  = $db ? (int)$db->fetch("SELECT COUNT(*) c FROM conditional_tasks WHERE action_type IN ('swap_sol_usdc','swap_usdc_sol')")['c'] : 0;
$sendCount  = $db ? (int)$db->fetch("SELECT COUNT(*) c FROM conditional_tasks WHERE action_type='send_sol'")['c'] : 0;
?>

<!-- Filter tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
  <?php foreach ([
    'all'    => ['All', $stats['active_goals']+$stats['triggered_goals'], 'bgr'],
    'swap'   => ['🔄 Swap Goals', $swapCount, 'bo'],
    'send'   => ['📤 Send Goals', $sendCount, 'bb'],
    'active' => ['Watching', $stats['active_goals'], 'by'],
    'done'   => ['Executed', $stats['triggered_goals'], 'bg'],
  ] as $key => [$lbl, $cnt, $cls]): ?>
  <a href="?page=goals&filter=<?= $key ?>"
     style="display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;border:1px solid var(--border);background:<?= $filter===$key?'var(--surface2)':'transparent' ?>;color:<?= $filter===$key?'var(--tx)':'var(--tx3)' ?>;text-decoration:none;transition:all .15s">
    <?= $lbl ?> <span class="badge <?= $cls ?>" style="font-size:9px"><?= $cnt ?></span>
  </a>
  <?php endforeach ?>
</div>

<?php if (empty($allGoals)): ?>
  <div class="card"><div class="cb">
    <p class="empty">No goals found.</p>
    <p style="text-align:center;font-size:13px;color:var(--tx3);margin-top:8px">
      Agents can set goals by saying things like:<br>
      <em>"Swap 50 USDC to SOL when SOL drops below $80"</em><br>
      <em>"Send 0.5 SOL to [address] when SOL reaches $100"</em>
    </p>
  </div></div>
<?php else: ?>
<div class="goal-list">
<?php foreach ($allGoals as $g):
  $d     = describeGoal($g);
  $agent = $g['username'] ? '@'.$g['username'] : ($g['first_name'] ?? 'Agent');
  $pl    = json_decode($g['action_payload'], true) ?? [];
?>
<div class="goal-item type-<?= $d['type'] ?> <?= $g['triggered']?'triggered':'' ?>">
  <!-- Header row -->
  <div class="goal-header">
    <div class="goal-icon"><?= $d['icon'] ?></div>
    <div class="goal-body">
      <div class="goal-title">
        SOL <strong style="color:<?= $g['condition_type']==='price_above'?'var(--accent)':'var(--red)' ?>">
          <?= $g['condition_type']==='price_above'?'↑ above':'↓ below' ?> <?= $d['condPrice'] ?>
        </strong>
        → <?= $d['type']==='swap'?'swap':'send' ?>
        <strong style="color:<?= $d['type']==='swap'?'var(--orange)':'var(--blue)' ?>"><?= htmlspecialchars((string)$d['amount']) ?></strong>
        <?php if ($d['type']==='swap'): ?>
          → <strong style="color:var(--accent)"><?= $d['to'] ?></strong>
        <?php endif ?>
      </div>
      <div class="goal-subtitle">👤 <?= htmlspecialchars($agent) ?> &nbsp;·&nbsp; 🕐 Set <?= date('M d H:i', strtotime($g['created_at'])) ?></div>
    </div>
    <div class="goal-badges">
      <span class="badge <?= $d['type']==='swap'?'bo':'bb' ?>"><?= $d['label'] ?></span>
      <?php if ($g['triggered']): ?>
        <span class="badge bg">✅ executed</span>
      <?php else: ?>
        <span class="badge by">👁 watching</span>
      <?php endif ?>
    </div>
  </div>

  <!-- Detail cells -->
  <div class="goal-detail" style="background:var(--bg2)">
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Trigger</div>
      <div class="goal-detail-val accent">SOL <?= $d['condDir'] ?> <?= $d['condPrice'] ?></div>
    </div>

    <?php if ($d['type'] === 'swap'): ?>
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Swap Pair</div>
      <div class="goal-detail-val">
        <?= tokenFlow($d['from'], $d['to']) ?>
      </div>
    </div>
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Amount</div>
      <div class="goal-detail-val orange"><?= htmlspecialchars((string)($pl['amount'] ?? '?')) ?> <?= $d['from'] ?></div>
    </div>
    <?php if (!empty($pl['amount_type'])): ?>
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Amount Type</div>
      <div class="goal-detail-val"><?= htmlspecialchars((string)($pl['amount_type'] ?? '')) ?></div>
    </div>
    <?php endif ?>

    <?php else: ?>
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Send Amount</div>
      <div class="goal-detail-val blue"><?= htmlspecialchars((string)($pl['amount'] ?? '?')) ?> SOL</div>
    </div>
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Recipient</div>
      <div class="goal-detail-val" style="font-size:10px" title="<?= htmlspecialchars((string)($pl['to'] ?? '')) ?>">
        <?= isset($pl['to']) ? substr($pl['to'],0,8).'…'.substr($pl['to'],-6) : '?' ?>
      </div>
    </div>
    <?php endif ?>

    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Status</div>
      <div class="goal-detail-val" style="color:<?= $g['triggered']?'var(--accent)':'var(--yellow)' ?>">
        <?= $g['triggered'] ? '✅ Executed' : '👁 Watching' ?>
      </div>
    </div>

    <?php if (!empty($g['label'])): ?>
    <div class="goal-detail-cell">
      <div class="goal-detail-lbl">Label</div>
      <div class="goal-detail-val"><?= htmlspecialchars($g['label']) ?></div>
    </div>
    <?php endif ?>
  </div>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<?php break;

/* ═══════════════════════════════════════ DEFI ═══════════════════════════════════════════════ */
case 'defi': ?>
<div class="ph">
  <h2>DeFi</h2>
  <span class="badge bo">Swap Engine</span>
</div>

<?php
// ── Settings (use key_name column) ────────────────────────────────────────────
$liqAddr = $db ? ($db->fetch("SELECT value FROM settings WHERE key_name='swap_liquidity_addr'")['value'] ?? null) : null;
$liqSk   = $db ? ($db->fetch("SELECT value FROM settings WHERE key_name='swap_liquidity_sk'")['value'] ?? null) : null;

// ── Live balances from RPC ─────────────────────────────────────────────────────
$liqSolBal  = null;
$liqUsdcBal = null;
$usdcMint   = 'Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr';
if ($liqAddr) {
    try {
        $rpc = new RPC($config['solana']['rpc_url'] ?? 'https://api.devnet.solana.com');
        $liqSolBal  = $rpc->getBalanceSol($liqAddr);
        $tokenAccts = $rpc->getTokenAccountsByMint($liqAddr, $usdcMint);
        if (!empty($tokenAccts)) {
            $liqUsdcBal = round(
                (float)($tokenAccts[0]['account']['data']['parsed']['info']['tokenAmount']['uiAmount'] ?? 0), 2
            );
        }
    } catch (\Throwable $ignored) {}
}

// ── Swap goals ─────────────────────────────────────────────────────────────────
$swapGoals   = $db ? $db->fetchAll(
  "SELECT c.*,u.username,u.first_name FROM conditional_tasks c
   JOIN users u ON c.user_id=u.id
   WHERE c.action_type IN ('swap_sol_usdc','swap_usdc_sol')
   ORDER BY c.triggered ASC, c.id DESC LIMIT 40") : [];
$activeSwaps  = array_filter($swapGoals, fn($g) => !$g['triggered']);
$executedSwaps= array_filter($swapGoals, fn($g) =>  $g['triggered']);
$solToUsdc    = array_filter($swapGoals, fn($g) => $g['action_type']==='swap_sol_usdc');
$usdcToSol    = array_filter($swapGoals, fn($g) => $g['action_type']==='swap_usdc_sol');

// ── Recent swap transactions ────────────────────────────────────────────────────
$recentSwaps = $db ? $db->fetchAll(
  "SELECT t.*,u.username,u.first_name FROM transactions t
   JOIN users u ON t.user_id=u.id
   WHERE t.type='swap'
   ORDER BY t.id DESC LIMIT 30") : [];

// ── Volume ─────────────────────────────────────────────────────────────────────
$totalSwapVol = $db ? (float)($db->fetch("SELECT COALESCE(SUM(amount_sol),0) v FROM transactions WHERE type='swap'")['v'] ?? 0) : 0;
$swapCount    = $db ? (int)($db->fetch("SELECT COUNT(*) c FROM transactions WHERE type='swap'")['c'] ?? 0) : 0;
?>

<!-- Stat strip -->
<div class="sg" style="margin-bottom:20px">
  <div class="sc co"><div class="sc-icon" style="color:var(--orange)">🔄</div>
    <div><div class="sc-lbl">Total Swaps</div>
    <div class="sc-num"><?= $swapCount ?></div>
    <div class="sc-sub"><?= number_format($totalSwapVol,2) ?> SOL volume</div></div></div>

  <div class="sc cb"><div class="sc-icon"><img src="https://assets.coingecko.com/coins/images/4128/small/solana.png" alt="SOL" class="tok-icon"> → <img src="https://assets.coingecko.com/coins/images/6319/small/usdc.png" alt="USDC" class="tok-icon"></div>
    <div><div class="sc-lbl">SOL→USDC Goals</div>
    <div class="sc-num"><?= count($solToUsdc) ?></div>
    <div class="sc-sub"><?= count(array_filter($solToUsdc,fn($g)=>!$g['triggered'])) ?> watching</div></div></div>

  <div class="sc cp"><div class="sc-icon"><img src="https://assets.coingecko.com/coins/images/6319/small/usdc.png" alt="USDC" class="tok-icon"> → <img src="https://assets.coingecko.com/coins/images/4128/small/solana.png" alt="SOL" class="tok-icon"></div>
    <div><div class="sc-lbl">USDC→SOL Goals</div>
    <div class="sc-num"><?= count($usdcToSol) ?></div>
    <div class="sc-sub"><?= count(array_filter($usdcToSol,fn($g)=>!$g['triggered'])) ?> watching</div></div></div>

  <?php if ($liqAddr): ?>
  <div class="sc cg"><div class="sc-icon"><img src="https://assets.coingecko.com/coins/images/4128/small/solana.png" alt="SOL" class="tok-icon"></div>
    <div><div class="sc-lbl">Pool SOL</div>
    <div class="sc-num"><?= $liqSolBal !== null ? number_format($liqSolBal,2) : '—' ?></div>
    <div class="sc-sub">liquidity wallet</div></div></div>

  <div class="sc cb"><div class="sc-icon"><img src="https://assets.coingecko.com/coins/images/6319/small/usdc.png" alt="USDC" class="tok-icon"></div>
    <div><div class="sc-lbl">Pool USDC</div>
    <div class="sc-num"><?= $liqUsdcBal !== null ? number_format($liqUsdcBal,2) : '—' ?></div>
    <div class="sc-sub">available for swaps</div></div></div>
  <?php endif ?>
</div>

<!-- Liquidity wallet card -->
<div class="card" style="margin-bottom:20px">
  <div class="ch">
    <h3>Liquidity Wallet</h3>
    <span class="badge <?= $liqAddr ? 'bg' : 'br' ?>"><?= $liqAddr ? 'configured' : 'not set' ?></span>
  </div>
  <div class="cb">
    <?php if ($liqAddr): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
      <ul class="il">
        <li class="ii"><span class="ik">Address</span>
          <span class="iv mono" style="color:var(--accent);font-size:11px"><?= htmlspecialchars($liqAddr) ?></span></li>
        <li class="ii"><span class="ik">Network</span>
          <span class="iv"><?= strtoupper($config['solana']['network']??'devnet') ?></span></li>
        <li class="ii"><span class="ik">Keypair</span>
          <span class="iv"><?= $liqSk ? '✅ Encrypted & stored' : '❌ Missing' ?></span></li>
        <li class="ii"><span class="ik">Explorer</span>
          <?php $cluster = ($config['solana']['network']??'devnet')==='mainnet'?'':'?cluster=devnet'; ?>
          <a href="https://explorer.solana.com/address/<?= urlencode($liqAddr) ?><?= $cluster ?>"
             target="_blank" class="badge bb" style="text-decoration:none">View on Explorer ↗</a></li>
      </ul>
      <div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px">
          <div style="font-family:var(--mono);font-size:10px;color:var(--tx3);text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px">Live Pool Balances</div>
          <div style="display:flex;gap:20px;align-items:center">
            <div>
              <div style="font-family:var(--mono);font-size:22px;font-weight:700;color:var(--purple)">
                <?= $liqSolBal !== null ? number_format($liqSolBal, 4) : '—' ?> <span style="font-size:13px">SOL</span>
              </div>
              <?php if ($solPrice && $liqSolBal !== null): ?>
              <div style="font-size:11px;color:var(--tx3)">≈ $<?= number_format($liqSolBal * $solPrice['usd'], 2) ?></div>
              <?php endif ?>
            </div>
            <div style="color:var(--tx3);font-size:20px">+</div>
            <div>
              <div style="font-family:var(--mono);font-size:22px;font-weight:700;color:var(--blue)">
                <?= $liqUsdcBal !== null ? number_format($liqUsdcBal, 2) : '—' ?> <span style="font-size:13px">USDC</span>
              </div>
              <div style="font-size:11px;color:var(--tx3)">stablecoin</div>
            </div>
          </div>
          <?php if ($liqSolBal !== null && $liqSolBal < 1): ?>
          <div style="margin-top:10px;padding:6px 10px;background:rgba(255,184,0,.1);border:1px solid rgba(255,184,0,.3);border-radius:6px;font-size:11px;color:var(--yellow)">
            ⚠️ SOL pool is low — run <code>php setup-defi.php</code> to refill
          </div>
          <?php endif ?>
          <?php if ($liqUsdcBal !== null && $liqUsdcBal < 50): ?>
          <div style="margin-top:8px;padding:6px 10px;background:rgba(255,184,0,.1);border:1px solid rgba(255,184,0,.3);border-radius:6px;font-size:11px;color:var(--yellow)">
            ⚠️ USDC pool is low — run <code>php setup-defi.php</code> to refill
          </div>
          <?php endif ?>
        </div>
      </div>
    </div>
    <p style="color:var(--tx3);font-size:12px;margin-top:12px">
      SOL→USDC: user sends SOL here → pool sends USDC back. USDC→SOL: user sends USDC here → pool sends SOL back.
      Devnet only — uses real on-chain SPL token transfers at live CoinGecko price.
    </p>
    <?php else: ?>
    <p class="empty">Liquidity wallet not configured.</p>
    <div style="text-align:center;margin-top:12px">
      <a href="setup-defi.php" class="btn btn-p">⚡ Configure DeFi Liquidity →</a>
    </div>
    <?php endif ?>
  </div>
</div>

<!-- Recent swap transactions -->
<div class="card" style="margin-bottom:20px">
  <div class="ch">
    <h3>Recent Swap Transactions</h3>
    <span class="ch-badge"><?= $swapCount ?> total</span>
  </div>
  <div class="cb cb-np">
    <?php if (empty($recentSwaps)): ?>
      <p class="empty">No swap transactions yet — they'll appear here after users execute swaps.</p>
    <?php else: ?>
    <div class="tw"><table>
      <thead><tr>
        <th>Agent</th><th>Swap</th><th>From</th><th>To</th><th>Rate</th><th>Status</th><th>Sig</th><th>Date</th>
      </tr></thead>
      <tbody>
      <?php foreach ($recentSwaps as $tx):
        $meta    = json_decode($tx['meta'] ?? '{}', true) ?? [];
        $from    = $meta['from'] ?? '?';
        $to      = $meta['to']   ?? '?';
        $fromAmt = $meta['from_amt'] ?? $tx['amount_sol'];
        $toAmt   = $meta['to_amt']   ?? '?';
        $rate    = $meta['rate']     ?? null;
        $agent   = $tx['username'] ? '@'.$tx['username'] : ($tx['first_name'] ?? '—');
        $sigShort = $tx['signature'] ? substr($tx['signature'],0,8).'…'.substr($tx['signature'],-6) : '—';
        $cluster  = ($config['solana']['network']??'devnet')==='mainnet'?'':'?cluster=devnet';
      ?>
      <tr>
        <td><?= htmlspecialchars($agent) ?></td>
        <td>
          <div class="defi-flow"><?= tokenFlow($from, $to) ?></div>
        </td>
        <td class="mono" style="color:var(--orange)"><?= number_format((float)$fromAmt,4) ?> <?= $from ?></td>
        <td class="mono" style="color:var(--accent)"><?= is_numeric($toAmt) ? number_format((float)$toAmt,4) : $toAmt ?> <?= $to ?></td>
        <td class="mono" style="font-size:11px;color:var(--tx3)"><?= $rate ? '$'.number_format((float)$rate,2) : '—' ?></td>
        <td><span class="badge <?= $tx['status']==='submitted'?'bg':'by' ?>"><?= $tx['status'] ?></span></td>
        <td>
          <?php if ($tx['signature']): ?>
          <a href="https://explorer.solana.com/tx/<?= urlencode($tx['signature']) ?><?= $cluster ?>"
             target="_blank" class="mono" style="font-size:11px;color:var(--blue)"><?= $sigShort ?></a>
          <?php else: ?>—<?php endif ?>
        </td>
        <td class="muted"><?= date('M d H:i', strtotime($tx['created_at'])) ?></td>
      </tr>
      <?php endforeach ?>
      </tbody>
    </table></div>
    <?php endif ?>
  </div>
</div>

<!-- Swap goals -->
<div class="card">
  <div class="ch">
    <h3>Conditional Swap Goals</h3>
    <span class="ch-badge"><?= count($activeSwaps) ?> watching · <?= count($executedSwaps) ?> executed</span>
  </div>
  <div class="cb">
    <?php if (empty($swapGoals)): ?>
    <p class="empty">No swap goals set yet.</p>
    <p style="text-align:center;font-size:13px;color:var(--tx3);margin-top:8px">
      Agents set these by saying things like<br>
      <em>"Swap 50 USDC to SOL when SOL drops below $80"</em>
    </p>
    <?php else: ?>
    <div class="goal-list">
    <?php foreach ($swapGoals as $g):
      $d     = describeGoal($g);
      $pl    = json_decode($g['action_payload'], true) ?? [];
      $agent = $g['username'] ? '@'.$g['username'] : ($g['first_name'] ?? 'Agent');
    ?>
    <div class="goal-item type-swap <?= $g['triggered']?'triggered':'' ?>">
      <div class="goal-header">
        <div class="goal-icon">🔄</div>
        <div class="goal-body">
          <div class="goal-title">
            SOL <strong style="color:<?= $g['condition_type']==='price_above'?'var(--accent)':'var(--red)' ?>">
              <?= $g['condition_type']==='price_above'?'↑':'↓' ?> <?= $d['condPrice'] ?>
            </strong>
            → swap <strong style="color:var(--orange)"><?= htmlspecialchars((string)($pl['amount']??'?')) ?> <?= $d['from'] ?></strong>
            → <strong style="color:var(--accent)"><?= $d['to'] ?></strong>
          </div>
          <div class="goal-subtitle">👤 <?= htmlspecialchars($agent) ?> · <?= date('M d H:i', strtotime($g['created_at'])) ?></div>
        </div>
        <div class="goal-badges">
          <span class="badge <?= $g['action_type']==='swap_sol_usdc'?'bb':'bp' ?>"><?= $d['from'] ?> → <?= $d['to'] ?></span>
          <?= $g['triggered'] ? '<span class="badge bg">✅ done</span>' : '<span class="badge by">👁 watching</span>' ?>
        </div>
      </div>
      <div class="goal-detail" style="background:var(--bg2)">
        <div class="goal-detail-cell">
          <div class="goal-detail-lbl">Trigger</div>
          <div class="goal-detail-val accent">SOL <?= $d['condDir'] ?> <?= $d['condPrice'] ?></div>
        </div>
        <div class="goal-detail-cell">
          <div class="goal-detail-lbl">Pair</div>
          <div class="goal-detail-val">
            <?= tokenFlow($d['from'], $d['to']) ?>
          </div>
        </div>
        <div class="goal-detail-cell">
          <div class="goal-detail-lbl">Amount</div>
          <div class="goal-detail-val orange"><?= htmlspecialchars((string)($pl['amount']??'?')) ?> <?= $d['from'] ?></div>
        </div>
        <div class="goal-detail-cell">
          <div class="goal-detail-lbl">Status</div>
          <div class="goal-detail-val" style="color:<?= $g['triggered']?'var(--accent)':'var(--yellow)' ?>">
            <?= $g['triggered'] ? '✅ Executed' : '👁 Watching' ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
</div>

<?php break;

/* ═══════════════════════════════════════ STRATEGIES ════════════════════════════════════════ */
case 'strategies': ?>
<div class="ph">
  <h2>Trading Strategies</h2>
  <span class="badge bo"><?= $stats['active_strategies']??0 ?> active</span>
  <span class="badge bg" style="margin-left:4px"><?= $stats['done_strategies']??0 ?> completed</span>
</div>
<p style="color:var(--tx3);font-size:13px;margin-bottom:20px">
  Autonomous buy/sell strategies. The agent monitors price every minute, buys at target,
  holds, then sells at profit or stops at the stop-loss automatically.
</p>

<?php
$allStrategies = $db ? $db->fetchAll(
  'SELECT s.*,u.username,u.first_name FROM trading_strategies s
   JOIN users u ON s.user_id=u.id ORDER BY s.status ASC, s.id DESC LIMIT 60') : [];

$phaseCount = ['waiting_buy'=>0,'holding'=>0,'completed'=>0,'stopped'=>0,'cancelled'=>0];
foreach ($allStrategies as $s) {
    $ph = $s['phase'] ?? $s['status'];
    if (isset($phaseCount[$ph])) $phaseCount[$ph]++;
}
?>

<!-- Phase strip -->
<div class="sg" style="margin-bottom:20px">
  <div class="sc cy"><div class="sc-icon" style="color:var(--yellow)">⏳</div>
    <div><div class="sc-lbl">Waiting Buy</div>
    <div class="sc-num"><?= $phaseCount['waiting_buy'] ?></div>
    <div class="sc-sub">watching for dip</div></div></div>

  <div class="sc co"><div class="sc-icon" style="color:var(--orange)">📦</div>
    <div><div class="sc-lbl">Holding</div>
    <div class="sc-num"><?= $phaseCount['holding'] ?></div>
    <div class="sc-sub">watching for sell</div></div></div>

  <div class="sc cg"><div class="sc-icon" style="color:var(--accent)">✅</div>
    <div><div class="sc-lbl">Completed</div>
    <div class="sc-num"><?= $phaseCount['completed'] ?></div>
    <div class="sc-sub">profit secured</div></div></div>

  <div class="sc cr"><div class="sc-icon" style="color:var(--red)">🛑</div>
    <div><div class="sc-lbl">Stopped</div>
    <div class="sc-num"><?= $phaseCount['stopped'] ?></div>
    <div class="sc-sub">stop-loss hit</div></div></div>
</div>

<?php if (empty($allStrategies)): ?>
  <div class="card"><div class="cb">
    <p class="empty">No strategies yet.</p>
    <p style="text-align:center;font-size:13px;color:var(--tx3);margin-top:8px">
      Agents start strategies by saying <em>"grow my SOL"</em> or <em>"set up auto trading for me"</em>.
    </p>
  </div></div>
<?php else: ?>
<div class="card"><div class="cb cb-np">
<div class="tw"><table>
  <thead><tr>
    <th>Agent</th><th>Label</th><th>Phase</th>
    <th>Buy at</th><th>Sell at</th><th>Stop</th>
    <th>Amount</th><th>Est P&L</th><th>Buy TX</th><th>Created</th>
  </tr></thead>
  <tbody>
  <?php foreach ($allStrategies as $s):
    $agent = $s['username'] ? '@'.$s['username'] : ($s['first_name'] ?? 'Agent');
    $phase = $s['phase'] ?? $s['status'];
    [$pClass,$pLabel] = match($phase) {
      'waiting_buy' => ['by',  '⏳ Waiting'],
      'holding'     => ['bo',  '📦 Holding'],
      'completed'   => ['bg',  '✅ Done'],
      'stopped'     => ['br',  '🛑 Stopped'],
      'cancelled'   => ['bgr', '❌ Cancelled'],
      default       => ['bgr', $phase],
    };
    $pnl      = $s['est_profit_pct'] !== null ? '+'.number_format((float)$s['est_profit_pct'],1).'%' : '—';
    $buyPrice  = $s['buy_price']  !== null ? '$'.number_format((float)$s['buy_price'],2)  : '—';
    $sellPrice = $s['sell_price'] !== null ? '$'.number_format((float)$s['sell_price'],2) : '—';
    $stopLoss  = $s['stop_loss']  !== null ? '$'.number_format((float)$s['stop_loss'],2)  : '—';
    $hasBuyTx  = !empty($s['buy_tx']);
    $cluster   = ($config['solana']['network'] ?? 'devnet') === 'mainnet' ? '' : '?cluster=devnet';
  ?>
  <tr>
    <td><?= htmlspecialchars($agent) ?></td>
    <td class="mono" style="font-size:11px"><?= htmlspecialchars($s['label'] ?? '#'.$s['id']) ?></td>
    <td><span class="badge <?= $pClass ?>"><?= $pLabel ?></span></td>
    <td class="mono" style="color:var(--accent)"><?= $buyPrice ?></td>
    <td class="mono" style="color:var(--yellow)"><?= $sellPrice ?></td>
    <td class="mono" style="color:var(--red)"><?= $stopLoss ?></td>
    <td class="mono"><?= $s['amount_sol'] ?> SOL</td>
    <td class="mono" style="color:var(--accent)"><?= $pnl ?></td>
    <td>
      <?php if ($hasBuyTx): ?>
        <a href="https://explorer.solana.com/tx/<?= htmlspecialchars($s['buy_tx']) ?><?= $cluster ?>"
           target="_blank" class="badge bb" style="text-decoration:none">View TX</a>
      <?php else: ?><span class="muted">—</span><?php endif ?>
    </td>
    <td class="muted"><?= date('M d H:i', strtotime($s['created_at'])) ?></td>
  </tr>
  <?php endforeach ?>
  </tbody>
</table></div>
</div></div>
<?php endif ?>

<?php break;

/* ═══════════════════════════════════════ SCHEDULER ══════════════════════════════════════════ */
case 'tasks': ?>
<div class="ph"><h2>Scheduler</h2></div>
<div class="g2">
  <div class="card">
    <div class="ch"><h3>Scheduled Sends</h3>
      <span class="ch-badge"><?= $stats['pending_tasks']??0 ?> pending</span></div>
    <div class="cb">
      <?php $tasks=$db?$db->fetchAll(
        'SELECT t.*,u.username,u.first_name FROM scheduled_tasks t
         JOIN users u ON t.user_id=u.id ORDER BY t.execute_at ASC LIMIT 30'):[];?>
      <?php if(empty($tasks)):?><p class="empty">No scheduled sends.</p><?php else:?>
      <ul class="tlist">
      <?php foreach($tasks as $t): $p=json_decode($t['payload'],true)??[];?>
        <li class="titem <?=$t['executed']?'done':''?>">
          <span class="ttype">send</span>
          <span class="tdetail">
            <?= isset($p['amount'])?$p['amount'].' SOL → '.substr($p['to']??'',0,8).'…':'—' ?>
            <span class="muted" style="margin-left:6px"><?= htmlspecialchars($t['username']??$t['first_name']??'') ?></span>
          </span>
          <span class="ttime"><?=date('M d H:i',strtotime($t['execute_at']))?></span>
          <span class="badge <?=$t['executed']?'bgr':'by'?>"><?=$t['executed']?'done':'pending'?></span>
        </li>
      <?php endforeach?>
      </ul>
      <?php endif?>
    </div>
  </div>

  <div class="card">
    <div class="ch"><h3>Price Alerts</h3>
      <span class="ch-badge"><?= $stats['active_alerts']??0 ?> active</span></div>
    <div class="cb">
      <?php $alerts=$db?$db->fetchAll(
        'SELECT a.*,u.username FROM price_alerts a JOIN users u ON a.user_id=u.id
         ORDER BY a.triggered ASC, a.id DESC LIMIT 30'):[];?>
      <?php if(empty($alerts)):?><p class="empty">No price alerts.</p><?php else:?>
      <ul class="tlist">
      <?php foreach($alerts as $a):?>
        <li class="titem <?=$a['triggered']?'done':''?>">
          <span class="ttype"><?=$a['direction']==='above'?'▲':'▼'?></span>
          <span class="tdetail">
            $<?=number_format($a['target_price'],2)?> <?=$a['direction']?>
            <span class="muted" style="margin-left:6px"><?=htmlspecialchars($a['username']??'')?></span>
          </span>
          <span class="badge <?=$a['triggered']?'bgr':'bg'?>"><?=$a['triggered']?'fired':'watching'?></span>
        </li>
      <?php endforeach?>
      </ul>
      <?php endif?>
    </div>
  </div>
</div>

<?php break;

/* ═══════════════════════════════════════ TRANSACTIONS ═══════════════════════════════════════ */
case 'transactions': ?>
<div class="ph"><h2>Transactions</h2><span class="badge bg"><?=count($recentTx)?> shown</span></div>
<div class="card"><div class="cb cb-np">
  <?php if(empty($recentTx)):?><p class="empty">No transactions yet.</p><?php else:?>
  <div class="tw"><table>
    <thead><tr><th>Agent</th><th>Type</th><th>Amount</th><th>To</th><th>Status</th><th>Net</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach($recentTx as $tx):?>
    <tr>
      <td><?=htmlspecialchars($tx['username']?'@'.$tx['username']:($tx['first_name']??'—'))?></td>
      <td><span class="badge bp"><?=$tx['type']?></span></td>
      <td class="mono" style="color:var(--accent)"><?=$tx['amount_sol']?> SOL</td>
      <td class="mono" style="font-size:11px" title="<?=htmlspecialchars($tx['to_addr']??'')?>">
        <?=$tx['to_addr']?substr($tx['to_addr'],0,6).'…'.substr($tx['to_addr'],-6):'—'?>
      </td>
      <td><span class="badge <?=$tx['status']==='submitted'?'bg':'by'?>"><?=$tx['status']?></span></td>
      <td><span class="badge bgr"><?=strtoupper($tx['network'])?></span></td>
      <td class="muted"><?=date('M d H:i',strtotime($tx['created_at']))?></td>
    </tr>
    <?php endforeach?>
    </tbody>
  </table></div>
  <?php endif?>
</div></div>

<?php break;

/* ═══════════════════════════════════════ LOGS ═══════════════════════════════════════════════ */
case 'logs': ?>
<div class="ph"><h2>System Logs</h2><span class="badge bgr"><?=count($recentLogs)?> entries</span></div>
<div class="card"><div class="cb cb-np">
  <div class="lfl" id="lfl">
    <?php foreach($recentLogs as $l):?>
    <div class="ll log-<?=$l['level']?>">
      <span class="lts"><?=date('H:i:s',strtotime($l['created_at']))?></span>
      <span class="llvl">[<?=strtoupper($l['level'])?>]</span>
      <span class="lmsg">
        <?=htmlspecialchars($l['message'])?>
        <?php if($l['context']):?><span class="lctx"><?=htmlspecialchars($l['context'])?></span><?php endif?>
      </span>
    </div>
    <?php endforeach?>
    <?php if(empty($recentLogs)):?><p class="empty">No logs yet.</p><?php endif?>
  </div>
</div></div>

<?php break;

/* ═══════════════════════════════════════ SETTINGS ═══════════════════════════════════════════ */
case 'settings': ?>
<div class="ph"><h2>Settings</h2></div>
<div class="g2">
  <div class="card">
    <div class="ch">
      <h3>Webhook</h3>
      <?php if(!empty($webhookInfo['url'])): ?>
        <span class="badge bg">Active</span>
      <?php else: ?>
        <span class="badge br">Not Set</span>
      <?php endif?>
    </div>
    <div class="cb">
      <?php if($webhookInfo):?>
      <ul class="il" style="margin-bottom:16px">
        <li class="ii"><span class="ik">URL</span>
          <span class="iv" style="max-width:200px;overflow:hidden;text-overflow:ellipsis" title="<?=htmlspecialchars($webhookInfo['url']??'')?>">
          <?=htmlspecialchars(substr($webhookInfo['url']??'—',0,32))?>…</span></li>
        <li class="ii"><span class="ik">Pending</span><span class="iv"><?=$webhookInfo['pending_update_count']??0?></span></li>
        <?php if(!empty($webhookInfo['last_error_message'])):?>
        <li class="ii"><span class="ik">Last Error</span>
          <span class="iv" style="color:var(--red);font-size:11px"><?=htmlspecialchars($webhookInfo['last_error_message'])?></span></li>
        <?php endif?>
      </ul>
      <?php endif?>
      <form method="POST" action="?page=set_webhook">
        <div class="field"><label>Webhook URL</label>
          <input type="url" name="webhook_url"
            value="<?=htmlspecialchars(rtrim($config['app']['base_url']??'','/')  .'/webhook.php')?>" required></div>
        <div class="fw">
          <button type="submit" class="btn btn-p">⚡ Set Webhook</button>
          <a href="register-webhook.php" class="btn btn-g">Open Helper →</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="ch"><h3>Bot Status</h3></div>
    <div class="cb">
      <ul class="il">
        <li class="ii"><span class="ik">Network</span><span class="iv"><?=strtoupper($config['solana']['network']??'devnet')?></span></li>
        <li class="ii"><span class="ik">AI Primary</span><span class="iv"><?=ucfirst($config['ai']['primary']??'—')?></span></li>
        <li class="ii"><span class="ik">AI Fallback</span><span class="iv"><?=ucfirst($config['ai']['fallback']??'—')?></span></li>
        <li class="ii"><span class="ik">Groq Key</span><span class="iv"><?=!empty($config['ai']['groq']['api_key'])?'✅':'❌'?></span></li>
        <li class="ii"><span class="ik">Gemini Key</span><span class="iv"><?=!empty($config['ai']['gemini']['api_key'])?'✅':'❌'?></span></li>
        <li class="ii"><span class="ik">Encrypt Key</span><span class="iv"><?=!empty($config['security']['encryption_key'])?'✅ Set':'❌ Missing'?></span></li>
        <li class="ii"><span class="ik">PHP</span><span class="iv"><?=PHP_VERSION?></span></li>
        <li class="ii"><span class="ik">Sodium</span><span class="iv"><?=extension_loaded('sodium')?'✅':'❌ Required'?></span></li>
        <li class="ii"><span class="ik">SQLite</span><span class="iv"><?=in_array('sqlite',\PDO::getAvailableDrivers())?'✅':'❌ Required'?></span></li>
      </ul>
    </div>
  </div>
</div>

<div class="card">
  <div class="ch"><h3>Cron Job Setup</h3></div>
  <div class="cb">
    <p style="color:var(--tx2);font-size:13px;margin-bottom:8px">
      Required for autonomous execution — price alerts, scheduled sends, goal-triggered transactions, and DeFi swaps:</p>
    <div class="codeblock">* * * * * php <?=realpath(__DIR__)?>/cron.php >> <?=realpath(__DIR__)?>/data/logs/cron.log 2>&1</div>
    <p style="color:var(--tx3);font-size:12px;margin-bottom:6px">Or trigger via HTTP (cron-job.org, every minute):</p>
    <div class="codeblock"><?=htmlspecialchars(rtrim($config['app']['base_url']??'','/').'/cron.php?secret='.($config['security']['cron_secret']??'YOUR_SECRET'))?></div>
  </div>
</div>

<?php break; endswitch ?>
</main>

<script>
(function(){
  var hb=document.getElementById('hb'),sb=document.getElementById('sb'),ov=document.getElementById('ov');
  if(hb){
    function openMenu(){sb.classList.add('open');ov.classList.add('open');hb.classList.add('open');document.body.style.overflow='hidden'}
    function closeMenu(){sb.classList.remove('open');ov.classList.remove('open');hb.classList.remove('open');document.body.style.overflow=''}
    hb.addEventListener('click',function(){sb.classList.contains('open')?closeMenu():openMenu()});
    ov.addEventListener('click',closeMenu);
    sb.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){if(window.innerWidth<900)closeMenu()})});
  }
  var rt=document.getElementById('rt');
  if(rt){var t=30;setInterval(function(){t--;rt.textContent=t+'s';if(t<=0)location.reload()},1000)}
  ['lf','lfl'].forEach(function(id){var el=document.getElementById(id);if(el)el.scrollTop=el.scrollHeight});
  document.querySelectorAll('.alert').forEach(function(el){
    el.style.cssText+='opacity:0;transform:translateY(-6px);transition:opacity .3s,transform .3s';
    requestAnimationFrame(function(){el.style.opacity='1';el.style.transform='translateY(0)'});
    if(el.classList.contains('ao'))setTimeout(function(){el.style.opacity='0';setTimeout(function(){el.remove()},300)},4000);
  });
  document.querySelectorAll('.sc-num').forEach(function(el){
    var raw=el.textContent.replace(/[^0-9.]/g,''),target=parseFloat(raw);
    if(isNaN(target)||target===0)return;
    var isF=raw.indexOf('.')!==-1,steps=25,step=target/steps,cur=0,i=0;
    var iv=setInterval(function(){
      i++;cur=Math.min(cur+step,target);
      el.textContent=isF?'$'+cur.toFixed(2):Math.round(cur).toLocaleString();
      if(i>=steps){clearInterval(iv);el.textContent=isF?'$'+target.toFixed(2):target.toLocaleString()}
    },28);
  });
  document.querySelectorAll('.mono,.codeblock').forEach(function(el){
    el.style.cursor='pointer';el.title='Click to copy';
    el.addEventListener('click',function(){
      if(!navigator.clipboard)return;
      navigator.clipboard.writeText(el.textContent.trim()).then(function(){
        var o=el.textContent;el.textContent='✓ copied';
        setTimeout(function(){el.textContent=o},1200);
      });
    });
  });
})();
</script>
<?php endif ?>
</body>
</html>