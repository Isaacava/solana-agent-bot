<?php
namespace SolanaAgent\AI;

use SolanaAgent\Utils\Logger;

/**
 * VoiceHandler
 * Downloads Telegram voice/audio messages and transcribes them
 * using Groq's free Whisper endpoint (whisper-large-v3-turbo).
 *
 * Supports Nigerian languages: English, Yoruba, Igbo, Hausa, Pidgin
 */
class VoiceHandler
{
    private string $botToken;
    private string $groqApiKey;

    // Groq Whisper endpoint
    private const WHISPER_URL = 'https://api.groq.com/openai/v1/audio/transcriptions';
    private const MODEL        = 'whisper-large-v3-turbo';

    // Max voice file size Telegram allows (20MB, but Groq limits to 25MB — safe)
    private const MAX_BYTES = 20_000_000;

    public function __construct(string $botToken, string $groqApiKey)
    {
        $this->botToken   = $botToken;
        $this->groqApiKey = $groqApiKey;
    }

    // ─── Main entry point ─────────────────────────────────────────────────────

    /**
     * Given a Telegram file_id, download the audio and return transcribed text.
     *
     * @throws \RuntimeException if download or transcription fails
     */
    public function transcribe(string $fileId, string $languageHint = ''): string
    {
        if (empty($this->groqApiKey)) {
            throw new \RuntimeException(
                "Voice transcription requires a Groq API key.\n" .
                "Add it to config.php under ai → groq → api_key.\n" .
                "Get one free at https://console.groq.com"
            );
        }

        // Step 1: Resolve file_id → download URL
        $filePath = $this->getFilePath($fileId);
        $fileUrl  = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";

        // Step 2: Download into memory (no disk writes needed for small files)
        $audioData = $this->downloadFile($fileUrl);

        // Step 3: Transcribe with Groq Whisper
        $text = $this->transcribeAudio($audioData, $filePath, $languageHint);

        Logger::info('Voice transcribed', [
            'chars'    => strlen($text),
            'lang_hint'=> $languageHint,
        ]);

        return $text;
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Calls Telegram getFile API to get the download path for a file_id.
     */
    private function getFilePath(string $fileId): string
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getFile?file_id=" . urlencode($fileId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);

        if (!$res) {
            throw new \RuntimeException('Could not resolve voice file from Telegram.');
        }

        $data = json_decode($res, true);
        $path = $data['result']['file_path'] ?? null;

        if (!$path) {
            throw new \RuntimeException('Telegram returned no file path for voice message.');
        }

        return $path;
    }

    /**
     * Downloads a file from Telegram CDN into memory.
     */
    private function downloadFile(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data === false || $code !== 200) {
            throw new \RuntimeException("Could not download voice file (HTTP {$code}).");
        }

        if (strlen($data) > self::MAX_BYTES) {
            throw new \RuntimeException('Voice message is too large to process.');
        }

