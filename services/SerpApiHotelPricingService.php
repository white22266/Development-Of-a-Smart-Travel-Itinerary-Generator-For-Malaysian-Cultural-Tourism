<?php
/**
 * SerpApiHotelPricingService
 *
 * Uses SerpAPI only to enrich Google Places hotel cards with pricing.
 * It does not replace Google Places as the hotel discovery source.
 */
class SerpApiHotelPricingService
{
    private array $lastDebug = [];

    public function enrichPrices(
        array $googleHotels,
        float $lat,
        float $lng,
        string $placeName,
        string $state = '',
        string $district = ''
    ): array {
        $this->lastDebug = [];

        if (empty($googleHotels)) return $googleHotels;

        foreach ($googleHotels as &$hotel) {
            $hotel['price_source'] = $hotel['price_source'] ?? 'planning_estimate';
        }
        unset($hotel);

        if (!$this->hasSerpApiKey()) {
            $this->lastDebug[] = ['stage' => 'serpapi_pricing', 'status' => 'missing_serpapi_api_key'];
            return $googleHotels;
        }

        $context = trim(implode(' ', array_filter([$placeName, $district, $state, 'Malaysia'])));
        if ($context === '') {
            $context = $lat . ',' . $lng;
        }

        // One SerpAPI call per final stop. DB cache prevents repeated calls.
        $url = 'https://serpapi.com/search.json?' . http_build_query([
            'engine' => 'google_maps',
            'q' => 'hotels near ' . $context,
            'll' => '@' . $lat . ',' . $lng . ',15z',
            'type' => 'search',
            'hl' => 'en',
            'gl' => 'my',
            'api_key' => SERPAPI_API_KEY,
        ]);

        $results = $this->fetchLocalResults($url);
        if (empty($results)) return $googleHotels;

        $priceRows = [];
        foreach ($results as $result) {
            $title = trim((string)($result['title'] ?? $result['name'] ?? ''));
            if ($title === '') continue;
            $price = $this->extractPrice($result);
            if ($price === null) continue;
            $priceRows[] = [
                'title' => $title,
                'normalized_title' => $this->normalizeName($title),
                'price' => $price['amount'],
                'label' => $price['label'],
            ];
        }

        $enriched = 0;
        foreach ($googleHotels as &$hotel) {
            $hotelName = (string)($hotel['name'] ?? '');
            $match = $this->bestPriceMatch($hotelName, $priceRows);
            if (!$match) continue;

            $hotel['price_per_night'] = (float)$match['price'];
            $hotel['price_source'] = 'serpapi_google_maps_price';
            $hotel['price_label'] = (string)$match['label'];
            $hotel['serpapi_price_match'] = (string)$match['title'];
            $enriched++;
        }
        unset($hotel);

        $this->lastDebug[] = [
            'stage' => 'serpapi_pricing_match',
            'status' => 'OK',
            'price_rows' => count($priceRows),
            'enriched_hotels' => $enriched,
        ];

        return $googleHotels;
    }

    public function hasLivePrices(array $hotels): bool
    {
        foreach ($hotels as $hotel) {
            if (($hotel['price_source'] ?? '') === 'serpapi_google_maps_price') return true;
        }
        return false;
    }

    public function getLastDebug(): array
    {
        return $this->lastDebug;
    }

    private function fetchLocalResults(string $url): array
    {
        if (!function_exists('curl_init')) {
            $this->lastDebug[] = ['stage' => 'serpapi_pricing', 'status' => 'curl_missing'];
            return [];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            $this->lastDebug[] = ['stage' => 'serpapi_pricing', 'status' => 'http_or_curl_error', 'http_code' => $code, 'error' => $error];
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->lastDebug[] = ['stage' => 'serpapi_pricing', 'status' => 'invalid_json'];
            return [];
        }

        if (isset($data['error'])) {
            $this->lastDebug[] = ['stage' => 'serpapi_pricing', 'status' => 'serpapi_error', 'error' => (string)$data['error']];
            return [];
        }

        $results = $data['local_results'] ?? [];
        if (!is_array($results)) $results = [];

        $this->lastDebug[] = [
            'stage' => 'serpapi_pricing',
            'status' => 'OK',
            'results_count' => count($results),
        ];

        return $results;
    }

