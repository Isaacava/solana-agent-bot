<?php
/**
 * DeFi Setup — Liquidity Wallet Initializer
 * Run ONCE after uploading the DeFi files.
 * Access: https://yourdomain.com/setup-defi.php
 * DELETE after setup!
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/src/autoload.php';

use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\{Crypto, Logger};
use SolanaAgent\Solana\{RPC, Keypair};
use SolanaAgent\Features\{Swap, SPLToken, Price};

$config = require __DIR__ . '/config/config.php';

// Simple auth — reuse admin credentials
$authed = !empty($_SESSION['defi_setup_authed']);
$error  = '';
$msg    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === $config['security']['admin_username']
        && Crypto::verifyPassword($p, $config['security']['admin_password'])) {
        $_SESSION['defi_setup_authed'] = true;
        $authed = true;
    } else {
        $error = 'Invalid credentials.';
    }
}

// ─── Actions ──────────────────────────────────────────────────────────────────

$liqStatus  = null;
$solPrice   = null;
$network    = $config['solana']['network'] ?? 'devnet';
$isDevnet   = $network === 'devnet';

if ($authed) {
    try {
        $db      = Database::getInstance($config['database']['file']);
        $crypto  = new Crypto($config['security']['encryption_key']);
        $rpc     = new RPC($isDevnet
            ? $config['solana']['rpc_devnet']
            : $config['solana']['rpc_mainnet']);

        // Check if liquidity wallet already exists
        $liqSkRow   = $db->fetch("SELECT value FROM settings WHERE key_name='swap_liquidity_sk'");
        $liqAddrRow = $db->fetch("SELECT value FROM settings WHERE key_name='swap_liquidity_addr'");

        if ($liqAddrRow && !empty($liqAddrRow['value'])) {
            $liqAddr = $liqAddrRow['value'];

            // Fetch SOL balance
            $solBal   = 0;
            $solError = null;
            try {
                $lamports = $rpc->getBalance($liqAddr);
                $solBal   = round($lamports / 1_000_000_000, 6);
            } catch (\Throwable $e) {
                $solError = $e->getMessage();
            }

            // Fetch USDC-Dev balance — separate try so SOL error doesn't hide USDC error
            $usdcBal   = 0.0;
            $usdcError = null;
            $usdcRaw   = null;
            try {
                // Direct RPC call to see raw response
                $usdcMint  = 'Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr';
                $usdcRaw   = $rpc->getTokenAccountsByMint($liqAddr, $usdcMint);
                if (!empty($usdcRaw)) {
                    foreach ($usdcRaw as $acc) {
                        $ui = $acc['account']['data']['parsed']['info']['tokenAmount']['uiAmount'] ?? null;
                        if ($ui !== null) $usdcBal += (float)$ui;
                    }
                }
            } catch (\Throwable $e) {
                $usdcError = $e->getMessage();
            }

            $liqStatus = [
                'exists'     => true,
                'address'    => $liqAddr,
                'sol'        => $solBal,
                'usdc'       => $usdcBal,
                'sol_error'  => $solError,
                'usdc_error' => $usdcError,
                'usdc_raw'   => $usdcRaw,
            ];
        } else {
            $liqStatus = ['exists' => false];
        }

        try { $price = Price::getSolPrice(); $solPrice = $price['usd'] ?? null; } catch (\Throwable $ignored) {}

    } catch (\Throwable $e) {
        $error = 'DB error: ' . $e->getMessage();
    }

    // POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // ── Create liquidity wallet ──────────────────────────────────────────
        if ($action === 'create_liq_wallet' && $isDevnet) {
            try {
                $keypair  = Keypair::generate();
                $address  = $keypair->getPublicKey();
                $encSk    = $crypto->encrypt($keypair->getSecretKeyBytes());

                $db->query("INSERT OR REPLACE INTO settings (key_name,value,updated_at) VALUES (?,?,?)",
                    ['swap_liquidity_sk', $encSk, date('Y-m-d H:i:s')]);
                $db->query("INSERT OR REPLACE INTO settings (key_name,value,updated_at) VALUES (?,?,?)",
                    ['swap_liquidity_addr', $address, date('Y-m-d H:i:s')]);

                $msg = "✅ Liquidity wallet created: <code>{$address}</code><br>Now fund it with SOL and USDC using the buttons below.";

                // Refresh status
                $liqStatus = ['exists' => true, 'address' => $address, 'sol' => 0, 'usdc' => 0];

            } catch (\Throwable $e) {
                $error = 'Failed to create wallet: ' . $e->getMessage();
            }
        }

        // ── Airdrop SOL to liquidity wallet ─────────────────────────────────
        if ($action === 'airdrop_liq' && $isDevnet && $liqStatus['exists']) {
            try {
                $sig1 = $rpc->requestAirdrop($liqStatus['address'], 2.0);
                sleep(3);
                $sig2 = $rpc->requestAirdrop($liqStatus['address'], 2.0);
                $msg  = "✅ Airdrop requested (2 × 2 SOL = 4 SOL).<br>Sig1: <code>{$sig1}</code><br>Sig2: <code>{$sig2}</code><br>Wait ~10 seconds then refresh.";
            } catch (\Throwable $e) {
                $error = 'Airdrop failed: ' . $e->getMessage();
            }
        }

        // ── Request USDC-Dev faucet for liquidity wallet ─────────────────────
        if ($action === 'faucet_liq' && $isDevnet && $liqStatus['exists']) {
            try {
                $spl    = new SPLToken($rpc, 'devnet');
                $result = $spl->requestFaucet($liqStatus['address'], 1000.0);
                if ($result['success']) {
                    $sig = $result['signature'];
                    $msg = "✅ 1000 USDC-Dev sent to liquidity wallet!<br>Mint: <code>Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr</code><br>Sig: <code>{$sig}</code><br>Refresh to see updated balance.";
                } else {
                    $error = 'USDC-Dev faucet failed: ' . ($result['error'] ?? 'Unknown');
                }
            } catch (\Throwable $e) {
                $error = 'Faucet error: ' . $e->getMessage();
            }
        }

        // ── Rotate / replace liquidity wallet ───────────────────────────────
        if ($action === 'rotate_liq_wallet') {
            try {
                $keypair  = Keypair::generate();
                $address  = $keypair->getPublicKey();
                $encSk    = $crypto->encrypt($keypair->getSecretKeyBytes());
                $db->query("INSERT OR REPLACE INTO settings (key_name,value,updated_at) VALUES (?,?,?)",
                    ['swap_liquidity_sk', $encSk, date('Y-m-d H:i:s')]);
                $db->query("INSERT OR REPLACE INTO settings (key_name,value,updated_at) VALUES (?,?,?)",
                    ['swap_liquidity_addr', $address, date('Y-m-d H:i:s')]);
                $msg = "⚠️ New liquidity wallet created: <code>{$address}</code><br>OLD wallet funds are NOT moved automatically. Fund the new one.";
                $liqStatus = ['exists' => true, 'address' => $address, 'sol' => 0, 'usdc' => 0];
            } catch (\Throwable $e) {
                $error = 'Rotation failed: ' . $e->getMessage();
            }
        }

        // Refresh balances after actions — separated so each shows its own error
        if ($liqStatus['exists']) {
            try {
                $lamports         = $rpc->getBalance($liqStatus['address']);
                $liqStatus['sol'] = round($lamports / 1_000_000_000, 6);
                $liqStatus['sol_error'] = null;
            } catch (\Throwable $e) {
                $liqStatus['sol_error'] = $e->getMessage();
            }
            try {
                $usdcMint = 'Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr';
                $usdcRaw  = $rpc->getTokenAccountsByMint($liqStatus['address'], $usdcMint);
                $usdcBal  = 0.0;
                foreach ($usdcRaw as $acc) {
                    $ui = $acc['account']['data']['parsed']['info']['tokenAmount']['uiAmount'] ?? null;
                    if ($ui !== null) $usdcBal += (float)$ui;
                }
                $liqStatus['usdc']       = $usdcBal;
                $liqStatus['usdc_raw']   = $usdcRaw;
                $liqStatus['usdc_error'] = null;
            } catch (\Throwable $e) {
                $liqStatus['usdc_error'] = $e->getMessage();
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DeFi Setup — SolanaAgent</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--bg:#030C18;--surface:#0B1D32;--surface2:#0F2540;--border:rgba(0,255,163,.12);
  --accent:#00FFA3;--purple:#9945FF;--red:#FF4757;--yellow:#FFB800;
  --tx:#EAF4FB;--tx2:#7FA3BF;--tx3:#3A5872;
  --mono:'Space Mono',monospace;--sans:'DM Sans',system-ui,sans-serif;}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--tx);font-family:var(--sans);min-height:100vh;padding:30px 20px}
.wrap{max-width:720px;margin:0 auto}
h1{font-family:var(--mono);font-size:20px;color:var(--accent);margin-bottom:4px}
.sub{color:var(--tx3);font-size:13px;margin-bottom:28px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:22px;margin-bottom:18px;position:relative;overflow:hidden}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--purple))}
.card h2{font-family:var(--mono);font-size:12px;text-transform:uppercase;letter-spacing:1px;color:var(--accent);margin-bottom:14px}
.field{margin-bottom:14px}
.field label{display:block;font-size:11px;font-weight:600;color:var(--tx2);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
.field input{width:100%;padding:10px 14px;background:var(--bg);border:1px solid var(--border);border-radius:8px;color:var(--tx);font-family:var(--sans);font-size:14px;outline:none}
.field input:focus{border-color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:var(--sans);transition:all .18s;text-decoration:none}
.btn-p{background:var(--accent);color:#030C18}.btn-p:hover{background:#14F195}
.btn-g{background:var(--surface2);color:var(--tx2);border:1px solid var(--border)}.btn-g:hover{border-color:rgba(0,255,163,.3);color:var(--tx)}
.btn-y{background:rgba(255,184,0,.12);color:var(--yellow);border:1px solid rgba(255,184,0,.25)}
.btn-r{background:rgba(255,71,87,.1);color:#FF4757;border:1px solid rgba(255,71,87,.25)}
.btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.alert{padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px}
.ao{background:rgba(0,255,163,.07);border:1px solid rgba(0,255,163,.22);color:var(--accent)}
.ae{background:rgba(255,71,87,.07);border:1px solid rgba(255,71,87,.22);color:#FF4757}
.aw{background:rgba(255,184,0,.07);border:1px solid rgba(255,184,0,.22);color:var(--yellow)}
.stat{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:10px}
.stat-lbl{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--tx3);margin-bottom:4px;font-family:var(--mono)}
.stat-val{font-family:var(--mono);font-size:18px;font-weight:700}
.stat-val.green{color:var(--accent)}.stat-val.yellow{color:var(--yellow)}.stat-val.red{color:var(--red)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.mono{font-family:var(--mono);font-size:12px;background:var(--bg);padding:3px 8px;border-radius:4px;border:1px solid var(--border);word-break:break-all}
.step{display:flex;gap:10px;margin-bottom:14px;align-items:flex-start}
.step-n{width:24px;height:24px;border-radius:50%;background:var(--accent);color:var(--bg);font-family:var(--mono);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.step-body{flex:1}
.step-title{font-weight:600;font-size:14px;margin-bottom:3px}
.step-desc{font-size:12px;color:var(--tx3)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:4px;font-family:var(--mono);font-size:10px;font-weight:700;text-transform:uppercase}
.bg{background:rgba(0,255,163,.12);color:var(--accent)}.br{background:rgba(255,71,87,.12);color:#FF4757}
.by{background:rgba(255,184,0,.12);color:var(--yellow)}
hr{border:none;border-top:1px solid var(--border);margin:18px 0}
</style>
</head>
<body>
<div class="wrap">

<h1>◈ SolanaAgent — DeFi Setup</h1>
<p class="sub">Configure the bot liquidity wallet for devnet swaps. Run once, then delete this file.</p>

<?php if (!$authed): ?>
<div class="card">
  <h2>Authentication</h2>
  <form method="POST">
    <input type="hidden" name="auth" value="1">
    <div class="field"><label>Admin Username</label>
      <input type="text" name="username" placeholder="admin" required></div>
    <div class="field"><label>Admin Password</label>
      <input type="password" name="password" required></div>
    <?php if ($error): ?><div class="alert ae" style="margin-top:10px">⚠ <?= htmlspecialchars($error) ?></div><?php endif ?>
    <div class="btns"><button class="btn btn-p" type="submit">Sign In →</button></div>
  </form>
</div>

<?php else: ?>

<?php if ($msg): ?><div class="alert ao">✓ <?= $msg ?></div><?php endif ?>
<?php if ($error): ?><div class="alert ae">⚠ <?= htmlspecialchars($error) ?></div><?php endif ?>

<?php if (!$isDevnet): ?>
<div class="alert aw">
  ⚠️ You are on <strong>MAINNET</strong>. The liquidity wallet approach is for devnet only.<br>
  On mainnet, swaps route through Jupiter — no liquidity wallet needed. This setup page is devnet-only.
</div>
<?php endif ?>

<!-- Status Card -->
<div class="card">
  <h2>Network & Price</h2>
  <div class="grid2">
    <div class="stat">
      <div class="stat-lbl">Network</div>
      <div class="stat-val <?= $isDevnet ? 'yellow' : 'green' ?>"><?= strtoupper($network) ?></div>
    </div>
    <div class="stat">
      <div class="stat-lbl">SOL Price</div>
      <div class="stat-val green"><?= $solPrice ? '$' . number_format($solPrice, 2) : 'N/A' ?></div>
    </div>
  </div>
</div>

<!-- Liquidity Wallet Card -->
<div class="card">
  <h2>Bot Liquidity Wallet</h2>

  <?php if (!$liqStatus['exists']): ?>
  <div class="alert aw">⚠️ No liquidity wallet exists yet. Create one below to enable devnet swaps.</div>
  <form method="POST">
    <input type="hidden" name="action" value="create_liq_wallet">
    <div class="btns"><button class="btn btn-p" type="submit" <?= !$isDevnet ? 'disabled' : '' ?>>
      ⚡ Create Liquidity Wallet
    </button></div>
  </form>

  <?php else: ?>

  <div class="stat">
    <div class="stat-lbl">Wallet Address</div>
    <div class="mono" style="margin-top:4px"><?= htmlspecialchars($liqStatus['address']) ?></div>
  </div>

  <div class="grid2" style="margin-top:12px">
    <div class="stat">
      <div class="stat-lbl">SOL Balance</div>
      <div class="stat-val <?= ($liqStatus['sol'] ?? 0) > 0.5 ? 'green' : 'red' ?>">
        <?= number_format($liqStatus['sol'] ?? 0, 4) ?> SOL
      </div>
      <?php $solHealth = ($liqStatus['sol'] ?? 0) >= 2 ? '✅ Good' : (($liqStatus['sol'] ?? 0) > 0 ? '⚠️ Low' : '❌ Empty'); ?>
      <div style="font-size:11px;color:var(--tx3);margin-top:3px"><?= $solHealth ?></div>
    </div>
    <div class="stat">
      <div class="stat-lbl">USDC-Dev Balance</div>
      <?php if (!empty($liqStatus['usdc_error'])): ?>
        <div class="stat-val red">Error</div>
        <div style="font-size:11px;color:#FF4757;margin-top:3px;word-break:break-all">
          <?= htmlspecialchars($liqStatus['usdc_error']) ?>
        </div>
      <?php else: ?>
        <div class="stat-val <?= ($liqStatus['usdc'] ?? 0) > 10 ? 'green' : 'red' ?>">
          <?= number_format($liqStatus['usdc'] ?? 0, 2) ?> USDC
        </div>
        <?php $usdcHealth = ($liqStatus['usdc'] ?? 0) >= 100 ? '✅ Good' : (($liqStatus['usdc'] ?? 0) > 0 ? '⚠️ Low' : '❌ Empty - fund with faucet'); ?>
        <div style="font-size:11px;color:var(--tx3);margin-top:3px"><?= $usdcHealth ?></div>
      <?php endif ?>
    </div>
  </div>

  <?php if (!empty($liqStatus['error'])): ?>
  <div class="alert ae" style="margin-top:12px">RPC error: <?= htmlspecialchars($liqStatus['error']) ?></div>
  <?php endif ?>

  <?php if ($isDevnet): ?>
  <hr>
  <p style="color:var(--tx2);font-size:13px;margin-bottom:14px">
    Fund the liquidity wallet to enable swaps. Recommended: <strong>≥4 SOL</strong> and <strong>≥500 USDC-Dev</strong>.<br>
    USDC-Dev mint: <code style="font-size:11px">Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr</code>
  </p>
  <div class="btns">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="airdrop_liq">
      <button class="btn btn-g" type="submit">🪂 Airdrop 4 SOL</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="faucet_liq">
      <button class="btn btn-g" type="submit">🪙 Faucet 1000 USDC-Dev</button>
    </form>
    <a href="https://spl-token-faucet.com/?token-name=USDC-Dev" target="_blank" class="btn btn-g">🌐 Manual Faucet</a>
    <a href="https://explorer.solana.com/address/<?= urlencode($liqStatus['address']) ?>?cluster=devnet"
       target="_blank" class="btn btn-g">🔗 Explorer</a>
  </div>
  <hr>
  <form method="POST" onsubmit="return confirm('Rotate wallet? You must manually move funds from the old wallet.')">
    <input type="hidden" name="action" value="rotate_liq_wallet">
    <button class="btn btn-r" type="submit">🔄 Rotate Wallet (advanced)</button>
  </form>
  <?php endif ?>

  <?php endif ?>
</div>

<!-- Debug Panel -->
<?php if ($authed && isset($liqStatus) && $liqStatus['exists']): ?>
<div class="card">
  <h2>🔍 USDC Debug — Raw RPC Response</h2>
  <p style="color:var(--tx3);font-size:12px;margin-bottom:10px">
    Shows exactly what Solana returns for this wallet's USDC-Dev token accounts. If this is empty, the wallet has no ATA for this mint yet.
  </p>
  <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px;font-family:var(--mono);font-size:11px;color:var(--accent);white-space:pre-wrap;word-break:break-all;max-height:200px;overflow-y:auto">
    <?php
    $raw = $liqStatus['usdc_raw'] ?? null;
    if (!empty($liqStatus['usdc_error'])) {
        echo "ERROR: " . htmlspecialchars($liqStatus['usdc_error']);
    } elseif ($raw === null) {
        echo "Not fetched yet.";
    } elseif (empty($raw)) {
        echo "EMPTY — wallet has no token account for mint:\nGh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr\n\nThis means the USDC-Dev faucet has not created an ATA for this wallet yet.\nClick the Faucet button above to request tokens.";
    } else {
        foreach ($raw as $i => $acc) {
            $mint    = $acc['account']['data']['parsed']['info']['mint'] ?? '?';
            $owner   = $acc['account']['data']['parsed']['info']['owner'] ?? '?';
            $amount  = $acc['account']['data']['parsed']['info']['tokenAmount']['uiAmountString'] ?? '?';
            $decimals= $acc['account']['data']['parsed']['info']['tokenAmount']['decimals'] ?? '?';
            echo "Account #{$i}:\n";
            echo "  Mint:     {$mint}\n";
            echo "  Owner:    {$owner}\n";
            echo "  Balance:  {$amount} (decimals: {$decimals})\n\n";
        }
    }
    ?>
  </div>
  <?php if (empty($liqStatus['usdc_error']) && !empty($raw)): ?>
  <div class="alert ao" style="margin-top:10px">
    ✅ Token account found — USDC-Dev balance should display correctly above.
  </div>
  <?php elseif (empty($raw) && empty($liqStatus['usdc_error'])): ?>
  <div class="alert aw" style="margin-top:10px">
    ⚠️ No token account found. The wallet address is correct but it has never received USDC-Dev.
    Click <strong>Faucet 1000 USDC-Dev</strong> above — this creates the ATA and sends tokens in one step.
  </div>
  <?php endif ?>
</div>
<?php endif ?>

<!-- How It Works -->
<div class="card">
  <h2>How Devnet Swaps Work</h2>
  <div class="step">
    <div class="step-n">1</div>
    <div class="step-body">
      <div class="step-title">User says "swap 1 SOL to USDC"</div>
      <div class="step-desc">Bot gets live SOL price from CoinGecko, calculates USDC output (e.g. 1 SOL × $185 = 185 USDC), checks liquidity wallet has enough USDC</div>
    </div>
  </div>
  <div class="step">
    <div class="step-n">2</div>
    <div class="step-body">
      <div class="step-title">User's wallet → Liquidity wallet (SOL)</div>
      <div class="step-desc">Bot builds a real Solana SOL transfer transaction signed by the user's keypair. 1 SOL is sent to the bot's liquidity wallet address on devnet</div>
    </div>
  </div>
  <div class="step">
    <div class="step-n">3</div>
    <div class="step-body">
      <div class="step-title">Liquidity wallet → User's wallet (USDC)</div>
      <div class="step-desc">Bot uses the liquidity wallet keypair (decrypted from DB) to send a real SPL TransferChecked instruction. 185 USDC lands in the user's ATA</div>
    </div>
  </div>
  <div class="step">
    <div class="step-n">4</div>
    <div class="step-body">
      <div class="step-title">Both transactions verifiable on Solana Explorer</div>
      <div class="step-desc">Real on-chain devnet transactions — not simulated. Explorer links provided in Telegram after each swap</div>
    </div>
  </div>
  <hr>
  <p style="color:var(--tx3);font-size:12px">
    <strong style="color:var(--yellow)">Mainnet:</strong> Liquidity wallet is NOT used. Swaps route through Jupiter Aggregator — the bot gets a pre-built swap transaction from Jupiter, re-signs it with the user's key, and submits directly.
  </p>
</div>

<!-- File Upload Guide -->
<div class="card">
  <h2>Files to Upload (DeFi Integration)</h2>
  <p style="color:var(--tx2);font-size:13px;margin-bottom:14px">
    Upload these files to your server in the paths shown. The DeFi infrastructure already exists in the project — these updates wire everything together:
  </p>

  <?php
  $files = [
    ['src/Bot/Handler.php',         'Routes all commands, swaps, DeFi intents, faucet', 'bg'],
    ['src/Features/Scheduler.php',  'Executes conditional swaps when price is triggered', 'bg'],
    ['src/AI/AIManager.php',        'Detects swap/DeFi intents from natural language', 'bg'],
    ['src/Features/Swap.php',       'Liquidity wallet swap logic + Jupiter mainnet', 'by'],
    ['src/Features/SPLToken.php',   'USDC balance, transfer, faucet API', 'by'],
    ['src/Solana/Transaction.php',  'Builds SOL + SPL token transactions', 'by'],
    ['src/Solana/PDA.php',          'Derives Associated Token Account (ATA) addresses', 'by'],
    ['src/Solana/RPC.php',          'Solana JSON-RPC client', 'by'],
    ['src/Solana/WalletManager.php','High-level wallet + send operations', 'by'],
  ];
  foreach ($files as [$path, $desc, $badge]):
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.04)">
    <span class="badge <?= $badge ?>"><?= $badge === 'bg' ? 'UPDATED' : 'EXISTING' ?></span>
    <div style="flex:1">
      <div class="mono" style="font-size:11px;margin-bottom:2px"><?= $path ?></div>
      <div style="font-size:11px;color:var(--tx3)"><?= $desc ?></div>
    </div>
  </div>
  <?php endforeach ?>

  <div class="alert aw" style="margin-top:14px">
    ⚠️ The <strong>EXISTING</strong> files (yellow) are already on your server and are correct — they don't need re-uploading unless something broke. Only upload the <strong>UPDATED</strong> files (green).
  </div>
</div>

<!-- Checklist -->
<div class="card">
  <h2>Setup Checklist</h2>
  <?php
  $checks = [];

  // 1. Encryption key set
  $encKey = $config['security']['encryption_key'] ?? '';
  $checks[] = [strlen($encKey) >= 16, 'Encryption key set (≥16 chars)', 'Required for liquidity wallet storage'];

  // 2. DB accessible
  try { $db2 = Database::getInstance($config['database']['file']); $checks[] = [true, 'Database accessible', '']; }
  catch (\Throwable $e) { $checks[] = [false, 'Database accessible', $e->getMessage()]; }

  // 3. Liq wallet exists
  $checks[] = [$liqStatus['exists'] ?? false, 'Liquidity wallet created', 'Create above if missing'];

  // 4. Has SOL
  $checks[] = [($liqStatus['sol'] ?? 0) >= 1.0, 'Liquidity wallet has SOL (≥1 SOL)', 'Use Airdrop button above'];

  // 5. Has USDC
  $checks[] = [($liqStatus['usdc'] ?? 0) >= 50, 'Liquidity wallet has USDC (≥50)', 'Use Faucet button above'];

  // 6. Groq key
  $checks[] = [!empty($config['ai']['groq']['api_key']), 'Groq API key set', 'For AI intent detection'];

  // 7. Cron
  $lastCron = null;
  try { $lastCron = $db2->fetch("SELECT value FROM settings WHERE key_name='last_cron_run'"); } catch (\Throwable $ignored) {}
  $cronOk = $lastCron && (time() - strtotime($lastCron['value'] ?? '1970-01-01') < 300);
  $checks[] = [$cronOk, 'Cron is running (last run <5 min ago)', 'Set up cron: * * * * * php /path/cron.php'];

  foreach ($checks as [$ok, $label, $hint]):
  ?>
  <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.04)">
    <span style="font-size:18px;flex-shrink:0"><?= $ok ? '✅' : '❌' ?></span>
    <div>
      <div style="font-size:13px;font-weight:500"><?= $label ?></div>
      <?php if ($hint && !$ok): ?><div style="font-size:11px;color:var(--tx3)"><?= htmlspecialchars($hint) ?></div><?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</div>

<div class="alert aw" style="margin-top:4px">
  🔐 <strong>Delete this file after setup:</strong> <code>rm setup-defi.php</code> — or protect it from public access.
</div>

<?php endif ?>

</div>
</body>
</html>