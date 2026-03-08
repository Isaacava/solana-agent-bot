<?php
namespace SolanaAgent\Features;

/**
 * SOL price checker using CoinGecko free API
 * No API key required for basic endpoints
 */
class Price
{
    private const COINGECKO = 'https://api.coingecko.com/api/v3';
    private const CACHE_TTL = 60; // seconds

    private static array $cache = [];

    /**
     * Get current SOL price in USD (and NGN if possible)
     */
    public static function getSolPrice(): array
    {
        $cacheKey = 'sol_price';
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['ts']) < self::CACHE_TTL) {
            return self::$cache[$cacheKey]['data'];
        }

        $url  = self::COINGECKO . '/simple/price?ids=solana&vs_currencies=usd,ngn&include_24hr_change=true&include_market_cap=true';
        $data = self::fetch($url);

        if (!$data || !isset($data['solana'])) {
            throw new \RuntimeException('Unable to fetch SOL price. Try again shortly.');
        }

        $sol = $data['solana'];
        $result = [
            'usd'        => $sol['usd'] ?? 0,
            'ngn'        => $sol['ngn'] ?? 0,
            'change_24h' => round($sol['usd_24h_change'] ?? 0, 2),
            'market_cap' => $sol['usd_market_cap'] ?? 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        self::$cache[$cacheKey] = ['data' => $result, 'ts' => time()];
        return $result;
    }

    /**
     * Get historical price chart data (7 days)
     */
    public static function getPriceChart(int $days = 7): array
    {
        $url  = self::COINGECKO . "/coins/solana/market_chart?vs_currency=usd&days={$days}&interval=daily";
        $data = self::fetch($url);
        return $data['prices'] ?? [];
    }

    /**
     * Format price data as a readable message
     */
    public static function formatPriceMessage(array $price): string
    {
        $trend  = $price['change_24h'] >= 0 ? '📈' : '📉';
        $change = ($price['change_24h'] >= 0 ? '+' : '') . $price['change_24h'] . '%';

        $msg  = "💰 <b>SOL Price</b>\n\n";
        $msg .= "🇺🇸 USD: <b>$" . number_format($price['usd'], 2) . "</b>\n";

        if ($price['ngn'] > 0) {
            $msg .= "🇳🇬 NGN: <b>₦" . number_format($price['ngn'], 0) . "</b>\n";
        }

        $msg .= "{$trend} 24h Change: <b>{$change}</b>\n";

        if ($price['market_cap'] > 0) {
            $msg .= "📊 Market Cap: <b>$" . self::formatLargeNumber($price['market_cap']) . "</b>\n";
        }

        $msg .= "\n<i>Updated: " . $price['updated_at'] . "</i>";
        return $msg;
    }

    private static function formatLargeNumber(float $n): string
    {
        if ($n >= 1_000_000_000) return round($n / 1_000_000_000, 2) . 'B';
        if ($n >= 1_000_000)     return round($n / 1_000_000, 2) . 'M';
        if ($n >= 1_000)         return round($n / 1_000, 2) . 'K';
        return (string)$n;
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
        curl_close($ch);
        return $response ? json_decode($response, true) : null;
    }
}
