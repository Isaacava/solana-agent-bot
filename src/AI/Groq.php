<?php
namespace SolanaAgent\AI;

/**
 * Groq API provider (free tier — llama-3.3-70b-versatile)
 * Very fast inference, great for real-time chat
 */
class Groq
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function complete(string $systemPrompt, string $userMessage, array $history = []): string
    {
        $messages = [];

        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        // Add history (reverse since DB returns newest first)
        foreach (array_reverse($history) as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $payload = json_encode([
            'model'       => $this->config['model'],
            'messages'    => $messages,
            'max_tokens'  => $this->config['max_tokens'] ?? 1024,
            'temperature' => 0.7,
        ]);

        $ch = curl_init($this->config['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \RuntimeException("Groq API error (HTTP {$httpCode}): " . $response);
        }

        $data = json_decode($response, true);
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
