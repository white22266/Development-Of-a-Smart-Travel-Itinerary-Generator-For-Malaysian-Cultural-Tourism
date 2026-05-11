<?php
/**
 * services/AiTravelAssistantService.php
 *
 * AI assistant for itinerary explanation, route writing, and improvement advice.
 * Uses OpenAI Responses API when OPENAI_API_KEY is configured, with a deterministic
 * local fallback so the feature remains usable during offline demos.
 */

class AiTravelAssistantService
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = trim((string)$apiKey);
        $this->model = trim((string)($model ?: 'gpt-4.1-mini'));
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

        if ($this->apiKey !== '') {
            $ai = $this->callOpenAI($question, $context);
            if ($ai['status'] === 'success') return $ai;
        }

        return [
            'status' => 'success',
            'answer' => $this->fallbackAnswer($question, $context),
            'source' => 'local_fallback',
        ];
    }

    private function callOpenAI(string $question, array $context): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'answer' => 'cURL is not enabled.', 'source' => 'openai'];
        }

        $instructions = implode("\n", [
            "You are an AI travel assistant inside a Malaysian cultural tourism itinerary system.",
            "Help the traveller understand, improve, and write route instructions for the itinerary.",
            "Use only the itinerary context provided. Do not invent places, prices, or transport data.",
            "If route data is missing, say it is estimated and suggest checking the map.",
            "Keep answers concise, practical, and formatted with short bullets when useful.",
            "You may answer in English, Malay, or Chinese depending on the user's language.",
        ]);

        $input = "Itinerary context:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nTraveller question:\n" . $question;

        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => 700,
        ];

        $ch = curl_init('https://api.openai.com/v1/responses');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
            return ['status' => 'error', 'answer' => 'AI service unavailable.', 'source' => 'openai'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['status' => 'error', 'answer' => 'Invalid AI response.', 'source' => 'openai'];
        }

        $text = $this->extractResponseText($json);
        if ($text === '') {
            return ['status' => 'error', 'answer' => 'AI returned an empty answer.', 'source' => 'openai'];
        }

        return ['status' => 'success', 'answer' => $text, 'source' => 'openai'];
    }

    private function extractResponseText(array $json): string
    {
        if (!empty($json['output_text']) && is_string($json['output_text'])) {
            return trim($json['output_text']);
        }

        $parts = [];
        foreach (($json['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                    $parts[] = (string)$content['text'];
                }
            }
        }
        return trim(implode("\n", $parts));
    }

    private function fallbackAnswer(string $question, array $context): string
    {
        $q = strtolower($question);
        $days = $context['days'] ?? [];
        $title = $context['title'] ?? 'this itinerary';
        $transport = str_replace('_', ' ', (string)($context['transport_type'] ?? 'car'));

        if (strpos($q, 'route') !== false || strpos($q, '路线') !== false || strpos($q, '安排') !== false || strpos($q, 'write') !== false) {
            return $this->writeRouteSummary($title, $transport, $days);
        }

        if (strpos($q, 'cost') !== false || strpos($q, 'budget') !== false || strpos($q, '费用') !== false || strpos($q, '花费') !== false) {
            return $this->writeCostSummary($context);
        }

        if (strpos($q, 'improve') !== false || strpos($q, '优化') !== false || strpos($q, 'suggest') !== false) {
            return $this->writeImprovementSummary($days, $transport);
        }

        return $this->writeGeneralSummary($title, $transport, $days);
    }

    private function writeRouteSummary(string $title, string $transport, array $days): string
    {
        $lines = ["Route writing for {$title} using {$transport}:"];
        foreach ($days as $dayNo => $items) {
            $names = array_map(fn($x) => (string)($x['title'] ?? ''), $items);
            $names = array_values(array_filter($names));
            if (empty($names)) {
                $lines[] = "Day {$dayNo}: No scheduled places.";
                continue;
            }
            $lines[] = "Day {$dayNo}: " . implode(" -> ", $names) . ".";
        }
        $lines[] = "Use the map route panel for exact live directions and traffic/transit updates.";
        return implode("\n", $lines);
    }

    private function writeCostSummary(array $context): string
    {
        $total = number_format((float)($context['total_estimated_cost'] ?? 0), 2);
        $budget = number_format((float)($context['budget'] ?? 0), 2);
        return "Estimated total cost is RM {$total}. Traveller budget is RM {$budget}. Hotel cost is included only when a hotel was selected during review. For exact details, open Trip Summary.";
    }

    private function writeImprovementSummary(array $days, string $transport): string
    {
        $suggestions = ["Improvement suggestions:"];
        foreach ($days as $dayNo => $items) {
            if (count($items) > 4) {
                $suggestions[] = "Day {$dayNo}: Consider reducing stops because it may feel rushed.";
            } elseif (count($items) === 0) {
                $suggestions[] = "Day {$dayNo}: Add at least one cultural place or food stop.";
            }
        }
        if ($transport === 'walking') {
            $suggestions[] = "Walking mode should keep places close together; check long-distance days carefully.";
        }
        if (count($suggestions) === 1) {
            $suggestions[] = "The itinerary looks balanced. Check opening hours and live traffic before travel.";
        }
        return implode("\n", $suggestions);
    }

    private function writeGeneralSummary(string $title, string $transport, array $days): string
    {
        $placeCount = 0;
        foreach ($days as $items) $placeCount += count($items);
        return "{$title} contains {$placeCount} scheduled stop(s) across " . count($days) . " day(s), using {$transport}. Ask me to write the route, explain cultural value, check budget, or suggest improvements.";
    }
}
