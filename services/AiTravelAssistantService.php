<?php
// services/AiTravelAssistantService.php
// Travel assistant answers for the itinerary pages. Short fixed messages (greetings,
// thanks, "what can you do") are answered by rules; everything else goes to the local
// Ollama model with the itinerary as context. The model only writes text: it never
// changes saved data, and its reply is never parsed into actions.
// Questions may be in English, Malay or Chinese; the answer comes back in the same language.

require_once __DIR__ . '/OllamaClient.php';
require_once __DIR__ . '/../config/ai_language_guard.php';

class AiTravelAssistantService
{
    private const BASE_RULES = [
        'You are the travel assistant of a Malaysian cultural tourism itinerary planner.',
        'Use only the itinerary context provided. It is the source of truth.',
        'All money is in Malaysian Ringgit: write amounts as "RM 12.50", never with "$".',
        'In the context, distance_km is a distance in kilometres and travel_time_min is a travel time in minutes. Do not mix them up.',
        'Answer the traveller\'s actual question directly and practically. Do not repeat the whole itinerary unless asked for a summary.',
        'Keep replies short (about 3 to 6 sentences) unless the traveller asks for more detail.',
        'Do not invent live traffic, live prices, opening hours or bookings. If information is missing, say it is not provided or is an estimate.',
        'Never say that a date, starting location, hotel or place has been saved or changed. Changes are only saved after the traveller presses a confirmation button.',
        'You cannot change the itinerary yourself, so never promise to adjust, add, remove or replan anything. When the traveller wants the stops changed, tell them to say the day and the change in one sentence, for example "Day 2: fewer temples, more museums" or "remove the second stop of day 1", or to use the buttons on the itinerary page.',
        'Plain text only. No Markdown.',
    ];

    // Context keys that only add noise or must never be sent to the model.
    private const SKIP_KEYS = '/image|photo|thumbnail|video|created_at|updated_at|password|token|secret|api_key|latitude|longitude|_lat$|_lng$/i';

