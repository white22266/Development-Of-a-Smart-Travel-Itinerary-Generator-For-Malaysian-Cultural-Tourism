<?php
/**
 * services/AccessibilityNeedsAnalysisService.php
 *
 * Converts free-text accessibility notes into a compact rule profile that the
 * itinerary generator can use. Gemini is used first when configured; Ollama and
 * deterministic keyword parsing are kept as fallbacks so saving never depends on AI.
 */

class AccessibilityNeedsAnalysisService
{
    private string $model;
    private string $baseUrl;

    private const ALLOWED_TAGS = [
        'elderly',
        'wheelchair',
        'avoid_stairs',
        'low_walking',
        'indoor_preferred',
        'avoid_heat',
        'family_friendly',
        'rest_buffer',
        'step_free',
    ];

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model = trim((string)($model ?: (defined('OLLAMA_MODEL') ? OLLAMA_MODEL : 'qwen2.5:3b')));
        if ($this->model === '') $this->model = 'qwen2.5:3b';

        $this->baseUrl = rtrim(trim((string)($baseUrl ?: (defined('OLLAMA_BASE_URL') ? OLLAMA_BASE_URL : 'http://127.0.0.1:11434'))), '/');
        if ($this->baseUrl === '') $this->baseUrl = 'http://127.0.0.1:11434';
    }

    public function analyze(string $rawText, string $travellerType = 'solo'): array
    {
        $rawText = $this->sanitize($rawText, 120);
        $travellerType = strtolower(trim($travellerType));

        if ($rawText === '') {
            return [
                'status' => 'empty',
                'source' => 'none',
                'raw_text' => '',
                'tags' => [],
                'summary' => 'No special accessibility restriction.',
                'stored_text' => '',
            ];
        }

        $ai = $this->callGemini($rawText, $travellerType);
        if (($ai['status'] ?? '') !== 'success') {
            if (($ai['message'] ?? '') !== '') {
                error_log('Gemini accessibility fallback to Ollama: ' . $ai['message']);
            }
            $ai = $this->callOllama($rawText, $travellerType);
        }
        if (($ai['status'] ?? '') === 'success') {
            return $this->buildResult($rawText, $ai['tags'] ?? [], (string)($ai['summary'] ?? ''), (string)($ai['source'] ?? 'ai'));
        }

        $fallback = $this->keywordFallback($rawText, $travellerType);
        $result = $this->buildResult($rawText, $fallback['tags'], $fallback['summary'], 'local_fallback');
        $result['warning'] = $ai['message'] ?? 'AI service unavailable; local keyword fallback was used.';
        return $result;
    }

    private function callGemini(string $rawText, string $travellerType): array
    {
        $apiKey = defined('GEMINI_API_KEY') ? trim((string)GEMINI_API_KEY) : '';
        if ($apiKey === '') {
            return ['status' => 'skipped', 'message' => 'Gemini API key is not configured.', 'source' => 'gemini'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'message' => 'PHP cURL is not enabled.', 'source' => 'gemini'];
        }

        $model = defined('GEMINI_MODEL') && trim((string)GEMINI_MODEL) !== ''
            ? trim((string)GEMINI_MODEL)
            : 'gemini-2.5-flash';
        $model = preg_replace('#^models/#', '', $model) ?? $model;
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $allowed = implode(', ', self::ALLOWED_TAGS);

        $payload = [
            'systemInstruction' => [
                'parts' => [[
                    'text' => 'You analyze accessibility notes for a travel itinerary system. Return JSON only. No markdown.',
                ]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => "Traveller type: {$travellerType}\n"
                        . "Accessibility notes: {$rawText}\n\n"
                        . "Choose only these tags when relevant: {$allowed}.\n"
                        . "Return exactly this JSON shape: {\"tags\":[\"tag\"],\"summary\":\"one short rule sentence\"}.\n"
                        . "If the notes are vague, infer conservatively.",
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 120,
            ],
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return ['status' => 'error', 'message' => 'Gemini request could not be prepared.', 'source' => 'gemini'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['status' => 'error', 'message' => 'Gemini request failed: ' . $err, 'source' => 'gemini'];
        }

        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            $message = is_array($json) ? (string)($json['error']['message'] ?? 'Gemini HTTP error ' . $code) : 'Gemini HTTP error ' . $code;
            return ['status' => 'error', 'message' => $message, 'source' => 'gemini'];
        }

        $content = '';
        foreach (($json['candidates'][0]['content']['parts'] ?? []) as $part) {
            $content .= (string)($part['text'] ?? '');
        }
        $parsed = $this->extractJson(trim($content));
        if (!$parsed) {
            return ['status' => 'error', 'message' => 'Gemini response is invalid.', 'source' => 'gemini'];
        }

        return [
            'status' => 'success',
            'source' => 'gemini',
            'tags' => $this->normalizeTags($parsed['tags'] ?? []),
            'summary' => $this->sanitize((string)($parsed['summary'] ?? ''), 160),
        ];
    }

    private function callOllama(string $rawText, string $travellerType): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'message' => 'PHP cURL is not enabled.'];
        }

        $url = str_ends_with($this->baseUrl, '/api') ? $this->baseUrl . '/chat' : $this->baseUrl . '/api/chat';
        $allowed = implode(', ', self::ALLOWED_TAGS);
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You analyze accessibility notes for a travel itinerary system. Return JSON only. No markdown.',
                ],
                [
                    'role' => 'user',
                    'content' => "Traveller type: {$travellerType}\n"
                        . "Accessibility notes: {$rawText}\n\n"
                        . "Choose only these tags when relevant: {$allowed}.\n"
                        . "Return exactly this JSON shape: {\"tags\":[\"tag\"],\"summary\":\"one short rule sentence\"}.\n"
                        . "If the notes are vague, infer conservatively.",
                ],
            ],
            'stream' => false,
            'options' => [
                'temperature' => 0.1,
                'num_ctx' => defined('OLLAMA_NUM_CTX') ? OLLAMA_NUM_CTX : 512,
                'num_predict' => 120,
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            error_log('Ollama accessibility request failed: ' . $err . ' | URL: ' . $url);
            return ['status' => 'error', 'message' => 'AI service is currently unavailable.'];
        }

        $json = json_decode($raw, true);
        if ($code === 404 || (is_array($json) && isset($json['error']) && stripos((string)$json['error'], 'not found') !== false)) {
            return ['status' => 'error', 'message' => 'AI model is not installed. Please run: ollama pull ' . $this->model];
        }
        if ($code < 200 || $code >= 300 || !is_array($json) || !isset($json['message']['content'])) {
            return ['status' => 'error', 'message' => 'AI response is invalid.'];
        }

        $content = trim((string)$json['message']['content']);
        $parsed = $this->extractJson($content);
        if (!$parsed) {
            return ['status' => 'error', 'message' => 'AI response is invalid.'];
        }

        return [
            'status' => 'success',
            'source' => 'ollama',
            'tags' => $this->normalizeTags($parsed['tags'] ?? []),
            'summary' => $this->sanitize((string)($parsed['summary'] ?? ''), 160),
        ];
    }

    private function keywordFallback(string $rawText, string $travellerType): array
    {
        $text = strtolower($rawText);
        $tags = [];

        $match = function (array $needles) use ($text): bool {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($text, strtolower($needle))) return true;
            }
            return false;
        };

        if ($match(['elderly', 'senior', 'old people', '老人', '长者', '年长'])) $tags[] = 'elderly';
        if ($match(['wheelchair', 'wheel chair', '轮椅', 'disabled', 'oku'])) $tags[] = 'wheelchair';
        if ($match(['avoid stairs', 'no stairs', 'less stairs', 'stair', '楼梯', '不要爬楼梯', '避免楼梯'])) $tags[] = 'avoid_stairs';
        if ($match(['walk less', 'less walking', 'low walking', 'cannot walk', '少走路', '不要走太多', '不方便走路'])) $tags[] = 'low_walking';
        if ($match(['indoor', '室内', 'shopping mall', 'museum'])) $tags[] = 'indoor_preferred';
        if ($match(['avoid heat', 'too hot', 'hot weather', 'sun', '怕热', '太热', '避免晒'])) $tags[] = 'avoid_heat';
        if ($match(['family', 'kids', 'children', 'child', '小孩', '家庭'])) $tags[] = 'family_friendly';

        if ($travellerType === 'family') $tags[] = 'family_friendly';
        if (in_array('elderly', $tags, true) || in_array('wheelchair', $tags, true) || in_array('avoid_stairs', $tags, true)) {
            $tags[] = 'low_walking';
            $tags[] = 'rest_buffer';
        }
        if (in_array('wheelchair', $tags, true)) $tags[] = 'step_free';
        if (in_array('avoid_heat', $tags, true)) $tags[] = 'indoor_preferred';

        $tags = $this->normalizeTags($tags);
        return [
            'tags' => $tags,
            'summary' => $this->summaryFromTags($tags),
        ];
    }

    private function buildResult(string $rawText, array $tags, string $summary, string $source): array
    {
        $tags = $this->normalizeTags($tags);
        if ($summary === '') $summary = $this->summaryFromTags($tags);

        $tagText = empty($tags) ? 'general' : implode(',', $tags);
        $prefix = $this->sanitize($rawText, 70);
        $stored = $prefix . ' | AI: ' . $tagText;
        if (strlen($stored) > 120) {
            $stored = substr($prefix, 0, max(0, 114 - strlen($tagText))) . ' | AI: ' . $tagText;
            $stored = substr($stored, 0, 120);
        }

        return [
            'status' => 'success',
            'source' => $source,
            'raw_text' => $rawText,
            'tags' => $tags,
            'summary' => $summary,
            'stored_text' => $stored,
        ];
    }

    private function normalizeTags($tags): array
    {
        if (!is_array($tags)) return [];
        $clean = [];
        foreach ($tags as $tag) {
            $tag = strtolower(trim(str_replace([' ', '-'], '_', (string)$tag)));
            if (in_array($tag, self::ALLOWED_TAGS, true) && !in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }
        return $clean;
    }

    private function summaryFromTags(array $tags): string
    {
        if (empty($tags)) return 'No strong accessibility restriction detected.';
        if (in_array('wheelchair', $tags, true)) return 'Prefer wheelchair-friendly, step-free, low-walking places with extra rest time.';
        if (in_array('elderly', $tags, true)) return 'Prefer elderly-friendly, low-walking places with extra rest time.';
        if (in_array('avoid_heat', $tags, true)) return 'Prefer indoor or morning activities and avoid long outdoor heat exposure.';
        if (in_array('avoid_stairs', $tags, true)) return 'Avoid stairs and prefer easier access places.';
        if (in_array('low_walking', $tags, true)) return 'Reduce walking distance and add longer rest buffer.';
        return 'Accessibility notes converted into itinerary planning rules.';
    }

    private function extractJson(string $content): ?array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) return null;
        $json = substr($content, $start, $end - $start + 1);
        $parsed = json_decode($json, true);
        return is_array($parsed) ? $parsed : null;
    }

    private function sanitize(string $value, int $maxLen): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';
        if (function_exists('mb_substr')) return mb_substr($value, 0, $maxLen);
        return substr($value, 0, $maxLen);
    }
}
