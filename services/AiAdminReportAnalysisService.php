<?php
/**
 * services/AiAdminReportAnalysisService.php
 *
 * AI analysis for admin reports. Uses OpenAI when configured and falls back to
 * deterministic local insights so the report still works during offline demos.
 */

class AiAdminReportAnalysisService
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = trim((string)$apiKey);
        $this->model = trim((string)($model ?: 'gpt-4.1-mini'));
    }

    public function analyze(array $reportData): array
    {
        if ($this->apiKey !== '') {
            $ai = $this->callOpenAI($reportData);
            if (($ai['status'] ?? '') === 'success') return $ai;
        }

        return [
            'status' => 'success',
            'source' => 'local_fallback',
            'analysis' => $this->fallbackAnalysis($reportData),
        ];
    }

    private function callOpenAI(array $reportData): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'source' => 'openai', 'analysis' => 'cURL is not enabled.'];
        }

        $instructions = implode("\n", [
            "You are an admin analytics assistant for a Malaysian cultural tourism itinerary system.",
            "Analyze only the database metrics provided. Do not invent missing data.",
            "Write a concise but detailed management report with these headings:",
            "1. Executive Summary",
            "2. User Engagement",
            "3. Itinerary and Cost Insights",
            "4. Cultural Content Insights",
            "5. AI Feature Usage",
            "6. Recommended Admin Actions",
            "Mention concrete numbers from the data. Use practical wording suitable for a final year project report.",
        ]);

        $payload = [
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => "Admin report database snapshot:\n" . json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'max_output_tokens' => 1000,
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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
            return ['status' => 'error', 'source' => 'openai', 'analysis' => 'AI service unavailable.'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['status' => 'error', 'source' => 'openai', 'analysis' => 'Invalid AI response.'];
        }

        $text = $this->extractResponseText($json);
        if ($text === '') {
            return ['status' => 'error', 'source' => 'openai', 'analysis' => 'AI returned an empty response.'];
        }

        return ['status' => 'success', 'source' => 'openai', 'analysis' => $text];
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

    private function fallbackAnalysis(array $data): string
    {
        if (isset($data['sections']) && is_array($data['sections'])) {
            return $this->fallbackSectionReportAnalysis($data);
        }

        $summary = $data['summary'] ?? [];
        $popularStates = $data['popular_states'] ?? [];
        $popularPlaces = $data['popular_places'] ?? [];
        $transportStats = $data['transport_stats'] ?? [];
        $interestStats = $data['interest_stats'] ?? [];

        $travellers = (int)($summary['travellers_total'] ?? 0);
        $trips = (int)($summary['itineraries'] ?? 0);
        $activePlaces = (int)($summary['active_cultural_places'] ?? 0);
        $avgCost = number_format((float)($summary['avg_trip_cost'] ?? 0), 2);
        $aiQuestions = (int)($summary['ai_questions'] ?? 0);

        $topState = $popularStates[0]['state'] ?? 'No state data yet';
        $topStateCount = (int)($popularStates[0]['total_items'] ?? 0);
        $topPlace = $popularPlaces[0]['name'] ?? 'No place data yet';
        $topTransport = $transportStats[0]['transport_type'] ?? 'No transport data yet';
        $topInterest = $interestStats[0]['interest'] ?? 'No interest data yet';

        $lines = [];
        $lines[] = "Executive Summary";
        $lines[] = "- The system currently has {$travellers} registered traveller(s), {$activePlaces} active cultural record(s), and {$trips} generated itinerary record(s).";
        $lines[] = "- Average estimated trip cost is RM {$avgCost}. This figure helps admin monitor whether generated itineraries match common traveller budgets.";
        $lines[] = "";
        $lines[] = "User Engagement";
        $lines[] = "- Top traveller and preference tables show which users are actively generating trips and what trip settings they use most often.";
        $lines[] = "- Most common transport preference: {$topTransport}. Most common interest: {$topInterest}.";
        $lines[] = "";
        $lines[] = "Itinerary and Cost Insights";
        $lines[] = "- The most frequently used state is {$topState} with {$topStateCount} itinerary item(s).";
        $lines[] = "- The most frequently selected cultural place is {$topPlace}. Admin can use this to identify high-demand tourism content.";
        $lines[] = "";
        $lines[] = "Cultural Content Insights";
        $lines[] = "- Category and popular-place lists show which cultural records are useful for route generation and which categories may need more data.";
        $lines[] = "- If some categories have low counts, admin should add more verified places to improve recommendation variety.";
        $lines[] = "";
        $lines[] = "AI Feature Usage";
        $lines[] = "- Travellers asked {$aiQuestions} AI assistant question(s). This provides evidence that the system includes an AI-supported itinerary explanation and route-writing feature.";
        $lines[] = "";
        $lines[] = "Recommended Admin Actions";
        $lines[] = "- Add more cultural places for low-coverage states and categories.";
        $lines[] = "- Review high-cost itineraries to ensure hotel and transport costs are realistic.";
        $lines[] = "- Use AI chat logs to identify common traveller questions and improve itinerary explanations.";

        return implode("\n", $lines);
    }

    private function fallbackSectionReportAnalysis(array $data): string
    {
        $type = str_replace('_', ' ', (string)($data['report_type'] ?? 'admin'));
        $kpis = $data['kpis'] ?? [];
        $sections = $data['sections'] ?? [];

        $lines = [];
        $lines[] = "Executive Summary";
        $lines[] = "- This {$type} report was generated from the connected system database for the selected period.";
        foreach (array_slice($kpis, 0, 4) as $kpi) {
            $label = (string)($kpi['label'] ?? 'Metric');
            $value = (string)($kpi['value'] ?? '-');
            $note = trim((string)($kpi['note'] ?? ''));
            $lines[] = "- {$label}: {$value}" . ($note !== '' ? " ({$note})" : "") . ".";
        }

        $lines[] = "";
        $lines[] = "Key Findings";
        $findingCount = 0;
        foreach ($sections as $section) {
            $title = (string)($section['title'] ?? 'Section');
            $rows = $section['rows'] ?? [];
            if (!is_array($rows) || empty($rows)) {
                $lines[] = "- {$title}: no data available for this period.";
                $findingCount++;
                continue;
            }
            $first = $rows[0];
            if (!is_array($first)) continue;
            $pairs = [];
            foreach ($first as $key => $value) {
                $pairs[] = $key . " = " . $value;
                if (count($pairs) >= 3) break;
            }
            $lines[] = "- {$title}: leading record shows " . implode(", ", $pairs) . ".";
            $findingCount++;
            if ($findingCount >= 8) break;
        }

        $lines[] = "";
        $lines[] = "Recommended Admin Actions";
        if (strpos((string)($data['report_type'] ?? ''), 'preference') !== false) {
            $lines[] = "- Use highest preference items to prioritize itinerary content and use lowest preference items to identify weak demand or content gaps.";
            $lines[] = "- Add more cultural places for high-demand states, districts, and interests so generated itineraries have enough variety.";
        } elseif (($data['report_type'] ?? '') === 'destination_demand') {
            $lines[] = "- Compare desired destinations with actual generated route destinations to detect where the generator lacks enough place data.";
            $lines[] = "- Increase cultural records for high-demand states and districts that appear weak in generated itinerary output.";
        } elseif (($data['report_type'] ?? '') === 'attraction_price') {
            $lines[] = "- Review very high and very low attraction prices for data accuracy.";
            $lines[] = "- Complete missing image, coordinate, opening hour, and visit duration fields to improve itinerary quality.";
        } elseif (($data['report_type'] ?? '') === 'cost_budget') {
            $lines[] = "- Monitor over-budget trips and adjust hotel, food, and transport assumptions when needed.";
            $lines[] = "- Use highest-cost itinerary records to inspect whether the cost estimation logic is realistic.";
        } elseif (($data['report_type'] ?? '') === 'ai_usage') {
            $lines[] = "- Use common AI question categories to improve itinerary explanations and route-writing prompts.";
            $lines[] = "- If most answers are local fallback, configure an OpenAI API key before final demonstration.";
        } else {
            $lines[] = "- Keep report exports as evidence for supervisor evaluation and final project documentation.";
            $lines[] = "- Review sections with no data and either add data collection or hide the feature from final demo.";
        }

        return implode("\n", $lines);
    }
}
