<?php

/**
 * services/AiAdminReportAnalysisService.php
 *
 * AI analysis for admin reports. Uses Gemini first when configured, then local
 * Ollama, and finally deterministic local insights when AI is unavailable.
 */
class AiAdminReportAnalysisService
{
    private string $model;
    private string $baseUrl;
    private bool $fastMode;
    private int $timeout;
    private int $connectTimeout;
    private int $numPredict;
    private int $numCtx;
    private string $keepAlive;

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->model = trim((string)($model ?: (getenv('ADMIN_AI_MODEL') ?: 'qwen2.5:3b')));
        $this->baseUrl = rtrim(trim((string)($baseUrl ?: (getenv('OLLAMA_BASE_URL') ?: 'http://127.0.0.1:11434'))), '/');
        $fast = strtolower(trim((string)(getenv('ADMIN_AI_FAST_MODE') ?: 'false')));
        $this->fastMode = in_array($fast, ['1', 'true', 'yes', 'on'], true);
        $this->timeout = max(3, (int)(getenv('ADMIN_AI_TIMEOUT') ?: 25));
        $this->connectTimeout = max(1, (int)(getenv('ADMIN_AI_CONNECT_TIMEOUT') ?: 2));
        $this->numPredict = max(60, min(220, (int)(getenv('ADMIN_AI_NUM_PREDICT') ?: 110)));
        $this->numCtx = max(256, min(2048, (int)(getenv('ADMIN_AI_NUM_CTX') ?: (defined('OLLAMA_NUM_CTX') ? OLLAMA_NUM_CTX : 512))));
        $this->keepAlive = trim((string)(getenv('ADMIN_AI_KEEP_ALIVE') ?: (getenv('OLLAMA_KEEP_ALIVE') ?: '-1')));
        if ($this->keepAlive === '') $this->keepAlive = '-1';
    }

    public function analyze(array $reportData): array
    {
        if ($this->fastMode) {
            return [
                'status' => 'success',
                'source' => 'fast_local_report',
                'analysis' => $this->fallbackAnalysis($reportData),
            ];
        }

        $gemini = $this->callGemini($reportData);
        if (($gemini['status'] ?? '') === 'success') return $gemini;
        if (($gemini['message'] ?? '') !== '') {
            error_log('Gemini admin report fallback to Ollama: ' . $gemini['message']);
        }

        $ai = $this->callOllama($reportData);
        if (($ai['status'] ?? '') === 'success') return $ai;

        return [
            'status' => 'success',
            'source' => 'local_fallback',
            'analysis' => $this->fallbackAnalysis($reportData),
        ];
    }

    private function callGemini(array $reportData): array
    {
        $apiKey = defined('GEMINI_API_KEY') ? trim((string)GEMINI_API_KEY) : '';
        if ($apiKey === '') {
            return ['status' => 'skipped', 'source' => 'gemini', 'message' => 'Gemini API key is not configured.'];
        }
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'source' => 'gemini', 'message' => 'cURL is not enabled.'];
        }

        $compactData = $this->compactReportData($reportData);
        $reportType = (string)($compactData['report_type'] ?? 'overview');
        $typeInstructions = $this->reportTypeInstructions($reportType);
        $instructions = implode("\n", [
            "You are an admin analytics assistant for a Malaysian cultural tourism itinerary system.",
            "Analyze only the selected report type: {$reportType}.",
            "Use only the KPI and section rows provided in the compact JSON snapshot. Do not invent missing data.",
            "Do not analyze unrelated modules or report types. For example, only discuss AI usage when report_type is ai_usage.",
            "Follow this exact analysis focus and headings:",
            $typeInstructions,
            "Mention concrete numbers from the selected report. Keep it concise for a final year project admin report.",
            "Do not use Markdown heading symbols such as #, ##, **, or ***. Write plain report text.",
        ]);

        $model = defined('GEMINI_MODEL') && trim((string)GEMINI_MODEL) !== ''
            ? trim((string)GEMINI_MODEL)
            : 'gemini-2.5-flash';
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $instructions]],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => "Compact admin report database snapshot:\n" . json_encode($compactData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => $this->numPredict,
            ],
        ];

        $ch = curl_init($url);
        if (!$ch) {
            return ['status' => 'error', 'source' => 'gemini', 'message' => 'Gemini request could not be prepared.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '') {
            return ['status' => 'error', 'source' => 'gemini', 'message' => 'Gemini request failed: ' . $err];
        }

        $json = json_decode((string)$raw, true);
        if ($code < 200 || $code >= 300) {
            $message = is_array($json) ? (string)($json['error']['message'] ?? 'Gemini HTTP error ' . $code) : 'Gemini HTTP error ' . $code;
            return ['status' => 'error', 'source' => 'gemini', 'message' => $message];
        }
        if (!is_array($json)) {
            return ['status' => 'error', 'source' => 'gemini', 'message' => 'Invalid Gemini response.'];
        }

        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $text .= (string)($part['text'] ?? '');
            }
        }
        $text = trim($text);
        if ($text === '') {
            return ['status' => 'error', 'source' => 'gemini', 'message' => 'Gemini returned an empty response.'];
        }

        return ['status' => 'success', 'source' => 'gemini', 'analysis' => $text];
    }

    private function callOllama(array $reportData): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'cURL is not enabled.'];
        }

        $compactData = $this->compactReportData($reportData);
        $reportType = (string)($compactData['report_type'] ?? 'overview');
        $typeInstructions = $this->reportTypeInstructions($reportType);
        $instructions = implode("\n", [
            "You are an admin analytics assistant for a Malaysian cultural tourism itinerary system.",
            "Analyze only the selected report type: {$reportType}.",
            "Use only the KPI and section rows provided in the compact JSON snapshot. Do not invent missing data.",
            "Do not analyze unrelated modules or report types. For example, only discuss AI usage when report_type is ai_usage.",
            "Follow this exact analysis focus and headings:",
            $typeInstructions,
            "Mention concrete numbers from the selected report. Keep it concise for a final year project admin report.",
            "Do not use Markdown heading symbols such as #, ##, **, or ***. Write plain report text.",
        ]);

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "Compact admin report database snapshot:\n" . json_encode($compactData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'stream' => false,
            'keep_alive' => $this->keepAlive,
            'options' => [
                'temperature' => 0.2,
                'num_ctx' => $this->numCtx,
                'num_predict' => $this->numPredict,
                'num_thread' => 2,
            ],
        ];

        $url = str_ends_with($this->baseUrl, '/api') ? $this->baseUrl . '/chat' : $this->baseUrl . '/api/chat';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
            error_log('Ollama admin report request failed: HTTP ' . $code . ' ' . $err . ' | URL: ' . $url);
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'AI service unavailable.'];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'Invalid AI response.'];
        }

        $text = trim((string)($json['message']['content'] ?? ''));
        if ($text === '') {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'AI returned an empty response.'];
        }

        return ['status' => 'success', 'source' => 'ollama', 'analysis' => $text];
    }

    private function compactReportData(array $reportData): array
    {
        $compact = [
            'report_type' => (string)($reportData['report_type'] ?? 'overview'),
            'period' => (string)($reportData['period'] ?? ''),
            'kpis' => array_slice(is_array($reportData['kpis'] ?? null) ? $reportData['kpis'] : [], 0, 6),
            'sections' => [],
        ];

        $sections = is_array($reportData['sections'] ?? null) ? $reportData['sections'] : [];
        foreach (array_slice($sections, 0, 6) as $section) {
            if (!is_array($section)) continue;
            $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
            $compact['sections'][] = [
                'title' => (string)($section['title'] ?? 'Section'),
                'note' => (string)($section['note'] ?? ''),
                'headers' => array_slice(is_array($section['headers'] ?? null) ? $section['headers'] : [], 0, 6),
                'rows' => array_slice($rows, 0, 5),
            ];
        }

        return $compact;
    }

    private function reportTypeInstructions(string $reportType): string
    {
        return match ($reportType) {
            'user_preferences' => implode("\n", [
                "1. Preference Summary",
                "2. Highest and Lowest User Preferences",
                "3. Budget, Duration, and Transport Pattern",
                "4. Admin Actions for Personalization",
            ]),
            'destination_demand' => implode("\n", [
                "1. Destination Demand Summary",
                "2. Desired States and Districts",
                "3. Actual Generated Destination Pattern",
                "4. Admin Actions for Destination Coverage",
            ]),
            'attraction_price' => implode("\n", [
                "1. Cultural Place and Price Summary",
                "2. Category and Price Coverage",
                "3. Data Completeness Issues",
                "4. Admin Actions for Knowledge Base Quality",
            ]),
            'cost_budget' => implode("\n", [
                "1. Cost and Budget Summary",
                "2. Over-Budget and Within-Budget Pattern",
                "3. Hotel and Trip Cost Observations",
                "4. Admin Actions for Cost Accuracy",
            ]),
            'ai_usage' => implode("\n", [
                "1. AI Usage Summary",
                "2. Question Intent Pattern",
                "3. Most Active AI Users",
                "4. Admin Actions for AI Assistant Improvement",
            ]),
            default => implode("\n", [
                "1. System Overview Summary",
                "2. User, Trip, and Cultural Data Status",
                "3. Operational Gaps Shown by the Report",
                "4. Admin Actions for System Readiness",
            ]),
        };
    }

    private function fallbackAnalysis(array $data): string
    {
        if (isset($data['sections']) && is_array($data['sections'])) {
            return $this->fallbackSectionReportAnalysis($data);
        }

        return $this->fallbackSectionReportAnalysis([
            'report_type' => (string)($data['report_type'] ?? 'overview'),
            'kpis' => [],
            'sections' => [
                [
                    'title' => 'Available Data Snapshot',
                    'headers' => ['Metric', 'Value'],
                    'rows' => $this->flattenFallbackRows($data),
                ],
            ],
        ]);
    }

    private function flattenFallbackRows(array $data): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) continue;
            $rows[] = ['Metric' => str_replace('_', ' ', (string)$key), 'Value' => (string)$value];
            if (count($rows) >= 12) break;
        }
        return $rows;
    }

    private function fallbackSectionReportAnalysis(array $data): string
    {
        $reportType = (string)($data['report_type'] ?? 'overview');
        $type = str_replace('_', ' ', $reportType);
        $kpis = $data['kpis'] ?? [];
        $sections = $data['sections'] ?? [];
        $headings = $this->fallbackHeadings($reportType);

        $lines = [];
        $lines[] = $headings[0];
        $lines[] = "- This {$type} report was generated from the connected system database for the selected period.";
        foreach (array_slice($kpis, 0, 4) as $kpi) {
            $label = (string)($kpi['label'] ?? 'Metric');
            $value = (string)($kpi['value'] ?? '-');
            $note = trim((string)($kpi['note'] ?? ''));
            $lines[] = "- {$label}: {$value}" . ($note !== '' ? " ({$note})" : "") . ".";
        }

        $lines[] = "";
        $lines[] = $headings[1];
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
        $lines[] = $headings[2];
        if (strpos($reportType, 'preference') !== false) {
            $lines[] = "- Use highest preference items to prioritize itinerary content and use lowest preference items to identify weak demand or content gaps.";
            $lines[] = "- Add more cultural places for high-demand states, districts, and interests so generated itineraries have enough variety.";
        } elseif ($reportType === 'destination_demand') {
            $lines[] = "- Compare desired destinations with actual generated route destinations to detect where the generator lacks enough place data.";
            $lines[] = "- Increase cultural records for high-demand states and districts that appear weak in generated itinerary output.";
        } elseif ($reportType === 'attraction_price') {
            $lines[] = "- Review very high and very low attraction prices for data accuracy.";
            $lines[] = "- Complete missing image, coordinate, opening hour, and visit duration fields to improve itinerary quality.";
        } elseif ($reportType === 'cost_budget') {
            $lines[] = "- Monitor over-budget trips and adjust hotel, food, and transport assumptions when needed.";
            $lines[] = "- Use highest-cost itinerary records to inspect whether the cost estimation logic is realistic.";
        } elseif ($reportType === 'ai_usage') {
            $lines[] = "- Use common AI question categories to improve itinerary explanations and route-writing prompts.";
            $lines[] = "- Test the assistant before final demonstration and make sure itinerary answers are specific to the selected trip.";
        } else {
            $lines[] = "- Keep report exports as evidence for supervisor evaluation and final project documentation.";
            $lines[] = "- Review sections with no data and either add data collection or hide the feature from final demo.";
        }

        return implode("\n", $lines);
    }

    private function fallbackHeadings(string $reportType): array
    {
        return match ($reportType) {
            'user_preferences' => ['Preference Summary', 'Preference Findings', 'Admin Actions for Personalization'],
            'destination_demand' => ['Destination Demand Summary', 'Destination Findings', 'Admin Actions for Destination Coverage'],
            'attraction_price' => ['Cultural Place and Price Summary', 'Price and Data Quality Findings', 'Admin Actions for Knowledge Base Quality'],
            'cost_budget' => ['Cost and Budget Summary', 'Cost Findings', 'Admin Actions for Cost Accuracy'],
            'ai_usage' => ['AI Usage Summary', 'AI Usage Findings', 'Admin Actions for AI Assistant Improvement'],
            default => ['System Overview Summary', 'System Findings', 'Admin Actions for System Readiness'],
        };
    }
}