    private OllamaClient $ollama;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->ollama = new OllamaClient($model, $baseUrl);
    }

    /**
     * @param array $context Itinerary data. An optional 'assistant_rules' list adds page-specific
     *                       rules to the system prompt.
     * @param string|null $lang 'en', 'ms' or 'zh'; null = detect it from the question.
     * @return array{status: string, answer: string, source: string, lang: string}
     *   'answer' is always safe to show to the traveller, including when status is 'error'.
     */
    public function answer(string $question, array $context, ?string $lang = null): array
    {
        $question = trim($question);
        $lang = $lang ?? ai_detect_language($question);

        if ($question === '') {
            return ['status' => 'error', 'answer' => ai_text('empty_question', $lang), 'source' => 'validation', 'lang' => $lang];
        }

        $quick = $this->quickReply($question, $lang);
        if ($quick !== null) {
            return ['status' => 'success', 'answer' => $quick, 'source' => 'rule', 'lang' => $lang];
        }

        $pageRules = $context['assistant_rules'] ?? [];
        unset($context['assistant_rules']);
        $instructions = implode("\n", array_merge(
            self::BASE_RULES,
            is_array($pageRules) ? $pageRules : [],
            [ai_reply_language_rule($lang)]
        ));

        $input = "Itinerary context (JSON):\n"
            . json_encode($this->contextForPrompt($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nTraveller question:\n"
            . $this->truncate($question, 700);

        // Chinese needs more tokens for the same answer.
        $reply = $this->ollama->chat($instructions, $input, ['num_predict' => $lang === 'zh' ? 420 : 320, 'temperature' => 0.2]);
        if (!$reply['ok']) {
            error_log('AiTravelAssistantService: ' . $reply['error']);
            return ['status' => 'error', 'answer' => ai_text($reply['timed_out'] ? 'model_timeout' : 'model_unavailable', $lang), 'source' => 'ollama', 'lang' => $lang];
        }

        $text = $this->cleanAnswer($reply['text'], $reply['done_reason'] === 'length');
        if ($text === '') {
            return ['status' => 'error', 'answer' => ai_text('model_invalid', $lang), 'source' => 'ollama', 'lang' => $lang];
        }

        return ['status' => 'success', 'answer' => $text, 'source' => 'ollama', 'lang' => $lang];
    }

    /**
     * Fixed replies for whole short messages only. Anything longer goes to the model, so a
     * request such as "help me with a cheaper hotel" is never swallowed by a rule.
     */
    private function quickReply(string $question, string $lang): ?string
    {
        // Byte-wise trim() would cut Chinese characters: strip spaces and punctuation by regex.
        $trimmed = preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $question) ?? $question;
        if (preg_match('/^(?:你好|您好|嗨|哈喽|早上好|下午好|晚上好)$/u', $trimmed)) {
            return ai_text('quick_hello', $lang);
        }
        if (preg_match('/^(?:谢谢|谢谢你|多谢|好的|好|OK|ok)$/u', $trimmed)) {
            return ai_text('quick_thanks', $lang);
        }
        if (preg_match('/^(?:帮助|你能做什么|你可以做什么|你会做什么)$/u', $trimmed)) {
            return ai_text('quick_help', $lang);
        }

        $normalized = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', ' ', $question) ?? $question));
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);

        if (preg_match('/^(?:h+i+|hello+|hey+|yo|hai|helo|good (?:morning|afternoon|evening)|selamat (?:pagi|petang|tengah hari|malam)|salam)(?: there)?$/', $normalized)) {
            return ai_text('quick_hello', $lang);
        }
        if (preg_match('/^(?:ok|okay|thanks|thank you|thank you very much|tq|thx|ty|terima kasih|terima kasih banyak|baik)$/', $normalized)) {
            return ai_text('quick_thanks', $lang);
        }
        if (preg_match('/^(?:then|next|continue|more|go on|and then|what next|so|lepas tu|seterusnya|lagi)$/', $normalized)) {
            return ai_text('quick_next', $lang);
        }
        if (preg_match('/^(?:help|menu|options|tolong|bantuan)$/', $normalized)
            || preg_match('/^(?:what (?:can|could) (?:you|u) do|what do you do|how (?:can|could) you help(?: me)?|what are your (?:features|functions|capabilities)|apa yang (?:awak|anda|kamu) boleh buat|apa (?:awak|anda|kamu) boleh buat)$/', $normalized)) {
            return ai_text('quick_help', $lang);
        }

        return null;
    }

    /**
     * Keep the useful itinerary data and drop noise, so the prompt fits the model's context.
     * Falls back to a tighter trim for very long trips.
     */
    private function contextForPrompt(array $context): array
    {
        $full = $this->trimForPrompt($context, 0, 240, 40);
        $json = json_encode($full, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false && mb_strlen($json, 'UTF-8') <= 9000) {
            return $full;
        }
        return $this->trimForPrompt($context, 0, 100, 25);
    }

    private function trimForPrompt($value, int $depth, int $maxString, int $maxItems)
    {
        if ($depth > 5) return null;
        if (is_string($value)) return $this->truncate($value, $maxString);
        if (!is_array($value)) return (is_scalar($value) || $value === null) ? $value : null;

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= $maxItems) break;
            if (is_string($key) && preg_match(self::SKIP_KEYS, $key)) continue;
            if ($item === null || $item === '') continue;
            $result[$key] = $this->trimForPrompt($item, $depth + 1, $maxString, $maxItems);
            $count++;
        }
        return $result;
    }

    private function cleanAnswer(string $text, bool $wasCutOff): string
    {
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/u', '$1', $text) ?? $text;   // **bold**
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text;          // # headings
        $text = preg_replace('/^\s*[*]\s+/m', '- ', $text) ?? $text;               // * bullets
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text, 'UTF-8') > 1500) {
            $text = mb_substr($text, 0, 1500, 'UTF-8');
            $wasCutOff = true;
        }
        if ($wasCutOff) {
            $text = $this->clipToLastSentence($text);
        }
        return $text;
    }

    /**
     * Cut a reply that stopped mid-sentence back to its last complete sentence. A sentence ends
     * with . ! or ? followed by whitespace or the end, so "RM 12.50" is never split.
     */
    private function clipToLastSentence(string $text): string
    {
        if (!preg_match_all('/[.!?](?=\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return rtrim($text, " ,;:-") . '...';
        }
        $last = end($matches[0]);
        $clipped = trim(substr($text, 0, $last[1] + 1));
        return mb_strlen($clipped, 'UTF-8') >= 40 ? $clipped : rtrim($text, " ,;:-") . '...';
    }

    private function truncate(string $text, int $limit): string
    {
        $text = trim($text);
        if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
        return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '...';
    }
}
