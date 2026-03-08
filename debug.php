<?php
// diagnose_swap_addresses.php
declare(strict_types=1);
require_once __DIR__ . '/src/autoload.php';

use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\Crypto;
use SolanaAgent\Solana\{Base58, Keypair, PDA, RPC};
use SolanaAgent\Features\SPLToken;

$config = require __DIR__ . '/config/config.php';
$dbFile = $config['database']['file'] ?? __DIR__ . '/data.sqlite';

echo "Diagnosing swap liquidity values...\n\n";

// 1) Open DB
$db = Database::getInstance($dbFile);

// Helper to fetch setting
function getSetting($db, $k) {
    $r = $db->fetch("SELECT value FROM settings WHERE key_name=?", [$k]);
    return $r['value'] ?? null;
}

$liqAddr = getSetting($db, 'swap_liquidity_addr');
$liqSkEnc = getSetting($db, 'swap_liquidity_sk');

echo "swap_liquidity_addr (DB):\n";
var_dump($liqAddr);
echo "Valid base58? " . (Base58::isValidAddress((string)$liqAddr) ? 'YES' : 'NO') . "\n\n";

echo "swap_liquidity_sk (encrypted) present? " . ($liqSkEnc ? 'YES' : 'NO') . "\n\n";

if ($liqSkEnc) {
    // Attempt to decrypt using encryption key from config
    $encKey = $config['security']['encryption_key'] ?? '';
    if (!$encKey) {
        echo "Encryption key not set in config. Cannot decrypt secret to derive address.\n";
    } else {
        $crypto = new Crypto($encKey);
        try {
            $skBytes = $crypto->decrypt($liqSkEnc);
            echo "Decrypted secret key length: " . strlen($skBytes) . " bytes\n";
            // If Keypair::fromSecretKey exists
            $kp = Keypair::fromSecretKey($skBytes);
            $derived = $kp->getPublicKey();
            echo "Derived public key from secret: {$derived}\n";
            echo "Derived looks valid base58? " . (Base58::isValidAddress($derived) ? 'YES' : 'NO') . "\n";

            if ($liqAddr !== $derived) {
                echo "NOTICE: DB address differs from derived public key!\n";
            } else {
                echo "DB address matches derived public key.\n";
            }

            // Also check ATA for devnet USDC mint
            $rpcUrl = $config['solana']['rpc_devnet'] ?? $config['solana']['rpc_mainnet'];
            $rpc = new RPC($rpcUrl);
            $spl = new SPLToken($rpc, 'devnet');
            $mint = $spl->usdcMint();
            echo "\nDevnet USDC mint: {$mint}\n";
            echo "Mint valid base58? " . (Base58::isValidAddress($mint) ? 'YES' : 'NO') . "\n";

            $ata = PDA::findATA($derived, $mint);
            echo "Derived liquidity ATA: {$ata}\n";
            echo "ATA valid base58? " . (Base58::isValidAddress($ata) ? 'YES' : 'NO') . "\n";

            // Show getTokenAccountsByOwner raw response for this mint
            echo "\nRPC getTokenAccountsByOwner (for derived liquidity address + USDC mint):\n";
            $accounts = $rpc->getTokenAccountsByOwner($derived, ['mint' => $mint]);
            echo "Accounts returned: " . count($accounts) . "\n";
            if ($accounts) {
                // print first entry key fields
                $first = $accounts[0];
                echo "First account (summary):\n";
                echo "  pubkey: " . ($first['pubkey'] ?? '(none)') . "\n";
                $parsed = $first['account']['data']['parsed'] ?? null;
                if ($parsed) {
                    echo "  mint: " . ($parsed['info']['mint'] ?? '(none)') . "\n";
                    echo "  uiAmount: " . ($parsed['info']['tokenAmount']['uiAmount'] ?? '(none)') . "\n";
                } else {
                    echo "  account data not parsed\n";
                }
            }
        } catch (\Throwable $e) {
            echo "Failed to decrypt or derive keypair: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDiagnostic complete. If any value above is NOT valid base58, that value is the likely cause of the 'invalid base58' error.\n";