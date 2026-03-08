<?php
namespace SolanaAgent\AI;

/**
 * Cohere API provider (free tier — command-r)
 */
class Cohere
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function complete(string $systemPrompt, string $userMessage, array $history = []): string
    {
        $chatHistory = [];
        foreach (array_reverse($history) as $h) {
            $chatHistory[] = [
                'role'    => $h['role'] === 'assistant' ? 'CHATBOT' : 'USER',
                'message' => $h['content'],
            ];
        }

        $payload = json_encode([
            'model'        => $this->config['model'],
            'message'      => $userMessage,
            'preamble'     => $systemPrompt ?: null,
            'chat_history' => $chatHistory,
            'max_tokens'   => $this->config['max_tokens'] ?? 1024,
            'temperature'  => 0.7,
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
            throw new \RuntimeException("Cohere API error (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        return $data['text'] ?? '';
    }
}
