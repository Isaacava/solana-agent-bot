<?php
namespace SolanaAgent\Bot;

use SolanaAgent\Utils\Logger;

/**
 * Telegram Bot API wrapper
 * Pure PHP cURL — no external libraries
 */
class Telegram
{
    private string $token;
    private string $apiBase;
    private string $parseMode;

    public function __construct(array $config)
    {
        $this->token     = $config['bot_token'];
        $this->apiBase   = "https://api.telegram.org/bot{$this->token}";
        $this->parseMode = $config['parse_mode'] ?? 'HTML';
    }

    // ─── Core API caller ──────────────────────────────────────────────────────

    public function api(string $method, array $params = []): array
    {
        $url = "{$this->apiBase}/{$method}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Telegram API request failed');
        }

        $data = json_decode($response, true);

        if (!$data['ok']) {
            $err = $data['description'] ?? 'Unknown Telegram error';
            Logger::warn("Telegram API error on {$method}: {$err}");
            throw new \RuntimeException("Telegram: {$err}");
        }

        return $data;
    }

    // ─── Sending messages ─────────────────────────────────────────────────────

    public function sendMessage(
        int    $chatId,
        string $text,
        array  $replyMarkup = [],
        bool   $disablePreview = true
    ): array {
        $params = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $this->parseMode,
            'disable_web_page_preview' => $disablePreview,
        ];
        if (!empty($replyMarkup)) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->api('sendMessage', $params);
    }

    public function editMessage(int $chatId, int $messageId, string $text, array $replyMarkup = []): array
    {
        $params = [
            'chat_id'                  => $chatId,
            'message_id'               => $messageId,
            'text'                     => $text,
            'parse_mode'               => $this->parseMode,
            'disable_web_page_preview' => true,
        ];
        if (!empty($replyMarkup)) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->api('editMessageText', $params);
    }

    public function sendTyping(int $chatId): void
    {
        try {
            $this->api('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        } catch (\Throwable $ignored) {}
    }

    public function sendRecordingAction(int $chatId): void
    {
        try {
            $this->api('sendChatAction', ['chat_id' => $chatId, 'action' => 'record_voice']);
        } catch (\Throwable $ignored) {}
    }

    public function answerCallbackQuery(string $callbackId, string $text = ''): void
    {
        try {
            $this->api('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text]);
        } catch (\Throwable $ignored) {}
    }

    // ─── Inline keyboard helpers ──────────────────────────────────────────────

    public static function inlineKeyboard(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    public static function button(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    public static function urlButton(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }

    // ─── Webhook management ───────────────────────────────────────────────────

    public function setWebhook(string $url, string $secret = ''): array
    {
        $params = ['url' => $url];
        if ($secret) $params['secret_token'] = $secret;
        return $this->api('setWebhook', $params);
    }

    public function deleteWebhook(): array
    {
        return $this->api('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->api('getWebhookInfo');
    }

    public function getMe(): array
    {
        return $this->api('getMe');
    }

    // ─── Parse incoming update ────────────────────────────────────────────────

    public static function parseUpdate(string $raw): ?array
    {
        $update = json_decode($raw, true);
        if (!$update) return null;

        if (isset($update['message'])) {
            $msg  = $update['message'];
            $from = $msg['from'] ?? [];

            $base = [
                'type'        => 'message',
                'update_id'   => $update['update_id'],
                'chat_id'     => $msg['chat']['id'],
                'message_id'  => $msg['message_id'],
                'text'        => trim($msg['text'] ?? ''),
                'telegram_id' => (string)($from['id'] ?? ''),
                'username'    => $from['username'] ?? null,
                'first_name'  => $from['first_name'] ?? 'User',
            ];

            // ── Voice note ────────────────────────────────────────────────────
            // Telegram sends voice messages as OGG Opus files
            if (isset($msg['voice'])) {
                $base['type']        = 'voice';
                $base['voice_file_id'] = $msg['voice']['file_id'];
                $base['voice_duration']= $msg['voice']['duration'] ?? 0;
                return $base;
            }

            // ── Audio file (mp3, m4a, etc.) ───────────────────────────────────
            if (isset($msg['audio'])) {
                $base['type']        = 'voice';
                $base['voice_file_id'] = $msg['audio']['file_id'];
                $base['voice_duration']= $msg['audio']['duration'] ?? 0;
                return $base;
            }

            return $base;
        }

        if (isset($update['callback_query'])) {
            $cb   = $update['callback_query'];
            $from = $cb['from'] ?? [];
            return [
                'type'        => 'callback',
                'update_id'   => $update['update_id'],
                'chat_id'     => $cb['message']['chat']['id'],
                'message_id'  => $cb['message']['message_id'],
                'callback_id' => $cb['id'],
                'data'        => $cb['data'] ?? '',
                'telegram_id' => (string)($from['id'] ?? ''),
                'username'    => $from['username'] ?? null,
                'first_name'  => $from['first_name'] ?? 'User',
            ];
        }

        return null;
    }
}
