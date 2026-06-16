<?php
// services/AiTravelAssistantService.php
// Gemini-first assistant for itinerary explanation, route writing, and improvements.
// Ollama remains the local fallback when Gemini is not configured or unavailable.

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

        $capabilityAnswer = $this->capabilityAnswer($question);
        if ($capabilityAnswer !== null) {
            return [
                'status' => 'success',
                'answer' => $capabilityAnswer,
                'source' => 'rule',
            ];
        }

        $question = $this->truncateText($question, 700);
        $compactContext = $this->compactContext($context);

        $instructions = implode("\n", [
            "You are a local AI travel assistant for a Malaysian cultural tourism itinerary system.",
            "Use only the provided compact itinerary context.",
            "Do not invent live traffic, booking prices, opening hours, or saved changes.",
            "If information is missing, say it is estimated or not provided.",
            "Reply in the same language as the user when possible.",
            "Answer the traveller's actual question directly. Do not repeat the full itinerary unless the traveller asks for a summary or explanation.",
            "When asked what you can do, describe your available travel-assistant functions instead of summarising the itinerary.",
            "Keep the reply concise and practical. If the user asks for a longer explanation, give a fuller answer but keep it clear.",
            "Plain text only. No Markdown formatting.",
        ]);

        $input = "Compact itinerary context JSON:\n"
            . json_encode($compactContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nTraveller question:\n"
            . $question;

        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'answer' => 'cURL is not enabled.', 'source' => 'ai'];
        }

        $gemini = $this->callGemini($instructions, $input);
        if (($gemini['status'] ?? '') === 'success') {
            return $gemini;
        }
        if (($gemini['message'] ?? '') !== '') {
            error_log('Gemini AI fallback to Ollama: ' . $gemini['message']);
        }

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
                'num_predict' => 260,
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
                'answer' => 'AI service is currently unavailable. Please check Gemini API or Ollama fallback.',
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
                'answer' => 'AI service is currently unavailable. Please check Gemini API or Ollama fallback.',
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

    private function capabilityAnswer(string $question): ?string
    {
        $question = trim($question);
        $isCapabilityQuestion = (bool)preg_match(
            '/\b(?:what\s+(?:can|could)\s+you\s+do|what\s+do\s+you\s+do|how\s+can\s+you\s+help|what\s+can\s+u\s+do|your\s+(?:features|functions|capabilities)|help\s+me\s+with)\b|你能做什么|你可以做什么|你有什么功能|你可以帮什么|你会什么|可以做什么/iu',
            $question
        );

        if (!$isCapabilityQuestion) {
            return null;
        }

        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $question)) {
            return "我可以帮助你：\n"
                . "1. 解释整个行程、每日安排和地点背景。\n"
                . "2. 回答路线、交通时间、距离、预算和费用问题。\n"
                . "3. 根据你的要求建议酒店、景点和替代地点。\n"
                . "4. 提供天气规划建议，并提醒你核实实时天气和开放时间。\n"
                . "5. 建议增加、替换或重新安排行程地点。\n"
                . "6. 在你确认后，更新行程日期、出发地点或所选地点。\n"
                . "我不能直接完成酒店预订，也不能保证实时价格、交通或开放时间。";
        }

        return "I can help you with:\n"
            . "1. Explain the full trip, daily schedule, and background of each place.\n"
            . "2. Answer questions about routes, travel time, distance, budget, and estimated costs.\n"
            . "3. Suggest hotels, attractions, food places, and alternative stops when requested.\n"
            . "4. Give weather-planning advice and remind you to verify live weather and opening hours.\n"
            . "5. Suggest adding, replacing, or rearranging itinerary stops.\n"
            . "6. Update the trip date, starting location, or selected places after you confirm the change.\n"
            . "I cannot complete hotel bookings or guarantee live prices, traffic, or opening hours.";
    }

    private function containsChinese(string $text): bool
    {
        return (bool) preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text);
    }

    private function callGemini(string $instructions, string $input): array
    {
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
                'maxOutputTokens' => 360,
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
            CURLOPT_TIMEOUT => 35,
            CURLOPT_CONNECTTIMEOUT => 8,
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

        $finishReason = (string)($json['candidates'][0]['finishReason'] ?? '');
        $text = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        $text = $this->cleanAnswer($text);

        if ($text === '') {
            return ['status' => 'error', 'message' => 'Gemini returned an empty response.', 'source' => 'gemini'];
        }

        if ($finishReason === 'MAX_TOKENS' && (mb_strlen($text, 'UTF-8') < 80 || preg_match('/[-:]\s*$/u', $text))) {
            return ['status' => 'error', 'message' => 'Gemini response was truncated by token limit.', 'source' => 'gemini'];
        }

        return ['status' => 'success', 'answer' => $text, 'source' => 'gemini', 'model' => $model];
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
