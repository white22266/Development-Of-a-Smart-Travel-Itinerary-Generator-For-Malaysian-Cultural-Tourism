<?php
// services/AiTravelAssistantService.php
// Local Ollama assistant for itinerary explanation, route writing, and improvements.

class AiTravelAssistantService
{
    private string $model;
    private string $baseUrl;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model = trim((string)($model ?: (defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'qwen2.5:3b')));
        $this->baseUrl = rtrim(trim((string)($baseUrl ?: (defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434'))), '/');
    }

    public function answer(string $question, array $context): array
    {
        $question = trim($question);

        if ($question === '') {
            return [
                'status' => 'error',
                'answer' => 'Please type a question about this itinerary.',
                'source' => 'validation',
            ];
        }

        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'answer' => 'cURL is not enabled.', 'source' => 'ollama'];
        }

        $question = $this->truncateText($question, 700);
        $compactContext = $this->compactContext($context);

        $instructions = implode("\n", [
            "You are a local AI travel assistant for a Malaysian cultural tourism itinerary system.",
            "Use only the provided compact itinerary context.",
            "Do not invent live traffic, booking prices, opening hours, or saved changes.",
            "If information is missing, say it is estimated or not provided.",
            "Reply in the same language as the user when possible.",
            "Keep the reply concise, practical, and under 120 words.",
            "Plain text only. No Markdown formatting.",
        ]);

        $input = "Compact itinerary context JSON:\n"
            . json_encode($compactContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nTraveller question:\n"
            . $question;

        $url = str_ends_with($this->baseUrl, '/api') ? $this->baseUrl . '/chat' : $this->baseUrl . '/api/chat';

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            'stream' => false,
            'keep_alive' => -1,
            'options' => [
                'temperature' => 0.2,
                'num_ctx' => defined('OLLAMA_NUM_CTX') ? OLLAMA_NUM_CTX : 512,
                'num_predict' => 120,
                'num_thread' => 2,
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payloadJson === false) {
            return [
                'status' => 'error',
                'answer' => 'AI request could not be prepared. Please try again.',
                'source' => 'ollama',
            ];
        }

        error_log('Ollama AI payload size: ' . strlen($payloadJson) . ' bytes');

        $start = microtime(true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $start, 2);
        error_log('Ollama AI request duration: ' . $duration . 's | HTTP ' . $code . ' | URL: ' . $url);

        if ($raw === false || $err !== '') {
            error_log('Ollama AI request failed: ' . $err . ' | URL: ' . $url);
            return [
                'status' => 'error',
                'answer' => 'AI service is currently unavailable. Please make sure Ollama is running.',
                'source' => 'ollama',
            ];
        }

        $json = json_decode($raw, true);

        if ($code === 404 || (is_array($json) && isset($json['error']) && stripos((string)$json['error'], 'not found') !== false)) {
            return [
                'status' => 'error',
                'answer' => 'AI model is not installed. Please run: ollama pull ' . $this->model,
                'source' => 'ollama',
            ];
        }

        if ($code < 200 || $code >= 300) {
            error_log('Ollama AI HTTP error: ' . $code . ' | Response: ' . $this->truncateText((string)$raw, 500));
            return [
                'status' => 'error',
                'answer' => 'AI service is currently unavailable. Please make sure Ollama is running.',
                'source' => 'ollama',
            ];
        }

        $text = is_array($json) ? trim((string)($json['message']['content'] ?? '')) : '';
        $text = $this->cleanAnswer($text);

        if ($text === '') {
            return ['status' => 'error', 'answer' => 'AI response is invalid. Please try again.', 'source' => 'ollama'];
        }

        return ['status' => 'success', 'answer' => $text, 'source' => 'ollama'];
    }

    private function compactContext(array $context): array
    {
        $preferredKeys = [
            'itinerary_id',
            'title',
            'destination',
            'state',
            'states',
            'start_date',
            'end_date',
            'date',
            'duration',
            'budget',
            'companions',
            'travel_style',
            'preferences',
            'food_preferences',
            'accessibility_needs',
            'hotel',
            'hotels',
            'days',
            'places',
            'route',
        ];

        $compact = [];

        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $context)) {
                $compact[$key] = $this->trimForPrompt($context[$key], 0);
            }
        }

        if (empty($compact)) {
            $compact = $this->trimForPrompt(array_slice($context, 0, 12, true), 0);
        }

        return is_array($compact) ? $compact : [];
    }

    private function trimForPrompt(mixed $value, int $depth): mixed
    {
        if ($depth > 4) {
            return null;
        }

        if (is_string($value)) {
            return $this->truncateText($value, 220);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $result = [];
        $isList = array_is_list($value);
        $limit = $isList ? 8 : 20;
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count >= $limit) {
                break;
            }

            $keyString = is_string($key) ? strtolower($key) : '';

            if (preg_match('/image|photo|thumbnail|video|created_at|updated_at|password|token|secret|api_key/i', $keyString)) {
                continue;
            }

            $result[$key] = $this->trimForPrompt($item, $depth + 1);
            $count++;
        }

        return $result;
    }

    private function truncateText(string $text, int $limit): string
    {
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit, 'UTF-8') . '...';
    }

    private function cleanAnswer(string $text): string
    {
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/u', '$1', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*]\s+/m', '- ', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = trim($text);

        return $this->truncateText($text, 1200);
    }
}
