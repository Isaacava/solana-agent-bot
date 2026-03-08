<?php
namespace SolanaAgent\AI;

/**
 * Google Gemini API provider
 * Uses model from config — update 'model' in config.php to change versions.
 * Current free model: gemini-2.0-flash-lite
 */
class Gemini
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function complete(string $systemPrompt, string $userMessage, array $history = []): string
    {
        $contents = [];

        // Add chat history (oldest first)
        foreach (array_reverse($history) as $h) {
            $role       = $h['role'] === 'assistant' ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => $h['content']]]];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        $payload = json_encode([
            'systemInstruction' => $systemPrompt
                ? ['parts' => [['text' => $systemPrompt]]]
                : null,
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => (int)($this->config['max_tokens'] ?? 1024),
                'temperature'     => 0.7,
            ],
        ]);

        // Build endpoint: base + model + action + key
        $model    = $this->config['model'] ?? 'gemini-2.0-flash-lite';
        $base     = rtrim($this->config['endpoint'] ?? 'https://generativelanguage.googleapis.com/v1beta/models/', '/');
        $url      = $base . '/' . $model . ':generateContent?key=' . $this->config['api_key'];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Gemini API: cURL request failed');
        }

        if ($httpCode !== 200) {
            $err = '';
            if ($response) {
                $d   = json_decode($response, true);
                $err = $d['error']['message'] ?? $response;
            }
            throw new \RuntimeException("Gemini API error (HTTP {$httpCode}): {$err}");
        }

        $data = json_decode($response, true);
        return trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}
