<?php
namespace SolanaAgent\Solana;

use SolanaAgent\Utils\Logger;

/**
 * Solana JSON-RPC 2.0 client
 */
class RPC
{
    private string $endpoint;
    private int    $idCounter = 1;
    private float  $lastRateLimit = 0;

    public function __construct(string $rpcUrl)
    {
        $this->endpoint = $rpcUrl;
    }

    // ─── Core ─────────────────────────────────────────────────────────────────

    private function call(string $method, array $params = [])
    {
        $now = microtime(true);
        if ($now - $this->lastRateLimit < 0.1) usleep(100000);
        $this->lastRateLimit = microtime(true);

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $this->idCounter++,
            'method'  => $method,
            'params'  => $params,
        ]);

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('RPC curl failed: ' . $err);
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException('RPC bad JSON response');
        }
        if (isset($data['error'])) {
            throw new \RuntimeException('RPC error [' . $method . ']: ' . ($data['error']['message'] ?? json_encode($data['error'])));
        }

        return $data['result'] ?? null;
    }

    // ─── Balance ──────────────────────────────────────────────────────────────

    public function getBalance(string $publicKey): int
    {
        $result = $this->call('getBalance', [$publicKey, ['commitment' => 'confirmed']]);
        return (int)($result['value'] ?? 0);
    }

    public function getBalanceSol(string $publicKey): float
    {
        return round($this->getBalance($publicKey) / 1_000_000_000, 9);
    }

    // ─── Token accounts ───────────────────────────────────────────────────────

    /**
     * All token accounts for a wallet (any mint, any program)
     */
    public function getTokenAccounts(string $publicKey): array
    {
        $result = $this->call('getTokenAccountsByOwner', [
            $publicKey,
            ['programId' => 'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA'],
            ['encoding'  => 'jsonParsed'],
        ]);
        return $result['value'] ?? [];
    }

    /**
     * Token accounts filtered by a specific mint — the reliable way to check a token balance.
     * Returns [] when the user has no account for that mint (i.e. 0 balance).
     */
    public function getTokenAccountsByMint(string $ownerPublicKey, string $mintAddress): array
    {
        $result = $this->call('getTokenAccountsByOwner', [
            $ownerPublicKey,
            ['mint'      => $mintAddress],
            ['encoding'  => 'jsonParsed'],
        ]);
        return $result['value'] ?? [];
    }

    // ─── Blockhash ────────────────────────────────────────────────────────────

    public function getLatestBlockhash(): array
    {
        $result = $this->call('getLatestBlockhash', [['commitment' => 'confirmed']]);
        return [
            'blockhash'            => $result['value']['blockhash'],
            'lastValidBlockHeight' => $result['value']['lastValidBlockHeight'],
        ];
    }

    // ─── Transactions ─────────────────────────────────────────────────────────

    public function sendTransaction(string $serializedTx): string
    {
        $result = $this->call('sendTransaction', [
            base64_encode($serializedTx),
            ['encoding' => 'base64', 'preflightCommitment' => 'confirmed'],
        ]);
        return (string)$result;
    }

    public function sendRawBase64Transaction(string $base64Tx): string
    {
        $result = $this->call('sendTransaction', [
            $base64Tx,
            ['encoding' => 'base64', 'preflightCommitment' => 'confirmed', 'skipPreflight' => false],
        ]);
        return (string)$result;
    }

    public function confirmTransaction(string $signature, int $maxWaitSeconds = 30): bool
    {
        $start = time();
        while (time() - $start < $maxWaitSeconds) {
            $result = $this->call('getSignatureStatuses', [[$signature], ['searchTransactionHistory' => true]]);
            $status = $result['value'][0] ?? null;
            if ($status && in_array($status['confirmationStatus'] ?? '', ['confirmed', 'finalized'])) {
                return ($status['err'] === null);
            }
            sleep(2);
        }
        return false;
    }

    public function getTransaction(string $signature): ?array
    {
        return $this->call('getTransaction', [
            $signature,
            ['encoding' => 'jsonParsed', 'commitment' => 'confirmed', 'maxSupportedTransactionVersion' => 0],
        ]);
    }

    public function getSignaturesForAddress(string $address, int $limit = 10): array
    {
        $result = $this->call('getSignaturesForAddress', [$address, ['limit' => $limit]]);
        return $result ?? [];
    }

    // ─── Account info ─────────────────────────────────────────────────────────

    public function getAccountInfo(string $address): ?array
    {
        $result = $this->call('getAccountInfo', [$address, ['encoding' => 'jsonParsed']]);
        return $result['value'] ?? null;
    }

    public function getMinimumBalanceForRentExemption(int $dataSize): int
    {
        $result = $this->call('getMinimumBalanceForRentExemption', [$dataSize]);
        return (int)($result ?? 1_461_600);
    }

    // ─── Airdrop (devnet) ─────────────────────────────────────────────────────

    public function requestAirdrop(string $publicKey, float $sol = 1.0): string
    {
        $lamports = (int)round($sol * 1_000_000_000);
        return (string)$this->call('requestAirdrop', [$publicKey, $lamports]);
    }

    // ─── Cluster ──────────────────────────────────────────────────────────────

    public function getVersion(): array  { return $this->call('getVersion') ?? []; }
    public function getSlot(): int       { return (int)$this->call('getSlot'); }
    public function getHealth(): string
    {
        try { $this->call('getHealth'); return 'ok'; }
        catch (\Throwable $ignored) { return 'error'; }
    }
}