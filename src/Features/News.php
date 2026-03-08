<?php
namespace SolanaAgent\Features;

/**
 * Fetches latest Solana news from RSS feeds
 * No API key required
 */
class News
{
    private const FEEDS = [
        'Cointelegraph' => 'https://cointelegraph.com/rss/tag/solana',
        'CoinDesk'      => 'https://www.coindesk.com/arc/outboundfeeds/rss/?outputType=xml',
        'The Block'     => 'https://www.theblock.co/rss.xml',
    ];

    private const CACHE_TTL = 300; // 5 minutes
    private static ?array $cached = null;
    private static int    $cachedAt = 0;

    /**
     * Get latest Solana news items
     */
    public static function getLatestNews(int $limit = 5): array
    {
        if (self::$cached && (time() - self::$cachedAt) < self::CACHE_TTL) {
            return array_slice(self::$cached, 0, $limit);
        }

        $articles = [];

        foreach (self::FEEDS as $source => $feedUrl) {
            try {
                $items = self::parseFeed($feedUrl, $source);
                $articles = array_merge($articles, $items);
            } catch (\Throwable $ignored) {
                // Skip unavailable feeds
            }
        }

        // Sort by date descending
        usort($articles, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));

        // Filter for Solana-relevant articles
        $articles = array_filter($articles, function ($a) {
            $text = strtolower($a['title'] . ' ' . $a['description']);
            return strpos($text, 'solana') !== false || strpos($text, 'sol ') !== false;
        });

        self::$cached   = array_values($articles);
        self::$cachedAt = time();

        return array_slice(self::$cached, 0, $limit);
    }

    /**
     * Format news as a Telegram message
     */
    public static function formatNewsMessage(array $articles): string
    {
        if (empty($articles)) {
            return "📰 No recent Solana news found. Check back soon!";
        }

        $msg = "📰 <b>Latest Solana News</b>\n\n";

        foreach ($articles as $i => $article) {
            $n    = $i + 1;
            $date = date('M d', strtotime($article['date']));
            $msg .= "{$n}. <b>" . htmlspecialchars(substr($article['title'], 0, 80)) . "</b>\n";
            $msg .= "   <i>{$article['source']} · {$date}</i>\n";
            $msg .= "   🔗 <a href=\"{$article['url']}\">Read more</a>\n\n";
        }

        return $msg;
    }

    // ─── RSS Parsing ──────────────────────────────────────────────────────────

    private static function parseFeed(string $url, string $source): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: SolanaAgentBot/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $xml = curl_exec($ch);
        curl_close($ch);

        if (!$xml) return [];

        // Suppress XML errors
        libxml_use_internal_errors(true);
        $feed  = simplexml_load_string($xml);
        libxml_clear_errors();

        if (!$feed) return [];

        $items   = [];
        $channel = $feed->channel ?? $feed;

        foreach ($channel->item ?? [] as $item) {
            $items[] = [
                'title'       => (string)$item->title,
                'description' => strip_tags((string)$item->description),
                'url'         => (string)$item->link,
                'date'        => (string)$item->pubDate,
                'source'      => $source,
            ];
            if (count($items) >= 10) break;
        }

        return $items;
    }
}
