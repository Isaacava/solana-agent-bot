<?php
namespace SolanaAgent\Features;

use SolanaAgent\Solana\RPC;

/**
 * Solana Name Service (SNS) — .sol domain lookup
 * Uses Bonfida's SNS proxy API (free, no key required)
 */
class Domain
{
    private const SNS_PROXY = 'https://sns-sdk-proxy.bonfida.workers.dev';

    /**
     * Resolve a .sol domain to a wallet address
     */
    public static function resolve(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.sol');   // normalize

        $url  = self::SNS_PROXY . '/resolve/' . urlencode($domain);
        $data = self::fetch($url);

        if (!$data || isset($data['error'])) return null;
        return $data['result'] ?? null;
    }

    /**
     * Get all domains owned by an address
     */
    public static function domainsForAddress(string $address): array
    {
        $url  = self::SNS_PROXY . '/domains/' . urlencode($address);
        $data = self::fetch($url);
        return $data['result'] ?? [];
    }

    /**
     * Check if a domain is available (not registered)
     */
    public static function isAvailable(string $domain): bool
    {
        return self::resolve($domain) === null;
    }

    /**
     * Format domain lookup result as message
     */
    public static function formatLookupMessage(string $domain, ?string $address): string
    {
        $displayDomain = rtrim($domain, '.sol') . '.sol';

        if ($address) {
            return "🌐 <b>SNS Domain Lookup</b>\n\n"
                . "Domain: <code>{$displayDomain}</code>\n"
                . "Owner: <code>{$address}</code>\n"
                . "Status: ✅ <b>Registered</b>\n\n"
                . "🔗 <a href=\"https://naming.bonfida.org/domain/{$domain}\">View on Bonfida</a>";
        }

        return "🌐 <b>SNS Domain Lookup</b>\n\n"
            . "Domain: <code>{$displayDomain}</code>\n"
            . "Status: 🆓 <b>Available!</b>\n\n"
            . "🔗 <a href=\"https://naming.bonfida.org/domain/{$domain}\">Register on Bonfida</a>";
    }

    /**
     * Format reverse lookup (address → domains) message
     */
    public static function formatReverseMessage(string $address, array $domains): string
    {
        if (empty($domains)) {
            return "🌐 <b>No .sol domains</b> found for:\n<code>{$address}</code>";
        }

        $msg = "🌐 <b>.sol Domains for address</b>\n\n";
        foreach ($domains as $d) {
            $name  = is_array($d) ? ($d['domain'] ?? $d['name'] ?? (string)$d) : (string)$d;
            $msg  .= "• <code>{$name}.sol</code>\n";
        }
        return $msg;
    }

    private static function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: SolanaAgentBot/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response) return null;
        $data = json_decode($response, true);
        return $data;
    }
}