        return $data;
    }

    /**
     * Sends audio data to Groq Whisper API for transcription.
     * Returns the transcribed text string.
     */
    private function transcribeAudio(string $audioData, string $filePath, string $languageHint): string
    {
        // Determine MIME type from file extension
        $ext  = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $this->mimeForExtension($ext);

        // Build multipart POST
        $boundary = '----GBoundary' . bin2hex(random_bytes(8));
        $body     = $this->buildMultipart($boundary, $audioData, $ext, $mime, $languageHint);

        $ch = curl_init(self::WHISPER_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->groqApiKey,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Groq Whisper: cURL request failed.');
        }

        if ($httpCode !== 200) {
            $d   = json_decode($response, true);
            $msg = $d['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Groq Whisper error: {$msg}");
        }

        $data = json_decode($response, true);
        $text = trim($data['text'] ?? '');

        if ($text === '') {
            throw new \RuntimeException('Voice message transcribed as empty — please speak clearly and try again.');
        }

        return $text;
    }

    /**
     * Builds multipart/form-data for Whisper API.
     *
     * NIGERIAN LANGUAGE STRATEGY:
     * - Yoruba/Igbo/Hausa have NO proper Whisper ISO support — passing those codes
     *   makes transcription WORSE, not better.
     * - For indigenous languages: omit `language` param → Whisper auto-detects.
     * - For Pidgin/English: set language=en (Nigerian accented English).
     * - Use `prompt` field in ALL cases to inject Nigerian + crypto vocabulary
     *   so the beam-search decoder strongly prefers Nigerian names and Solana terms.
     */
    private function buildMultipart(
        string $boundary,
        string $audioData,
        string $ext,
        string $mime,
        string $languageHint
    ): string {
        $CRLF = "\r\n";
        $body = '';

        // .oga (Telegram voice) → rename to .ogg (Groq accepted extension)
        $safeExt  = ($ext === 'oga') ? 'ogg' : $ext;
        $filename = 'voice.' . $safeExt;

        // — file —
        $body .= "--{$boundary}{$CRLF}";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"{$CRLF}";
        $body .= "Content-Type: {$mime}{$CRLF}{$CRLF}";
        $body .= $audioData . $CRLF;

        // — model —
        $body .= "--{$boundary}{$CRLF}";
        $body .= "Content-Disposition: form-data; name=\"model\"{$CRLF}{$CRLF}";
        $body .= self::MODEL . $CRLF;

        // — response_format —
        $body .= "--{$boundary}{$CRLF}";
        $body .= "Content-Disposition: form-data; name=\"response_format\"{$CRLF}{$CRLF}";
        $body .= "json{$CRLF}";

        // — language: only set for English/Pidgin speakers —
        $indigenousLangs = ['yoruba', 'igbo', 'hausa'];
        if (!in_array(strtolower($languageHint), $indigenousLangs, true)) {
            $body .= "--{$boundary}{$CRLF}";
            $body .= "Content-Disposition: form-data; name=\"language\"{$CRLF}{$CRLF}";
            $body .= "en{$CRLF}";
        }
        // For Yoruba/Igbo/Hausa: NO language field → Whisper auto-detect is better

        // — prompt: Nigerian + crypto context for beam-search accuracy —
        $prompt = $this->nigerianContextPrompt($languageHint);
        $body .= "--{$boundary}{$CRLF}";
        $body .= "Content-Disposition: form-data; name=\"prompt\"{$CRLF}{$CRLF}";
        $body .= $prompt . $CRLF;

        $body .= "--{$boundary}--{$CRLF}";
        return $body;
    }

    /**
     * Builds a Whisper decoder prompt loaded with Nigerian vocabulary.
     * Whisper's beam-search is heavily biased by the prompt, so injecting
     * the right words massively improves accuracy for Nigerian speakers.
     */
    private function nigerianContextPrompt(string $language): string
    {
        // Crypto + Solana vocab that always helps
        $crypto = "Solana, SOL, blockchain, NFT, crypto, wallet, devnet, mainnet, "
                . "airdrop, transaction, lamports, Bonfida, Magic Eden, USDC, staking, DeFi.";

        $lang = strtolower($language);

        $nigerianContexts = [
            'yoruba' => " Yoruba Nigerian speaker, may code-switch to English. "
                      . "Common Yoruba words: jọwọ (please), kí ni iye rẹ (what is its price), "
                      . "fí ranṣẹ (send), àpamọ́wọ́ (wallet), iye (price/value), "
                      . "ṣàyẹwò (check), dáadáa (good/fine).",

            'igbo'   => " Igbo Nigerian speaker, may code-switch to English. "
                      . "Common Igbo words: biko (please), ego (money), "
                      . "zipu (send), akpa ego (wallet), ego ole (how much), "
                      . "lelee (check), ọ dị mma (it is fine).",

            'hausa'  => " Hausa Nigerian speaker, may code-switch to English. "
                      . "Common Hausa words: don Allah (please), nawa ne (how much), "
                      . "aika (send), jakar kuɗi (wallet), kuɗi (money), "
                      . "duba (check), yakamata (should).",

            'pidgin' => " Nigerian Pidgin English speaker. "
                      . "Common Pidgin: wetin (what), abeg (please), oga (boss/sir), "
                      . "how much e be (how much is it), send am (send it), "
                      . "check am (check it), my wallet (my wallet), "
                      . "e don do (it is done), wahala (problem/trouble), "
                      . "na so e be (that is how it is), price don go up (price went up), "
                      . "I wan send (I want to send), make I check (let me check).",

            'english' => " Nigerian English speaker with Nigerian accent. "
                       . "May use expressions like: abeg, oga, my guy, no wahala.",
        ];

        return $crypto . ($nigerianContexts[$lang] ?? $nigerianContexts['english']);
    }


    /**
     * Returns MIME type for common Telegram audio extensions.
     */
    private function mimeForExtension(string $ext): string
    {
        $map = [
            'oga'  => 'audio/ogg',
            'ogg'  => 'audio/ogg',
            'opus' => 'audio/ogg',
            'mp3'  => 'audio/mpeg',
            'mpeg' => 'audio/mpeg',
            'mpga' => 'audio/mpeg',
            'mp4'  => 'audio/mp4',
            'm4a'  => 'audio/mp4',
            'wav'  => 'audio/wav',
            'webm' => 'audio/webm',
            'flac' => 'audio/flac',
        ];
        return $map[$ext] ?? 'audio/ogg';
    }

}