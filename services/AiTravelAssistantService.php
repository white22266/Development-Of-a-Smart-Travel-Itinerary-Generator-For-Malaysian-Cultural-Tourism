<?php
// services/AiTravelAssistantService.php
// Fast local travel assistant service.
// Default behaviour: rule-based quick replies first, optional Gemini only when explicitly enabled,
// then short Ollama fallback with strict timeout so the chat does not stay on "Writing answer...".
// Answers are post-processed so length-limited model output does not end halfway through a sentence.

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

        $compactContext = $this->compactContext($context);
        $ruleAnswer = $this->ruleAnswer($question, $compactContext);
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

        $instructions = implode("\n", [
            'You are a local AI travel assistant for a Malaysian cultural tourism itinerary system.',
            'Use only the provided compact itinerary context.',
            'Answer directly and practically.',
            'Keep the reply under 60 words unless the user clearly asks for details.',
            'Prefer 1 to 3 complete sentences instead of long lists.',
            'Every sentence must be complete. Do not end with an unfinished phrase.',
            'If the full answer would be too long, give only the most important conclusion.',
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

    private function ruleAnswer(string $question, array $context = []): ?string
    {
        $q = strtolower(trim($question));
        $normalized = preg_replace('/[^a-z0-9\s]/i', '', $q) ?? $q;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        $isCapabilityQuestion = (bool)preg_match(
            '/\b(?:what\s+(?:can|could)\s+you\s+do|what\s+you\s+can\s+do|what\s+u\s+can\s+do|what\s+can\s+u\s+do|what\s+do\s+you\s+do|how\s+can\s+you\s+help|your\s+(?:features|functions|capabilities)|help\s+me\s+with)\b/iu',
            $question
        );

        if ($isCapabilityQuestion) {
            return 'I can explain your preference, suggest a starting location, check route readiness, estimate cost, describe travel time, and review hotel or itinerary options. I only guide you; the system rules still generate the official itinerary.';
        }

        if (preg_match('/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i', $normalized)) {
            return 'Hello. I can help you check your travel preference, starting location, route, estimated cost, and itinerary readiness.';
        }

        if (preg_match('/^(ok|okay|thanks|thank you|tq|thx)$/i', $normalized)) {
            return 'You are welcome. Ask me about your selected preference, starting location, route, cost, or itinerary readiness.';
        }

        if (preg_match('/^(then|next|continue|more|go on|and then|what next|so)$/i', $normalized)) {
            return 'Please ask a specific travel question, such as checking your preference, suggesting a starting location, or explaining route readiness.';
        }

        if (preg_match('/^(help|menu|options|start)$/i', $normalized)) {
            return 'You can ask me to check your selected preference, suggest a starting location, explain route readiness, estimate cost, or review itinerary changes.';
        }

        if ($this->isPreferenceReadinessQuestion($normalized)) {
            return $this->buildPreferenceReadinessAnswer($context);
        }

        if ($this->isStartingLocationQuestion($normalized)) {
            return $this->buildStartingLocationAnswer($context);
        }

        return null;
    }

    private function isPreferenceReadinessQuestion(string $normalized): bool
    {
        if (preg_match('/^(check my preference|before generate|before generating|check preference|selected preference)$/i', $normalized)) {
            return true;
        }

        return (bool)preg_match(
            '/\b(?:selected\s+preference|preference\s+(?:suitable|ready|complete|confirmed)|suitable\s+for\s+generating|generate\s+an\s+itinerary|before\s+generat(?:e|ing)|check\s+(?:my\s+)?preference)\b/i',
            $normalized
        );
    }

    private function isStartingLocationQuestion(string $normalized): bool
    {
        if (preg_match('/^(suggest starting location|starting location|start location|origin|suggest origin)$/i', $normalized)) {
            return true;
        }

        return (bool)preg_match(
            '/\b(?:(?:suggest|recommend|choose|decide|suitable)\s+(?:a\s+)?(?:suitable\s+)?(?:starting|start|origin)\s+location|starting\s+location|start\s+location|origin\s+location)\b/i',
            $normalized
        );
    }

    private function buildPreferenceReadinessAnswer(array $context): string
    {
        $missing = [];

        $startDate = $this->findContextText($context, ['start_date', 'trip_start_date', 'date']);
        $origin = $this->findContextText($context, ['origin_name', 'starting_location', 'start_location', 'origin']);
        $states = $this->findContextText($context, ['preferred_states', 'state', 'states']);
        $districts = $this->findContextText($context, ['preferred_districts', 'district', 'districts']);
        $interests = $this->findContextText($context, ['interests', 'selected_interests', 'preference_interests']);
        $transport = $this->findContextText($context, ['transport_type', 'transport', 'travel_mode']);
        $budget = $this->findContextText($context, ['budget', 'budget_tier', 'total_budget']);

        if (!$this->isFilled($startDate)) $missing[] = 'start date';
        if (!$this->isFilled($origin)) $missing[] = 'starting location';
        if (!$this->isFilled($states) && !$this->isFilled($districts)) $missing[] = 'preferred state or district';
        if (!$this->isFilled($interests)) $missing[] = 'travel interests';
        if (!$this->isFilled($transport)) $missing[] = 'transport type';
        if (!$this->isFilled($budget)) $missing[] = 'budget';

        if (empty($missing)) {
            return 'Your selected preference looks ready for itinerary generation. You can proceed, and the official itinerary will still be generated by the system rules.';
        }

        $summary = implode(', ', array_slice($missing, 0, 4));
        if (count($missing) > 4) {
            $summary .= ', and other preference details';
        }

        return 'Your preference is not fully ready yet. Please complete ' . $summary . ' before generating the itinerary.';
    }

    private function buildStartingLocationAnswer(array $context): string
    {
        $origin = $this->findContextText($context, ['origin_name', 'starting_location', 'start_location', 'origin']);
        if ($this->isFilled($origin)) {
            return 'Use ' . $origin . ' as the starting location because it is already saved. If this is wrong, update the starting location before generating the itinerary.';
        }

        $district = $this->findContextText($context, ['preferred_districts', 'district', 'districts']);
        $state = $this->findContextText($context, ['preferred_states', 'state', 'states']);
        $place = $this->isFilled($district) ? $district : ($this->isFilled($state) ? $state : 'your preferred destination area');

        return 'Choose a central and easy-to-reach point in ' . $place . ', such as your hotel, a main bus terminal, or a train station. Save it before generating so route time and cost are more accurate.';
    }

    private function findContextText(array $context, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->findContextValue($context, strtolower($key));
            if ($value !== null) {
                if (is_array($value)) {
                    $flat = array_values(array_filter(array_map(static fn($v) => trim((string)$v), $value)));
                    if (!empty($flat)) {
                        return implode(', ', array_slice($flat, 0, 3));
                    }
                } else {
                    $text = trim((string)$value);
                    if ($text !== '') {
                        return $this->truncateText($text, 80, false);
                    }
                }
            }
        }

        return '';
    }

    private function findContextValue(mixed $value, string $needleKey): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $item) {
            if (is_string($key) && strtolower($key) === $needleKey) {
                return $item;
            }

            if (is_array($item)) {
                $found = $this->findContextValue($item, $needleKey);
                if ($found !== null && $found !== '') {
                    return $found;
                }
            }
        }

        return null;
    }

    private function isFilled(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return !preg_match('/^(0|0\.0|none|null|not provided|not set|unknown|n\/a)$/i', $value);
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
        $numPredict = max(96, min(140, (int)(getenv('OLLAMA_NUM_PREDICT') ?: 120)));
        $numCtx = defined('OLLAMA_NUM_CTX') ? (int)OLLAMA_NUM_CTX : 768;
        $numCtx = max(256, min(1024, $numCtx));
        $numThread = max(2, min(4, (int)(getenv('OLLAMA_NUM_THREAD') ?: 4)));
        $temperature = max(0.0, min(0.7, (float)(getenv('OLLAMA_TEMPERATURE') ?: 0.15)));

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            'stream' => false,
            'keep_alive' => getenv('OLLAMA_KEEP_ALIVE') ?: '30m',
            'options' => [
                'temperature' => $temperature,
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
                    ? 'The local AI took too long to answer. Please ask a shorter question, or use qwen2.5:1.5b for faster replies.'
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
        $doneReason = is_array($json) ? strtolower((string)($json['done_reason'] ?? '')) : '';
        $wasLengthLimited = in_array($doneReason, ['length', 'stop'], true) && !$this->endsLikeCompleteSentence($text);
        $text = $this->cleanAnswer($text, $wasLengthLimited);

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
                'temperature' => max(0.0, min(0.7, (float)(getenv('GEMINI_TEMPERATURE') ?: 0.15))),
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
            'preference',
            'preferences',
            'selected_preference',
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
            'starting_location',
        ];

        $compact = [];
        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $context)) {
                $compact[$key] = $this->trimForPrompt($context[$key], 0);
            }
        }

        if (empty($compact)) {
            $compact = $this->trimForPrompt(array_slice($context, 0, 10, true), 0);
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
        $limit = $isList ? 6 : 16;
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

    private function truncateText(string $text, int $limit, bool $appendEllipsis = true): string
    {
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }

        $cut = rtrim(mb_substr($text, 0, $limit, 'UTF-8'));
        return $appendEllipsis ? $cut . '...' : $cut;
    }

    private function cleanAnswer(string $text, bool $wasLengthLimited = false): string
    {
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/u', '$1', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*]\s+/m', '- ', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = trim($text);

        return $this->finalizeAnswer($text, $wasLengthLimited);
    }

    private function finalizeAnswer(string $text, bool $wasLengthLimited = false): string
    {
        $text = $this->truncateText($text, 420, false);
        if ($text === '') {
            return '';
        }

        if ($wasLengthLimited || !$this->endsLikeCompleteSentence($text)) {
            $complete = $this->clipToLastCompleteSentence($text);
            if ($complete !== '') {
                $text = $complete;
            }
        }

        $limitedSentences = $this->limitCompleteSentences($text, 3);
        if ($limitedSentences !== '') {
            $text = $limitedSentences;
        }

        $text = rtrim($text, " \t\n\r\0\x0B,;:-");
        if ($text !== '' && !$this->endsLikeCompleteSentence($text)) {
            $text .= '.';
        }

        if ($wasLengthLimited && !preg_match('/ask me for details/i', $text)) {
            $text .= ' Ask me for details if needed.';
        }

        return $text;
    }

    private function limitCompleteSentences(string $text, int $maxSentences): string
    {
        $text = trim($text);
        if ($text === '' || $maxSentences <= 0) {
            return '';
        }

        if (!preg_match_all('/[^.!?]+[.!?]/u', $text, $matches)) {
            return $text;
        }

        $sentences = array_slice(array_map('trim', $matches[0]), 0, $maxSentences);
        return trim(implode(' ', array_filter($sentences)));
    }

    private function clipToLastCompleteSentence(string $text): string
    {
        if (!preg_match_all('/[.!?](?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $last = end($matches[0]);
        if (!is_array($last) || !isset($last[1])) {
            return '';
        }

        $candidate = trim(substr($text, 0, $last[1] + strlen((string)$last[0])));
        return mb_strlen($candidate, 'UTF-8') >= 20 ? $candidate : '';
    }

    private function endsLikeCompleteSentence(string $text): bool
    {
        return (bool)preg_match('/[.!?)]$/u', trim($text));
    }
}
