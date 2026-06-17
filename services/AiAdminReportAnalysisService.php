<?php

/**
 * services/AiAdminReportAnalysisService.php
 *
 * AI analysis for admin reports. Uses local Ollama only and falls back
 * to deterministic local insights if Ollama is unavailable or exceeds the timeout.
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
        $this->timeout = max(3, (int)(getenv('ADMIN_AI_TIMEOUT') ?: 20));
        $this->connectTimeout = max(1, (int)(getenv('ADMIN_AI_CONNECT_TIMEOUT') ?: 2));

        // This value controls Ollama response length. The previous 220-token cap was too short
        // and caused outputs such as only "1. Preference Summary". Keep the report complete but concise.
        $this->numPredict = max(180, min(600, (int)(getenv('ADMIN_AI_NUM_PREDICT') ?: 350)));
        $this->numCtx = max(512, min(4096, (int)(getenv('ADMIN_AI_NUM_CTX') ?: (defined('OLLAMA_NUM_CTX') ? OLLAMA_NUM_CTX : 1024))));
        $this->keepAlive = trim((string)(getenv('ADMIN_AI_KEEP_ALIVE') ?: (getenv('OLLAMA_KEEP_ALIVE') ?: '30m')));
        if ($this->keepAlive === '' || $this->keepAlive === '-1') $this->keepAlive = '30m';
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

        $ai = $this->callOllama($reportData);

        if (($ai['status'] ?? '') === 'success') {
            $cleanAi = $this->cleanOllamaReportText((string)($ai['analysis'] ?? ''));

            return [
                'status' => 'success',
                'source' => 'ollama',
                'analysis' => $cleanAi,
            ];
        }

        error_log('Ollama admin report failed, using fallback: ' . (string)($ai['analysis'] ?? 'Unknown Ollama error.'));

        return [
            'status' => 'success',
            'source' => 'local_fallback',
            'analysis' => $this->fallbackAnalysis($reportData),
        ];
    }

    private function callOllama(array $reportData): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'cURL is not enabled in PHP. Enable extension=curl in php.ini and restart Apache.'];
        }

        $compactData = $this->compactReportData($reportData);
        $reportType = (string)($compactData['report_type'] ?? 'overview');
        $typeInstructions = $this->reportTypeInstructions($reportType);
        $instructions = implode("\n", [
            "You are an admin analytics assistant for a Malaysian cultural tourism itinerary system.",
            "Analyze only the selected report type: {$reportType}.",
            "Use only the KPI and section rows provided in the compact report snapshot. Do not invent missing data.",
            "Do not mention JSON, dataset, snapshot, prompt, or provided data.",
            "Do not write introductions such as 'Based on the data provided'.",
            "Do not write closing sentences such as 'If you need further analysis' or 'please provide more details'.",
            "Do not use tables. Do not use pipe characters. Do not use Markdown tables.",
            "Do not repeat the same finding in different sections.",
            "Output must follow this exact format only:",
            "Report Summary",
            "Write one short paragraph with 2 to 3 sentences. Mention the most important KPI numbers.",
            "Key Findings",
            "- Write one clear bullet point about the strongest pattern.",
            "- Write one clear bullet point about the weakest or lowest pattern.",
            "- Write one clear bullet point about budget, transport, cost, destination, or usage depending on the selected report type.",
            "Admin Actions",
            "- Write one practical action for the admin.",
            "- Write one practical action for improving the system or data quality.",
            "Keep the whole answer under 180 words.",
        ]);

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "Admin report for analysis:\n" . json_encode($compactData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
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
            CURLOPT_NOSIGNAL => true,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $err !== '' || $code < 200 || $code >= 300) {
            $bodyPreview = is_string($raw) ? substr($raw, 0, 800) : '';
            $debug = 'HTTP ' . $code .
                '. cURL error: ' . ($err !== '' ? $err : 'none') .
                '. URL: ' . $url .
                '. Response: ' . $bodyPreview;

            error_log('Ollama admin report request failed: ' . $debug);

            return [
                'status' => 'error',
                'source' => 'ollama',
                'analysis' => $debug,
            ];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'Invalid AI response. Raw response: ' . substr((string)$raw, 0, 800)];
        }

        $text = trim((string)($json['message']['content'] ?? ''));
        if ($text === '') {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'AI returned an empty response. Raw response: ' . substr((string)$raw, 0, 800)];
        }

        return ['status' => 'success', 'source' => 'ollama', 'analysis' => $text];
    }

    private function cleanOllamaReportText(string $text): string
    {
        $text = trim($text);
        $text = str_replace(["**", "***", "__"], "", $text);
        $text = preg_replace('/^\s*Based on (the )?(JSON )?(data|information|snapshot)( you( have)? provided)?[,\s]+/i', '', $text) ?? $text;
        $text = preg_replace('/^\s*Here is (a|the) (summary|analysis).*?:\s*/i', '', $text) ?? $text;
        $text = preg_replace('/^\s*Based on .*?:\s*/i', '', $text) ?? $text;

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = rtrim((string)$line);

            if (str_contains($line, '|')) continue;
            if (preg_match('/^\s*-{3,}\s*$/', $line)) continue;
            $line = preg_replace('/^\s*#+\s*/', '', $line) ?? $line;

            if (stripos($line, 'If you need further analysis') !== false) continue;
            if (stripos($line, 'please provide') !== false) continue;

            $clean[] = $line;
        }

        return trim(implode("\n", $clean));
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