    private function bestPriceMatch(string $hotelName, array $priceRows): ?array
    {
        $needle = $this->normalizeName($hotelName);
        if ($needle === '') return null;

        $best = null;
        $bestScore = 0.0;
        foreach ($priceRows as $row) {
            $candidate = (string)($row['normalized_title'] ?? '');
            if ($candidate === '') continue;

            $score = $this->nameSimilarity($needle, $candidate);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best && $bestScore >= 0.72) {
            $best['match_score'] = $bestScore;
            return $best;
        }
        return null;
    }

    private function nameSimilarity(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        if (str_contains($a, $b) || str_contains($b, $a)) return 0.92;

        similar_text($a, $b, $pct);
        $similarTextScore = ((float)$pct) / 100.0;

        $tokensA = array_values(array_filter(explode(' ', $a)));
        $tokensB = array_values(array_filter(explode(' ', $b)));
        if (empty($tokensA) || empty($tokensB)) return $similarTextScore;

        $intersection = array_intersect($tokensA, $tokensB);
        $tokenScore = count($intersection) / max(count(array_unique(array_merge($tokensA, $tokensB))), 1);

        return max($similarTextScore, $tokenScore);
    }

    private function normalizeName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\s]+/', ' ', $name) ?? $name;
        $name = preg_replace('/\b(hotel|kuala|lumpur|malaysia|the)\b/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', trim($name)) ?? $name;
        return trim($name);
    }

    private function extractPrice(array $result): ?array
    {
        foreach (['price', 'price_range', 'rate', 'rate_per_night', 'price_per_night', 'displayed_price'] as $key) {
            if (!array_key_exists($key, $result)) continue;
            $parsed = $this->parsePriceValue($result[$key]);
            if ($parsed !== null) return $parsed;
        }

        return $this->recursivePriceSearch($result);
    }

    private function recursivePriceSearch($value): ?array
    {
        if (!is_array($value)) return null;
        foreach ($value as $key => $nestedValue) {
            $keyText = strtolower((string)$key);
            if (str_contains($keyText, 'price') || str_contains($keyText, 'rate')) {
                $parsed = $this->parsePriceValue($nestedValue);
                if ($parsed !== null) return $parsed;
            }
            if (is_array($nestedValue)) {
                $parsed = $this->recursivePriceSearch($nestedValue);
                if ($parsed !== null) return $parsed;
            }
        }
        return null;
    }

    private function parsePriceValue($value): ?array
    {
        if (is_numeric($value)) {
            $amount = (float)$value;
            if ($amount >= 30 && $amount <= 5000) {
                return ['amount' => round($amount, 2), 'label' => 'RM ' . number_format($amount, 0)];
            }
            return null;
        }

        if (is_array($value)) {
            foreach (['lowest', 'extracted_lowest', 'before_taxes_fees', 'extracted_before_taxes_fees', 'amount', 'value'] as $key) {
                if (!array_key_exists($key, $value)) continue;
                $parsed = $this->parsePriceValue($value[$key]);
                if ($parsed !== null) return $parsed;
            }
            foreach ($value as $nested) {
                $parsed = $this->parsePriceValue($nested);
                if ($parsed !== null) return $parsed;
            }
            return null;
        }

        $text = trim((string)$value);
        if ($text === '') return null;

        if (preg_match('/(?:RM|MYR)\s*([0-9][0-9,]*(?:\.\d{1,2})?)/i', $text, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
            if ($amount >= 30 && $amount <= 5000) return ['amount' => round($amount, 2), 'label' => $text];
        }

        if (preg_match('/([0-9][0-9,]*(?:\.\d{1,2})?)\s*(?:RM|MYR)/i', $text, $m)) {
            $amount = (float)str_replace(',', '', $m[1]);
            if ($amount >= 30 && $amount <= 5000) return ['amount' => round($amount, 2), 'label' => $text];
        }

        return null;
    }

    private function hasSerpApiKey(): bool
    {
        return defined('SERPAPI_API_KEY') && trim((string)SERPAPI_API_KEY) !== '';
    }
}
