<?php
// services/AiTravelAssistantService.php
// Local Ollama assistant for itinerary explanation, route writing, and improvements.

class AiTravelAssistantService
{
    private string $model;
    private string $baseUrl;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model = trim((string)($model ?: 'qwen2.5:3b'));
        $this->baseUrl = rtrim(trim((string)($baseUrl ?: 'http://localhost:11434')), '/');
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

        $instructions = implode("\n", [
            "You are a local AI travel assistant inside a Malaysian cultural tourism itinerary system.",
            "Use the supplied itinerary context as the source of truth, but respond like a helpful travel chat assistant instead of repeating a fixed template.",
            "Help users clarify trip date, hotel needs, weather concerns, route changes, budget limits, accessibility needs, food preferences, and itinerary improvements.",
            "When a user gives details that need saving, explain that the system will require a confirmation button before saving.",
            "Do not claim bookings, hotels, dates, or itinerary changes are confirmed unless the system says they were saved.",
            "Do not invent exact live traffic, booking prices, or opening hours. If data is missing, say it is estimated.",
            "Do not mention states or places outside the supplied itinerary/preference context unless the user explicitly asks for them.",
            "If the user rejects a state or place, do not suggest it again.",
            "Use plain text only. Do not use Markdown symbols such as **, ###, or bullet decoration.",
            "Reply in the same language as the user when possible. Be concise, practical, and ask one follow-up question only when needed.",
        ]);

        $input = "Itinerary context:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nTraveller question:\n" . $question;

        $url = str_ends_with($this->baseUrl, '/api') ? $this->baseUrl . '/chat' : $this->baseUrl . '/api/chat';
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            'stream' => false,
            'options' => ['temperature' => 0.45],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
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

    private function cleanAnswer(string $text): string
    {
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/u', '$1', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s*/m', '', $text) ?? $text;
        $text = preg_replace('/^\s*[-*]\s+/m', '- ', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        return trim($text);
    }
}
