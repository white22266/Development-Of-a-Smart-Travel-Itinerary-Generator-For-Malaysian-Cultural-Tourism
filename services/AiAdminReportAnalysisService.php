<?php

/**
 * services/AiAdminReportAnalysisService.php
 *
 * Admin report explanation service.
 * PHP first extracts verified facts from the database report. Ollama only rewrites
 * those verified facts into a professional admin-friendly explanation. If Ollama
 * is unavailable, too slow, or produces unsuitable text, the same verified facts
 * are rendered with a longer deterministic local narrative.
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
        $this->numPredict = max(350, min(1000, (int)(getenv('ADMIN_AI_NUM_PREDICT') ?: 650)));
        $this->numCtx = max(512, min(4096, (int)(getenv('ADMIN_AI_NUM_CTX') ?: (defined('OLLAMA_NUM_CTX') ? OLLAMA_NUM_CTX : 1024))));
        $this->keepAlive = trim((string)(getenv('ADMIN_AI_KEEP_ALIVE') ?: (getenv('OLLAMA_KEEP_ALIVE') ?: '30m')));
        if ($this->keepAlive === '' || $this->keepAlive === '-1') $this->keepAlive = '30m';
    }

    public function analyze(array $reportData): array
    {
        $facts = $this->buildInsightFacts($reportData);
        $localSummary = $this->buildLocalNarrative($facts);

        if ($this->fastMode) {
            return [
                'status' => 'success',
                'source' => 'fast_local_report',
                'analysis' => $localSummary,
            ];
        }

        $ai = $this->callOllama($facts);
        if (($ai['status'] ?? '') === 'success') {
            $cleanAi = $this->cleanOllamaReportText((string)($ai['analysis'] ?? ''));
            if ($this->isValidNarrative($cleanAi)) {
                return [
                    'status' => 'success',
                    'source' => 'ollama',
                    'analysis' => $cleanAi,
                ];
            }
            error_log('Ollama admin report output rejected; using longer local verified-facts narrative.');
        } else {
            error_log('Ollama admin report failed; using longer local verified-facts narrative: ' . (string)($ai['analysis'] ?? 'Unknown Ollama error.'));
        }

        return [
            'status' => 'success',
            'source' => 'local_fallback',
            'analysis' => $localSummary,
        ];
    }

    private function callOllama(array $facts): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'cURL is not enabled in PHP. Enable extension=curl in php.ini and restart Apache.'];
        }

        $instructions = implode("\n", [
            'Write a professional admin report explanation from verified facts only.',
            'Do not calculate, rank, infer, or create new categories beyond the verified facts.',
            'Do not mention JSON, dataset, snapshot, prompt, user request, provided data, supplied data, or missing data.',
            'Do not ask questions. Do not offer more help. Do not write a closing sentence.',
            'Do not use tables or markdown table syntax.',
            'Use only these exact headings: Report Summary, Key Findings, Admin Actions.',
            'Report Summary must be one complete paragraph with 4 to 5 sentences.',
            'Key Findings must contain exactly 4 bullet points and explain what the facts mean for the admin.',
            'Admin Actions must contain exactly 3 bullet points with practical actions.',
            'Keep the whole answer between 240 and 330 words.',
            'Write in a formal final-year-project report style, not chatbot style.',
        ]);

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "Verified report facts only:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'stream' => false,
            'keep_alive' => $this->keepAlive,
            'options' => [
                'temperature' => 0.15,
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
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => $debug];
        }

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'Invalid AI response. Raw response: ' . substr((string)$raw, 0, 800)];
        }

        $text = trim((string)($json['message']['content'] ?? ''));
        if ($text === '') {
            return ['status' => 'error', 'source' => 'ollama', 'analysis' => 'AI returned an empty response. Raw response: ' . substr((string)$raw, 0, 800)];
        }

        return ['status' => 'success', 'source' => 'ollama', 'analysis' => $text];
    }

    private function buildInsightFacts(array $reportData): array
    {
        $type = (string)($reportData['report_type'] ?? 'overview');
        $period = (string)($reportData['period'] ?? '');
        $kpis = is_array($reportData['kpis'] ?? null) ? $reportData['kpis'] : [];
        $sections = is_array($reportData['sections'] ?? null) ? $reportData['sections'] : [];

        $facts = [
            'report_type' => $type,
            'report_label' => $this->reportLabel($type),
            'period' => $period !== '' ? $period : 'All time',
            'summary_points' => [],
            'key_findings' => [],
            'admin_actions' => $this->defaultActions($type),
        ];

        foreach ($kpis as $kpi) {
            if (!is_array($kpi)) continue;
            $label = trim((string)($kpi['label'] ?? ''));
            $value = trim((string)($kpi['value'] ?? ''));
            $note = trim((string)($kpi['note'] ?? ''));
            if ($label === '' || $value === '') continue;
            $facts['summary_points'][] = $label . ': ' . $value . ($note !== '' ? ' (' . $note . ')' : '') . '.';
        }

        foreach ($this->preferredSectionTitles($type) as $titleNeedle) {
            $row = $this->firstRow($sections, $titleNeedle);
            if ($row) {
                $facts['key_findings'][] = $titleNeedle . ': ' . $this->rowPair($row, array_keys($row)) . '.';
            }
        }

        if (count($facts['key_findings']) < 4) {
            foreach ($sections as $section) {
                if (!is_array($section)) continue;
                $title = trim((string)($section['title'] ?? ''));
                if ($title === '') continue;
                $row = is_array($section['rows'][0] ?? null) ? $section['rows'][0] : null;
                if (!$row) continue;
                $candidate = $title . ': ' . $this->rowPair($row, array_keys($row)) . '.';
                if (!in_array($candidate, $facts['key_findings'], true)) {
                    $facts['key_findings'][] = $candidate;
                }
                if (count($facts['key_findings']) >= 5) break;
            }
        }

        $facts['summary_points'] = $this->limitNonEmpty($facts['summary_points'], 6);
        $facts['key_findings'] = $this->limitNonEmpty($facts['key_findings'], 5);
        $facts['admin_actions'] = $this->limitNonEmpty($facts['admin_actions'], 3);

        if (empty($facts['summary_points'])) {
            $facts['summary_points'][] = 'The report was generated from available system records for the selected period.';
        }
        if (empty($facts['key_findings'])) {
            $facts['key_findings'][] = 'The report provides database-backed indicators for admin review.';
        }
        if (empty($facts['admin_actions'])) {
            $facts['admin_actions'][] = 'Review the report sections and update system data where gaps are visible.';
        }

        return $facts;
    }

    private function buildLocalNarrative(array $facts): string
    {
        $label = (string)($facts['report_label'] ?? 'Admin Report');
        $period = (string)($facts['period'] ?? 'All time');
        $summary = $this->limitNonEmpty($facts['summary_points'] ?? [], 6);
        $findings = $this->limitNonEmpty($facts['key_findings'] ?? [], 5);
        $actions = $this->limitNonEmpty($facts['admin_actions'] ?? [], 3);

        $summarySentence = implode(' ', $summary);
        if ($summarySentence === '') {
            $summarySentence = 'The report summarizes available system activity for the selected period.';
        }

        $interpretation = $this->localInterpretation((string)($facts['report_type'] ?? 'overview'));

        $lines = [];
        $lines[] = 'Report Summary';
        $lines[] = 'This ' . strtolower($label) . ' covers the period of ' . $period . ' and explains the main database-backed indicators that require admin attention. ' . $summarySentence . ' ' . $interpretation . ' The purpose of this summary is to help the administrator understand the meaning of the report without relying only on tables and charts.';
        $lines[] = '';
        $lines[] = 'Key Findings';
        foreach ($findings as $finding) {
            $lines[] = '- ' . ltrim($finding, '- ');
        }
        $findingCount = count($findings);
        if ($findingCount < 4) {
            $lines[] = '- The available indicators should be reviewed together with the report tables to confirm whether the pattern is consistent across the selected period.';
        }
        $lines[] = '';
        $lines[] = 'Admin Actions';
        foreach ($actions as $action) {
            $lines[] = '- ' . ltrim($action, '- ');
        }
        return trim(implode("\n", $lines));
    }

    private function cleanOllamaReportText(string $text): string
    {
        $text = trim($text);
        $text = str_replace(['**', '***', '__'], '', $text);
        $text = preg_replace('/^\s*,?\s*(here is|here\'s)\s+(a|the)?\s*(summary|analysis|overview).*?(\n|$)/i', '', $text) ?? $text;
        $text = preg_replace('/^\s*(Based on|According to).*?(provided|given|supplied).*?(\n|$)/is', '', $text) ?? $text;
        $text = preg_replace('/^\s*you[’\']?ve provided,?\s*/i', '', $text) ?? $text;

        $lines = preg_split('/\r\n|\r|\n/', $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (str_contains($line, '|')) continue;
            if (preg_match('/^\s*-{3,}\s*$/', $line)) continue;
            $line = preg_replace('/^\s*#+\s*/', '', $line) ?? $line;
            $line = preg_replace('/^\s*\d+\.\s+/', '', $line) ?? $line;
            $line = rtrim($line, ':');
            $lower = strtolower($line);

            $forbidden = [
                'here is', 'here are', 'provided', 'supplied', 'given data', 'json', 'dataset', 'snapshot', 'prompt',
                'would you like', 'how can i', 'please provide', 'feel free', 'anything else', 'further analysis',
                'additional data', 'not specified', 'not provided', 'missing', 'unavailable', 'urban planning',
                'marketing strategies', 'research', 'if you need', 'do you have', 'can i assist', 'elaborate', '?'
            ];
            $blocked = false;
            foreach ($forbidden as $phrase) {
                if (str_contains($lower, $phrase)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) continue;
            $clean[] = $line;
        }

        return trim(implode("\n", $clean));
    }

    private function isValidNarrative(string $text): bool
    {
        $lower = strtolower(trim($text));
        if ($lower === '') return false;
        foreach (['report summary', 'key findings', 'admin actions'] as $heading) {
            if (!str_contains($lower, $heading)) return false;
        }
        $bad = ['feel free', 'would you like', 'please provide', 'json', 'dataset', 'not specified', 'not provided', '?'];
        foreach ($bad as $phrase) {
            if (str_contains($lower, $phrase)) return false;
        }
        return str_word_count(strip_tags($text)) >= 90;
    }

    private function reportLabel(string $type): string
    {
        return match ($type) {
            'user_preferences' => 'User Preference Analysis Report',
            'destination_demand' => 'Destination Demand Report',
            'attraction_price' => 'Attraction and Price Report',
            'cost_budget' => 'Cost and Budget Report',
            'ai_usage' => 'AI Usage Report',
            default => 'System Overview Report',
        };
    }

    private function preferredSectionTitles(string $type): array
    {
        return match ($type) {
            'user_preferences' => ['Highest User Interests', 'Lowest User Interests', 'Highest Desired States', 'Lowest Desired States', 'Transport Preference Analysis', 'Budget Range Analysis'],
            'destination_demand' => ['Highest Desired States from Users', 'Lowest Desired States from Users', 'Actual Generated Destination States', 'Actual Generated Destination Districts'],
            'attraction_price' => ['Category and Price Summary', 'Most Used Attractions in Itineraries', 'Highest Price Attractions', 'Data Completeness Check'],
            'cost_budget' => ['Budget Fit Summary', 'Highest Cost Itineraries', 'Lowest Cost Itineraries', 'Monthly Cost Trend'],
            'ai_usage' => ['AI Question Intent Summary', 'Most Active AI Users'],
            default => ['System Summary', 'Monthly Trip Generation', 'Content Suggestion Status', 'Top Reviewed Places'],
        };
    }

    private function defaultActions(string $type): array
    {
        return match ($type) {
            'user_preferences' => [
                'Prioritize itinerary content around high-demand interests and states shown in the report.',
                'Review low-demand interests or states and improve cultural place coverage only where it supports system completeness.',
                'Use budget and transport patterns to adjust itinerary personalization rules and recommendation priorities.',
            ],
            'destination_demand' => [
                'Compare desired destinations with generated itinerary output to improve route alignment.',
                'Increase or refine cultural place records in high-demand states and districts where coverage is weak.',
                'Use demand-generation gaps to decide which destination data should be expanded before the final demonstration.',
            ],
            'attraction_price' => [
                'Review attraction prices and usage patterns to keep itinerary cost estimates realistic.',
                'Complete missing attraction data such as coordinates, images, opening hours, or visit duration.',
                'Use the price distribution to identify records that may need correction before report export.',
            ],
            'cost_budget' => [
                'Review over-budget trips and adjust hotel, food, or transport cost assumptions.',
                'Use high-cost itinerary records to verify whether the estimation logic is realistic.',
                'Compare user budgets with generated trip costs to improve budget-fit recommendations.',
            ],
            'ai_usage' => [
                'Improve AI assistant responses for the most common question intents.',
                'Test AI answers against real itinerary records before the final demonstration.',
                'Use active-user patterns to identify where travellers need clearer itinerary explanations.',
            ],
            default => [
                'Use the overview to identify system modules with strong activity and weak data coverage.',
                'Keep report exports as supporting evidence for final project evaluation.',
                'Review sections with low or empty data before presenting the system demonstration.',
            ],
        };
    }

    private function localInterpretation(string $type): string
    {
        return match ($type) {
            'user_preferences' => 'The indicators are useful for understanding what users want most, which areas show weaker demand, and how budget or transport choices should guide personalization.',
            'destination_demand' => 'The indicators are useful for comparing what users request against what the itinerary generator actually produces.',
            'attraction_price' => 'The indicators are useful for checking whether the cultural place knowledge base is complete, balanced, and suitable for cost-aware itinerary generation.',
            'cost_budget' => 'The indicators are useful for evaluating whether generated itineraries stay realistic against user budgets and cost assumptions.',
            'ai_usage' => 'The indicators are useful for understanding how travellers use the AI assistant and which question types need stronger responses.',
            default => 'The indicators are useful for reviewing system readiness, data coverage, and the reliability of report exports.',
        };
    }

    private function firstRow(array $sections, string $titleNeedle): ?array
    {
        $needle = strtolower($titleNeedle);
        foreach ($sections as $section) {
            if (!is_array($section)) continue;
            $title = strtolower((string)($section['title'] ?? ''));
            if (!str_contains($title, $needle)) continue;
            $rows = is_array($section['rows'] ?? null) ? $section['rows'] : [];
            foreach ($rows as $row) {
                if (is_array($row) && !empty($row)) return $row;
            }
        }
        return null;
    }

    private function rowPair(array $row, array $preferredKeys): string
    {
        $pairs = [];
        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $row)) {
                $value = trim((string)$row[$key]);
                if ($value !== '') $pairs[] = $key . ': ' . $value;
            }
            if (count($pairs) >= 4) break;
        }
        if (empty($pairs)) {
            foreach ($row as $key => $value) {
                if (is_array($value)) continue;
                $value = trim((string)$value);
                if ($value !== '') $pairs[] = $key . ': ' . $value;
                if (count($pairs) >= 4) break;
            }
        }
        return implode(', ', $pairs);
    }

    private function limitNonEmpty(array $items, int $limit): array
    {
        $out = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') continue;
            $out[] = $item;
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}
