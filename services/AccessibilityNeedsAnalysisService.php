<?php
/**
 * services/AccessibilityNeedsAnalysisService.php
 *
 * Converts free-text accessibility notes into a compact rule profile that the
 * itinerary generator can use. The local Ollama model picks the tags; keyword
 * parsing is the fallback, so saving a preference never depends on AI.
 */

require_once __DIR__ . '/OllamaClient.php';

class AccessibilityNeedsAnalysisService
{
    private OllamaClient $ollama;

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
        $this->ollama = new OllamaClient($model, $baseUrl);
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

        if ($this->containsChinese($rawText)) {
            return [
                'status' => 'language_rejected',
                'source' => 'language_guard',
                'raw_text' => $rawText,
                'tags' => [],
                'summary' => 'Only English is accepted. Please enter English.',
                'stored_text' => $rawText,
                'warning' => 'Only English is accepted. Please enter English.',
            ];
        }

        $ai = $this->callOllama($rawText, $travellerType);
        if (($ai['status'] ?? '') === 'success') {
            return $this->buildResult($rawText, $ai['tags'] ?? [], (string)($ai['summary'] ?? ''), (string)($ai['source'] ?? 'ai'));
        }

        $fallback = $this->keywordFallback($rawText, $travellerType);
        $result = $this->buildResult($rawText, $fallback['tags'], $fallback['summary'], 'local_fallback');
        $result['warning'] = 'AI analysis was unavailable, so keyword rules were used.';
        return $result;
    }

    private function callOllama(string $rawText, string $travellerType): array
    {
        $allowed = implode(', ', self::ALLOWED_TAGS);
        $reply = $this->ollama->chat(
            'You analyze accessibility notes for a travel itinerary system. Return JSON only. No markdown.',
            "Traveller type: {$travellerType}\n"
                . "Accessibility notes: {$rawText}\n\n"
                . "Choose only these tags when relevant: {$allowed}.\n"
                . "Return exactly this JSON shape: {\"tags\":[\"tag\"],\"summary\":\"one short rule sentence\"}.\n"
                . "If the notes are vague, infer conservatively.",
            ['format' => 'json', 'num_predict' => 120, 'temperature' => 0.1, 'timeout' => 20]
        );
        if (!$reply['ok']) {
            error_log('AccessibilityNeedsAnalysisService: ' . $reply['error']);
            return ['status' => 'error'];
        }

        $parsed = $this->extractJson($reply['text']);
        if (!$parsed) {
            error_log('AccessibilityNeedsAnalysisService: reply was not valid JSON.');
            return ['status' => 'error'];
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
        $tags = self::keywordTags($rawText, $travellerType);
        return [
            'tags' => $tags,
            'summary' => $this->summaryFromTags($tags),
        ];
    }

    /**
     * The tags stored after "AI:" in a saved accessibility_needs text, or null when the text
     * has no such part. "general" means no tag.
     *
     * @return string[]|null
     */
    public static function storedTags(string $storedText): ?array
    {
        $pos = strripos($storedText, 'AI:');
        if ($pos === false) return null;
        $tags = [];
        foreach (explode(',', substr($storedText, $pos + 3)) as $tag) {
            $tag = strtolower(trim(str_replace([' ', '-'], '_', trim($tag))));
            if (in_array($tag, self::ALLOWED_TAGS, true) && !in_array($tag, $tags, true)) $tags[] = $tag;
        }
        return $tags;
    }

    /**
     * Tags read from the traveller's own words by keyword rules, without AI.
     *
     * Keywords are matched as whole words. A clause that negates a need ("no wheelchair
     * needed", "not elderly") gives no tag for it, and neither does "stairs are fine" or
     * "don't mind the heat". Asking to avoid something ("no stairs") still gives its tag.
     *
     * @return string[]
     */
    public static function keywordTags(string $rawText, string $travellerType = 'solo'): array
    {
        $tags = [];
        $clauses = preg_split('/[,;.!?\/|]+|\b(?:but|however|although|though)\b/u', strtolower($rawText)) ?: [];
        $negation = '/\b(?:no|not|never|without|none|nobody|don\'?t|doesn\'?t|isn\'?t|aren\'?t|do not|does not|is not|are not|no need|needn\'?t)\b/u';
        $noProblem = '/\b(?:fine|ok|okay|no problem|not a problem|not an issue|no issue)\b|\b(?:don\'?t|do not|doesn\'?t|does not|never) mind\b/u';

        // $kind: "need" is negated by a negation before it; "avoid" names what to avoid, so
        // "no stairs" keeps the tag and only "stairs are fine" or "don't mind stairs" drops it.
        $match = static function (array $needles, string $kind) use ($clauses, $negation, $noProblem): bool {
            foreach ($clauses as $clause) {
                foreach ($needles as $needle) {
                    if (!preg_match('/\b' . $needle . '\b/u', $clause, $m, PREG_OFFSET_CAPTURE)) continue;
                    if (preg_match($noProblem, $clause)) continue;
                    if ($kind === 'need' && preg_match($negation, substr($clause, 0, (int)$m[0][1]))) continue;
                    return true;
                }
            }
            return false;
        };

        if ($match(['elderly', 'seniors?', 'old (?:people|parents?|folks?)', 'aged parents?', 'grand(?:mother|father|parents?)'], 'need')) $tags[] = 'elderly';
        if ($match(['wheel ?chairs?', 'disabled', 'oku'], 'need')) $tags[] = 'wheelchair';
        if ($match(['avoid stairs?', 'no stairs?', 'less stairs?', 'stairs?'], 'avoid')) $tags[] = 'avoid_stairs';
        if ($match(['walk less', 'less walking', 'low walking', 'cannot walk', 'can\'?t walk', 'limited walking', 'walking difficult(?:y|ies)'], 'need')) $tags[] = 'low_walking';
        if ($match(['indoors?', 'shopping malls?', 'museums?'], 'need')) $tags[] = 'indoor_preferred';
        if ($match(['avoid (?:the )?heat', 'too hot', 'hot weather', 'heat', 'sun'], 'avoid')) $tags[] = 'avoid_heat';
        if ($match(['family', 'kids', 'children', 'child', 'toddlers?', 'babies', 'baby'], 'need')) $tags[] = 'family_friendly';

        if (strtolower(trim($travellerType)) === 'family') $tags[] = 'family_friendly';
        if (in_array('elderly', $tags, true) || in_array('wheelchair', $tags, true) || in_array('avoid_stairs', $tags, true)) {
            $tags[] = 'low_walking';
            $tags[] = 'rest_buffer';
        }
        if (in_array('wheelchair', $tags, true)) $tags[] = 'step_free';
        if (in_array('avoid_heat', $tags, true)) $tags[] = 'indoor_preferred';

        return self::normalizeTags($tags);
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

    private function containsChinese(string $text): bool
    {
        return (bool) preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text);
    }

    private static function normalizeTags($tags): array
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
