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
            "Use only the itinerary context provided by the system.",
            "Help users write routes, check costs, explain cultural value, and suggest practical improvements.",
            "Do not claim bookings are confirmed. Do not invent exact live traffic, prices, or opening hours.",
            "If data is missing, say it is estimated and suggest checking the map or place details.",
            "Reply in the same language as the user when possible. Keep the answer concise and useful.",
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
        if ($text === '') {
            return ['status' => 'error', 'answer' => 'AI response is invalid. Please try again.', 'source' => 'ollama'];
        }

        return ['status' => 'success', 'answer' => $text, 'source' => 'ollama'];
    }
}
