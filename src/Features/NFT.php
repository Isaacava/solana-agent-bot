<?php
namespace SolanaAgent\Features;

/**
 * NFT price and collection info
 * Uses Magic Eden public API (no key required for basic endpoints)
 */
class NFT
{
    private const ME_API = 'https://api-mainnet.magiceden.dev/v2';
    private const ME_V3  = 'https://api-mainnet.magiceden.dev/v3';

    /**
     * Get collection stats by symbol/slug
     */
    public static function getCollectionStats(string $symbol): ?array
    {
        $symbol = strtolower(trim($symbol));
        $url    = self::ME_API . '/collections/' . urlencode($symbol) . '/stats';
        $data   = self::fetch($url);

        if (!$data || isset($data['msg'])) {
            return null;
        }

        return $data;
    }

    /**
     * Search for a collection by name
     */
    public static function searchCollection(string $query): array
    {
        $url  = self::ME_API . '/collections?q=' . urlencode($query) . '&limit=5';
        $data = self::fetch($url);
        return $data ?? [];
    }

    /**
     * Get recent listings for a collection
     */
    public static function getListings(string $symbol, int $limit = 5): array
    {
        $url  = self::ME_API . '/collections/' . urlencode($symbol) . '/listings?limit=' . $limit;
        $data = self::fetch($url);
        return $data ?? [];
    }

    /**
     * Format collection stats as a message
     */
    public static function formatStats(array $stats, string $name = ''): string
    {
        $floorSol = isset($stats['floorPrice'])
            ? round($stats['floorPrice'] / 1_000_000_000, 4)
            : 'N/A';

        $vol24h = isset($stats['volumeAll'])
            ? round($stats['volumeAll'] / 1_000_000_000, 2)
            : 'N/A';

        $listed  = $stats['listedCount'] ?? 'N/A';
        $holders = $stats['holderCount'] ?? 'N/A';

        $msg  = "🖼️ <b>NFT Collection Stats</b>";
        if ($name) $msg .= " — {$name}";
        $msg .= "\n\n";
        $msg .= "💎 Floor Price: <b>{$floorSol} SOL</b>\n";
        $msg .= "📦 Listed: <b>{$listed}</b>\n";
        $msg .= "👥 Holders: <b>{$holders}</b>\n";
        $msg .= "📊 Total Volume: <b>{$vol24h} SOL</b>\n";

        return $msg;
    }

    /**
     * Handle user query — try as symbol first, then search
     */
    public static function lookup(string $query): string
    {
        // Try direct symbol lookup
        $stats = self::getCollectionStats($query);
        if ($stats) {
            return self::formatStats($stats, $query);
        }

        // Try search
        $results = self::searchCollection($query);
        if (empty($results)) {
            return "❌ Could not find NFT collection: <b>" . htmlspecialchars($query) . "</b>\n"
                . "Try using the collection symbol (e.g. <code>degods</code>, <code>okay_bears</code>)";
        }

        $first  = $results[0];
        $symbol = $first['symbol'] ?? $query;
        $stats  = self::getCollectionStats($symbol);

        if ($stats) {
            return self::formatStats($stats, $first['name'] ?? $symbol);
        }

        // List search results
        $msg = "🔍 Found these collections for \"" . htmlspecialchars($query) . "\":\n\n";
        foreach (array_slice($results, 0, 5) as $r) {
            $msg .= "• <b>" . htmlspecialchars($r['name'] ?? '') . "</b> — symbol: <code>" . ($r['symbol'] ?? '') . "</code>\n";
        }
        $msg .= "\nUse: <code>/nft [symbol]</code> to get stats.";
        return $msg;
    }

    private static function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: SolanaAgentBot/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$response || $code !== 200) return null;
        return json_decode($response, true);
    }
}
