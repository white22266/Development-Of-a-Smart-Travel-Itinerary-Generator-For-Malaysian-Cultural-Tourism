<?php
// services/AiTravelAssistantService.php
// Fast local travel assistant service.
// Default behaviour: rule-based quick replies first, optional Gemini only when explicitly enabled,
// then short Ollama fallback with strict timeout so the chat does not stay on "Writing answer...".

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

        if ($this->containsChinese($question)) {
            return [
                'status' => 'success',
                'answer' => 'Only English is accepted. Please enter English.',
                'source' => 'language_guard',
            ];
        }

        if ($question === '') {
            return [
                'status' => 'error',
                'answer' => 'Please type a question about this itinerary.',
                'source' => 'validation',
            ];
        }

        $ruleAnswer = $this->ruleAnswer($question);
        if ($ruleAnswer !== null) {
            return [
                'status' => 'success',
                'answer' => $ruleAnswer,
                'source' => 'rule',
            ];
        }

        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'answer' => 'cURL is not enabled.', 'source' => 'server'];
        }

        $question = $this->truncateText($question, 500);
        $compactContext = $this->compactContext($context);

        $instructions = implode("\n", [
            'You are a local AI travel assistant for a Malaysian cultural tourism itinerary system.',
            'Use only the provided compact itinerary context.',
            'Answer directly and practically.',
            'Keep the reply under 80 words unless the user clearly asks for details.',
            'Do not invent live traffic, booking prices, opening hours, or saved changes.',
            'If information is missing, say it is estimated or not provided.',
            'Do not say a hotel, date, route, or place has been saved unless the system confirms it.',
            'Plain text only. No Markdown formatting.',
        ]);

        $input = "Compact itinerary context JSON:\n"
            . json_encode($compactContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nTraveller question:\n"
            . $question;

        // Gemini is disabled by default for VM deployment speed. Enable only with AI_USE_GEMINI=true.
        $gemini = $this->callGemini($instructions, $input);
        if (($gemini['status'] ?? '') === 'success') {
            return $gemini;
        }
        if (($gemini['message'] ?? '') !== '') {
            error_log('Gemini skipped/fallback to Ollama: ' . $gemini['message']);
        }

        return $this->callOllama($instructions, $input);
    }

    private function ruleAnswer(string $question): ?string
    {
        $q = strtolower(trim($question));
        $normalized = preg_replace('/[^a-z0-9\s]/i', '', $q) ?? $q;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        if (preg_match('/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i', $normalized)) {
            return 'Hello. I can help with this trip summary, estimated cost, route, hotel options, and itinerary changes.';
        }

        if (preg_match('/^(ok|okay|thanks|thank you|tq|thx)$/i', $normalized)) {
            return 'You are welcome. Ask me about cost, route, hotel options, or changes to this itinerary.';
        }

        $isCapabilityQuestion = (bool)preg_match(
            '/\b(?:what\s+(?:can|could)\s+you\s+do|what\s+do\s+you\s+do|how\s+can\s+you\s+help|what\s+can\s+u\s+do|your\s+(?:features|functions|capabilities)|help\s+me\s+with)\b/iu',
            $question
        );

        if ($isCapabilityQuestion) {
            return 'I can explain the trip summary, check estimated cost and budget, describe routes and travel time, suggest hotel options, and propose itinerary changes. I will only save hotel or route changes after you confirm.';
        }

        return null;
    }

    private function containsChinese(string $text): bool
    {
        return (bool)preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text);
    }

    private function callOllama(string $instructions, string $input): array
    {
        $url = str_ends_with($this->baseUrl, '/api') ? $this->baseUrl . '/chat' : $this->baseUrl . '/api/chat';
        $timeout = max(3, (int)(getenv('OLLAMA_TIMEOUT') ?: 12));
        $connectTimeout = max(1, (int)(getenv('OLLAMA_CONNECT_TIMEOUT') ?: 2));
        $numPredict = max(32, min(160, (int)(getenv('OLLAMA_NUM_PREDICT') ?: 96)));
        $numCtx = defined('OLLAMA_NUM_CTX') ? (int)OLLAMA_NUM_CTX : 768;
        $numCtx = max(256, min(1024, $numCtx));
        $numThread = max(2, min(4, (int)(getenv('OLLAMA_NUM_THREAD') ?: 4)));

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            'stream' => false,
            'keep_alive' => getenv('OLLAMA_KEEP_ALIVE') ?: '30m',
            'options' => [
                'temperature' => 0.2,
                'num_ctx' => $numCtx,
                'num_predict' => $numPredict,
                'num_thread' => $numThread,
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
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
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
            $timedOut = stripos($err, 'timed out') !== false || $duration >= $timeout;
            return [
                'status' => 'error',
                'answer' => $timedOut
                    ? 'The local AI took too long to answer. Please ask a shorter question, or use a smaller Ollama model such as qwen2.5:1.5b for faster replies.'
                    : 'AI service is currently unavailable. Please check whether Ollama is running.',
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
                'answer' => 'AI service is currently unavailable. Please check whether Ollama is running.',
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

    private function callGemini(string $instructions, string $input): array
    {
        $useGemini = strtolower(trim((string)(getenv('AI_USE_GEMINI') ?: 'false'))) === 'true';
        if (!$useGemini) {
            return ['status' => 'skipped', 'message' => 'Gemini disabled for fast VM response.', 'source' => 'gemini'];
        }

        $apiKey = defined('GEMINI_API_KEY') ? trim((string)GEMINI_API_KEY) : '';
        if ($apiKey === '') {
            return ['status' => 'skipped', 'message' => 'Gemini API key is not configured.', 'source' => 'gemini'];
        }

        $model = defined('GEMINI_MODEL') && trim((string)GEMINI_MODEL) !== ''
            ? trim((string)GEMINI_MODEL)
            : 'gemini-2.5-flash';
        $model = preg_replace('#^models/#', '', $model) ?? $model;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $instructions],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $input],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 160,
                'thinkingConfig' => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return ['status' => 'error', 'message' => 'Gemini request could not be prepared.', 'source' => 'gemini'];
        }

        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => max(3, (int)(getenv('GEMINI_TIMEOUT') ?: 4)),
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = round(microtime(true) - $start, 2);
        error_log('Gemini AI request duration: ' . $duration . 's | HTTP ' . $code . ' | Model: ' . $model);

        if ($raw === false || $err !== '') {
            return ['status' => 'error', 'message' => 'Gemini request failed: ' . $err, 'source' => 'gemini'];
        }

        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            $message = is_array($json) ? (string)($json['error']['message'] ?? 'Gemini HTTP error ' . $code) : 'Gemini HTTP error ' . $code;
            return ['status' => 'error', 'message' => $message, 'source' => 'gemini'];
        }

        $text = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        $text = $this->cleanAnswer($text);

        if ($text === '') {
            return ['status' => 'error', 'message' => 'Gemini returned an empty response.', 'source' => 'gemini'];
        }

        return ['status' => 'success', 'answer' => $text, 'source' => 'gemini', 'model' => $model];
    }

    private function compactContext(array $context): array
    {
        $preferredKeys = [
            'itinerary',
            'items',
            'hotel_options',
            'hotels',
            'cost',
            'route',
            'days',
            'places',
            'budget',
            'start_date',
            'origin_name',
        ];

        $compact = [];
        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $context)) {
                $compact[$key] = $this->trimForPrompt($context[$key], 0);
            }
        }

        if (empty($compact)) {
            $compact = $this->trimForPrompt(array_slice($context, 0, 8, true), 0);
        }

        return is_array($compact) ? $compact : [];
    }

    private function trimForPrompt(mixed $value, int $depth): mixed
    {
        if ($depth > 3) {
            return null;
        }

        if (is_string($value)) {
            return $this->truncateText($value, 160);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            return null;
        }

        $result = [];
        $isList = array_is_list($value);
        $limit = $isList ? 6 : 14;
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
        return $this->truncateText($text, 700);
    }
}
