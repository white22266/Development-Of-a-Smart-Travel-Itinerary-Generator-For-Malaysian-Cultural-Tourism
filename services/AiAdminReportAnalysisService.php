<?php

/**
 * services/AiAdminReportAnalysisService.php
 *
 * Admin report explanation service.
 * PHP first extracts verified facts from the database report, then Ollama only
 * rewrites those facts into a concise admin-friendly explanation. If Ollama is
 * unavailable or exceeds the timeout, the same verified facts are rendered by a
 * local deterministic template.
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
        $this->numPredict = max(180, min(500, (int)(getenv('ADMIN_AI_NUM_PREDICT') ?: 280)));
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
            error_log('Ollama admin report output rejected; using local verified-facts summary.');
        } else {
            error_log('Ollama admin report failed; using local verified-facts summary: ' . (string)($ai['analysis'] ?? 'Unknown Ollama error.'));
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
            'Write a concise admin report explanation from verified facts only.',
            'Do not calculate, rank, infer, or create new categories.',
            'Do not mention JSON, dataset, snapshot, prompt, user request, provided data, or missing data.',
            'Do not ask questions. Do not offer more help. Do not write a closing sentence.',
            'Do not use tables or markdown table syntax.',
            'Use only these exact headings: Report Summary, Key Findings, Admin Actions.',
            'Report Summary must be one short paragraph with 2 sentences.',
            'Key Findings must contain exactly 3 bullet points.',
            'Admin Actions must contain exactly 2 bullet points.',
            'Keep the whole answer under 140 words.',
        ]);

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => "Verified report facts:\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'stream' => false,
            'keep_alive' => $this->keepAlive,
            'options' => [
                'temperature' => 0.1,
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

    private function buildInsightFacts(array $reportData): array
    {
        $type = (string)($reportData['report_type'] ?? 'overview');
        $period = (string)($reportData['period'] ?? '');
        $kpis = is_array($reportData['kpis'] ?? null) ? $reportData['kpis'] : [];
        $sections = is_array($reportData['sections'] ?? null) ? $reportData['sections'] : [];
        $kpiMap = $this->kpiMap($kpis);

        $facts = [
            'report_type' => $type,
            'period' => $period,
            'summary_points' => [],
            'key_findings' => [],
            'admin_actions' => [],
        ];

        switch ($type) {
            case 'user_preferences':
                $facts = $this->factsForUserPreferences($facts, $kpiMap, $sections);
                break;
            case 'destination_demand':
                $facts = $this->factsForDestinationDemand($facts, $kpiMap, $sections);
                break;
            case 'attraction_price':
                $facts = $this->factsForAttractionPrice($facts, $kpiMap, $sections);
                break;
            case 'cost_budget':
                $facts = $this->factsForCostBudget($facts, $kpiMap, $sections);
                break;
            case 'ai_usage':
                $facts = $this->factsForAiUsage($facts, $kpiMap, $sections);
                break;
            default:
                $facts = $this->factsForOverview($facts, $kpiMap, $sections);
                break;
        }

        $facts['summary_points'] = $this->limitNonEmpty($facts['summary_points'], 4);
        $facts['key_findings'] = $this->limitNonEmpty($facts['key_findings'], 3);
        $facts['admin_actions'] = $this->limitNonEmpty($facts['admin_actions'], 2);

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

    private function factsForUserPreferences(array $facts, array $kpiMap, array $sections): array
    {
        $records = $this->kpi($kpiMap, 'preference records');
        $topInterest = $this->kpi($kpiMap, 'top interest');
        $topState = $this->kpi($kpiMap, 'top desired state');
        $avgBudget = $this->kpi($kpiMap, 'average budget');
        $lowestInterest = $this->firstRow($sections, 'Lowest User Interests');
        $lowestState = $this->firstRow($sections, 'Lowest Desired States');
        $transport = $this->firstRow($sections, 'Transport Preference Analysis');

        if ($records) $facts['summary_points'][] = 'Preference records: ' . $this->kpiDisplay($records) . '.';
        if ($topInterest) $facts['summary_points'][] = 'Top interest: ' . $this->kpiDisplay($topInterest) . '.';
        if ($topState) $facts['summary_points'][] = 'Top desired state: ' . $this->kpiDisplay($topState) . '.';
        if ($avgBudget) $facts['summary_points'][] = 'Average budget: ' . $this->kpiDisplay($avgBudget) . '.';

        if ($topInterest) $facts['key_findings'][] = 'Strongest pattern: ' . $this->kpiDisplay($topInterest) . ' shows the highest user interest.';
        if ($lowestInterest) $facts['key_findings'][] = 'Weakest interest pattern: ' . $this->rowPair($lowestInterest, ['Interest', 'Total']) . '.';
        if ($lowestState) $facts['key_findings'][] = 'Lowest state demand: ' . $this->rowPair($lowestState, ['State', 'Total']) . '.';
        if ($transport) $facts['key_findings'][] = 'Transport pattern: ' . $this->rowPair($transport, ['Transport', 'Total', 'Average Budget']) . '.';

        $facts['admin_actions'][] = 'Prioritize itinerary content around high-demand interests and states shown in the report.';
        $facts['admin_actions'][] = 'Review low-demand interests or states and improve cultural place coverage only where it supports system completeness.';
        return $facts;
    }

    private function factsForDestinationDemand(array $facts, array $kpiMap, array $sections): array
    {
        $mostDesired = $this->kpi($kpiMap, 'most desired state');
        $leastDesired = $this->kpi($kpiMap, 'least desired state');
        $mostGenerated = $this->kpi($kpiMap, 'most generated state');
        $records = $this->kpi($kpiMap, 'destination records');
        $topDistrict = $this->firstRow($sections, 'Highest Desired Districts');

        if ($mostDesired) $facts['summary_points'][] = 'Most desired state: ' . $this->kpiDisplay($mostDesired) . '.';
        if ($mostGenerated) $facts['summary_points'][] = 'Most generated state: ' . $this->kpiDisplay($mostGenerated) . '.';
        if ($records) $facts['summary_points'][] = 'Destination records: ' . $this->kpiDisplay($records) . '.';

        if ($mostDesired) $facts['key_findings'][] = 'Strongest user destination demand: ' . $this->kpiDisplay($mostDesired) . '.';
        if ($leastDesired) $facts['key_findings'][] = 'Lowest user destination demand: ' . $this->kpiDisplay($leastDesired) . '.';
        if ($mostDesired && $mostGenerated && strcasecmp((string)$mostDesired['value'], (string)$mostGenerated['value']) !== 0) {
            $facts['key_findings'][] = 'Demand-generation gap: users most desire ' . $mostDesired['value'] . ', while generated routes most often include ' . $mostGenerated['value'] . '.';
        } elseif ($topDistrict) {
            $facts['key_findings'][] = 'Top district demand: ' . $this->rowPair($topDistrict, ['District', 'Total']) . '.';
        }

        $facts['admin_actions'][] = 'Compare desired destinations with generated itinerary output to improve route alignment.';
        $facts['admin_actions'][] = 'Increase or refine cultural place records in high-demand states and districts where coverage is weak.';
        return $facts;
    }

    private function factsForAttractionPrice(array $facts, array $kpiMap, array $sections): array
    {
        foreach (['active attractions', 'free places', 'highest price', 'average price'] as $needle) {
            $kpi = $this->kpi($kpiMap, $needle);
            if ($kpi) $facts['summary_points'][] = $kpi['label'] . ': ' . $this->kpiDisplay($kpi) . '.';
        }
        $category = $this->firstRow($sections, 'Category and Price Summary');
        $popular = $this->firstRow($sections, 'Most Used Attractions');
        $completeness = $this->firstRow($sections, 'Data Completeness Check');

        if ($category) $facts['key_findings'][] = 'Category coverage: ' . $this->rowPair($category, ['Category', 'Places', 'Average Price']) . '.';
        if ($popular) $facts['key_findings'][] = 'Most used attraction pattern: ' . $this->rowPair($popular, ['Place', 'State', 'Used', 'Price']) . '.';
        if ($completeness) $facts['key_findings'][] = 'Data quality issue: ' . $this->rowPair($completeness, ['Issue', 'Total']) . '.';

        $facts['admin_actions'][] = 'Review attraction prices and usage patterns to keep itinerary cost estimates realistic.';
        $facts['admin_actions'][] = 'Complete missing attraction data such as coordinates, images, opening hours, or visit duration.';
        return $facts;
    }

    private function factsForCostBudget(array $facts, array $kpiMap, array $sections): array
    {
        foreach (['average trip cost', 'lowest trip cost', 'highest trip cost', 'over budget trips'] as $needle) {
            $kpi = $this->kpi($kpiMap, $needle);
            if ($kpi) $facts['summary_points'][] = $kpi['label'] . ': ' . $this->kpiDisplay($kpi) . '.';
        }
        $budgetFit = $this->firstRow($sections, 'Budget Fit Summary');
        $highestTrip = $this->firstRow($sections, 'Highest Cost Itineraries');
        $monthly = $this->firstRow($sections, 'Monthly Cost Trend');

        if ($budgetFit) $facts['key_findings'][] = 'Budget fit pattern: ' . $this->rowPair($budgetFit, ['Status', 'Trips', 'Average Trip Cost', 'Average User Budget']) . '.';
        if ($highestTrip) $facts['key_findings'][] = 'Highest cost itinerary: ' . $this->rowPair($highestTrip, ['Itinerary', 'Days', 'Cost', 'Budget']) . '.';
        if ($monthly) $facts['key_findings'][] = 'Monthly cost trend: ' . $this->rowPair($monthly, ['Month', 'Trips', 'Average Cost']) . '.';

        $facts['admin_actions'][] = 'Review over-budget trips and adjust hotel, food, or transport cost assumptions.';
        $facts['admin_actions'][] = 'Use high-cost itinerary records to verify whether the estimation logic is realistic.';
        return $facts;
    }

    private function factsForAiUsage(array $facts, array $kpiMap, array $sections): array
    {
        foreach (['ai questions', 'assistant responses', 'itinerary questions', 'active ai users'] as $needle) {
            $kpi = $this->kpi($kpiMap, $needle);
            if ($kpi) $facts['summary_points'][] = $kpi['label'] . ': ' . $this->kpiDisplay($kpi) . '.';
        }
        $intent = $this->firstRow($sections, 'AI Question Intent Summary');
        $user = $this->firstRow($sections, 'Most Active AI Users');

        if ($intent) $facts['key_findings'][] = 'Most common AI question intent: ' . $this->rowPair($intent, ['Intent', 'Total']) . '.';
        if ($user) $facts['key_findings'][] = 'Most active AI user pattern: ' . $this->rowPair($user, ['Traveller', 'Questions', 'Last Question']) . '.';
        $facts['key_findings'][] = 'AI usage data shows how travellers use the assistant during itinerary planning.';

        $facts['admin_actions'][] = 'Improve AI assistant responses for the most common question intents.';
        $facts['admin_actions'][] = 'Test AI answers against real itinerary records before the final demonstration.';
        return $facts;
    }

    private function factsForOverview(array $facts, array $kpiMap, array $sections): array
    {
        foreach ($kpiMap as $kpi) {
            $facts['summary_points'][] = $kpi['label'] . ': ' . $this->kpiDisplay($kpi) . '.';
        }
        foreach (array_slice($sections, 0, 3) as $section) {
            if (!is_array($section)) continue;
            $row = is_array($section['rows'][0] ?? null) ? $section['rows'][0] : null;
            if ($row) $facts['key_findings'][] = (string)($section['title'] ?? 'Section') . ': ' . $this->rowPair($row, array_keys($row)) . '.';
        }
        $facts['admin_actions'][] = 'Use the overview to identify system modules with strong activity and weak data coverage.';
        $facts['admin_actions'][] = 'Keep report exports as supporting evidence for final project evaluation.';
        return $facts;
    }

    private function buildLocalNarrative(array $facts): string
    {
        $summary = $this->limitNonEmpty($facts['summary_points'] ?? [], 4);
        $findings = $this->limitNonEmpty($facts['key_findings'] ?? [], 3);
        $actions = $this->limitNonEmpty($facts['admin_actions'] ?? [], 2);

        $summaryText = implode(' ', $summary);
        if ($summaryText === '') $summaryText = 'The report summarizes available system activity for the selected period.';

        $lines = [];
        $lines[] = 'Report Summary';
        $lines[] = $summaryText;
        $lines[] = '';
        $lines[] = 'Key Findings';
        foreach ($findings as $finding) $lines[] = '- ' . ltrim($finding, '- ');
        $lines[] = '';
        $lines[] = 'Admin Actions';
        foreach ($actions as $action) $lines[] = '- ' . ltrim($action, '- ');
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
        return true;
    }

    private function kpiMap(array $kpis): array
    {
        $map = [];
        foreach ($kpis as $kpi) {
            if (!is_array($kpi)) continue;
            $label = trim((string)($kpi['label'] ?? ''));
            if ($label === '') continue;
            $map[strtolower($label)] = [
                'label' => $label,
                'value' => (string)($kpi['value'] ?? '-'),
                'note' => trim((string)($kpi['note'] ?? '')),
            ];
        }
        return $map;
    }

    private function kpi(array $kpiMap, string $needle): ?array
    {
        $needle = strtolower($needle);
        foreach ($kpiMap as $label => $kpi) {
            if (str_contains($label, $needle)) return $kpi;
        }
        return null;
    }

    private function kpiDisplay(array $kpi): string
    {
        $value = trim((string)($kpi['value'] ?? '-'));
        $note = trim((string)($kpi['note'] ?? ''));
        return $value . ($note !== '' ? ' (' . $note . ')' : '');
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
                if ($value !== '') $pairs[] = $key . ' = ' . $value;
            }
            if (count($pairs) >= 3) break;
        }
        if (empty($pairs)) {
            foreach ($row as $key => $value) {
                if (is_array($value)) continue;
                $value = trim((string)$value);
                if ($value !== '') $pairs[] = $key . ' = ' . $value;
                if (count($pairs) >= 3) break;
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
