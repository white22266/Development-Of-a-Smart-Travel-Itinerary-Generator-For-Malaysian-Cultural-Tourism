<?php
/**
 * Live hotel recommendations from Google Places.
 *
 * The system no longer depends on a static `hotels` table for recommendations.
 * Hotels are searched near the last itinerary stop and are only written into
 * itinerary_items after the traveller confirms one option.
 */
class HotelRecommendationService
{
    private const DEFAULT_RADIUS_KM = 8.0;
    private const DEFAULT_TOP_N = 5;

    /** Kept for backward-compatible constructor calls. */
    public function __construct($conn = null)
    {
    }

    public function recommend(
        float $lat,
        float $lng,
        float $budget = 0.0,
        float $radiusKm = 0.0,
        int $topN = 0
    ): array {
        if (!$this->hasGoogleKey() || !$this->validCoord($lat, $lng)) return [];
        if ($radiusKm <= 0) $radiusKm = self::DEFAULT_RADIUS_KM;
        if ($topN <= 0) $topN = self::DEFAULT_TOP_N;

        $radiusMeters = (int)max(1000, min(50000, $radiusKm * 1000));
        $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?" . http_build_query([
            "location" => $lat . "," . $lng,
            "radius" => $radiusMeters,
            "type" => "lodging",
            "keyword" => "hotel accommodation",
            "key" => GOOGLE_MAPS_API_KEY,
        ]);

        $data = $this->getJson($url);
        $results = $data["results"] ?? [];
        $hotels = [];
        foreach ($results as $place) {
            $hotel = $this->normalizePlace($place, $lat, $lng, $budget);
            if ($hotel) $hotels[] = $hotel;
        }

        $hotels = $this->dedupeHotels($hotels);
        usort($hotels, fn($a, $b) => ($b["score"] ?? 0) <=> ($a["score"] ?? 0));
        return array_slice($hotels, 0, $topN);
    }

    public function recommendByState(string $state, float $budget = 0.0, int $topN = 5): array
    {
        $state = trim($state);
        if (!$this->hasGoogleKey() || $state === "") return [];
        if ($topN <= 0) $topN = self::DEFAULT_TOP_N;

        $url = "https://maps.googleapis.com/maps/api/place/textsearch/json?" . http_build_query([
            "query" => "hotels in " . $state . ", Malaysia",
            "type" => "lodging",
            "key" => GOOGLE_MAPS_API_KEY,
        ]);

        $data = $this->getJson($url);
        $results = $data["results"] ?? [];
        $hotels = [];
        foreach ($results as $place) {
            $hotel = $this->normalizePlace($place, null, null, $budget, $state);
            if ($hotel) $hotels[] = $hotel;
        }

        $hotels = $this->dedupeHotels($hotels);
        usort($hotels, fn($a, $b) => ($b["score"] ?? 0) <=> ($a["score"] ?? 0));
        return array_slice($hotels, 0, $topN);
    }

    public function detailsByPlaceId(string $placeId, float $budget = 0.0): ?array
    {
        $placeId = trim($placeId);
        if (!$this->hasGoogleKey() || $placeId === "") return null;

        $url = "https://maps.googleapis.com/maps/api/place/details/json?" . http_build_query([
            "place_id" => $placeId,
            "fields" => "place_id,name,formatted_address,geometry,rating,user_ratings_total,price_level,url,website",
            "key" => GOOGLE_MAPS_API_KEY,
        ]);

        $data = $this->getJson($url);
        $place = $data["result"] ?? null;
        if (!is_array($place)) return null;

        return $this->normalizePlace($place, null, null, $budget);
    }

    private function normalizePlace(array $place, ?float $originLat, ?float $originLng, float $budget, string $fallbackState = ""): ?array
    {
        $name = trim((string)($place["name"] ?? ""));
        $placeId = trim((string)($place["place_id"] ?? ""));
        $lat = $place["geometry"]["location"]["lat"] ?? null;
        $lng = $place["geometry"]["location"]["lng"] ?? null;
        if ($name === "" || $placeId === "" || !$this->validCoord((float)$lat, (float)$lng)) return null;

        $rating = (float)($place["rating"] ?? 0);
        $priceLevel = isset($place["price_level"]) ? (int)$place["price_level"] : null;
        $estimatedRate = $this->estimateNightlyRate($place, $priceLevel, $budget);
        $distanceKm = ($originLat !== null && $originLng !== null)
            ? round($this->haversineKm($originLat, $originLng, (float)$lat, (float)$lng), 2)
            : null;

        $distanceScore = $distanceKm !== null ? max(0.0, 1 - min($distanceKm, 30) / 30) : 0.65;
        $ratingScore = $rating > 0 ? min(1.0, $rating / 5.0) : 0.6;
        $priceScore = ($budget > 0 && $estimatedRate > 0) ? max(0.0, 1 - ($estimatedRate / max($budget, 1))) : 0.7;

        return [
            "hotel_id" => 0,
            "google_place_id" => $placeId,
            "name" => $name,
            "state" => $fallbackState,
            "district" => "",
            "address" => (string)($place["vicinity"] ?? $place["formatted_address"] ?? ""),
            "latitude" => (float)$lat,
            "longitude" => (float)$lng,
            "price_per_night" => $estimatedRate,
            "price_level" => $priceLevel,
            "rating" => $rating,
            "user_ratings_total" => (int)($place["user_ratings_total"] ?? 0),
            "distance_km" => $distanceKm,
            "map_url" => (string)($place["url"] ?? ("https://www.google.com/maps/search/?api=1&query_place_id=" . rawurlencode($placeId) . "&query=" . rawurlencode($name))),
            "source" => "google_places",
            "score" => round((0.40 * $distanceScore) + (0.30 * $priceScore) + (0.30 * $ratingScore), 4),
        ];
    }

    private function estimateNightlyRate(array $place, ?int $priceLevel, float $budget): float
    {
        $rating = (float)($place["rating"] ?? 3.8);
        $reviews = (int)($place["user_ratings_total"] ?? 0);
        $name = strtolower((string)($place["name"] ?? ""));
        $address = strtolower((string)($place["vicinity"] ?? $place["formatted_address"] ?? ""));
        $text = $name . " " . $address;

        if ($priceLevel !== null) {
            $base = match($priceLevel) {
                0 => 75.0,
                1 => 110.0,
                2 => 175.0,
                3 => 290.0,
                4 => 480.0,
                default => 170.0,
            };
        } else {
            // Google Places often omits hotel room prices. Use live popularity
            // signals so different hotels do not collapse into one fixed price.
            $base = 95.0 + max(0, $rating - 3.0) * 45.0 + min(95.0, log10(max(1, $reviews)) * 32.0);
        }

        $locationMultiplier = 1.0;
        if (str_contains($text, "kuala lumpur") || str_contains($text, "klcc") || str_contains($text, "bukit bintang")) {
            $locationMultiplier = 1.28;
        } elseif (str_contains($text, "penang") || str_contains($text, "george town") || str_contains($text, "johor bahru")) {
            $locationMultiplier = 1.12;
        } elseif (str_contains($text, "desaru") || str_contains($text, "island") || str_contains($text, "pulau")) {
            $locationMultiplier = 1.18;
        }

        $brandMultiplier = 1.0;
        if (preg_match('/\\b(resort|suite|suites|grand|premier|luxury|marriott|hilton|hyatt|shangri|intercontinental|renaissance|mandarin)\\b/i', $name)) {
            $brandMultiplier = 1.22;
        } elseif (preg_match('/\\b(budget|inn|capsule|hostel|oyo|spot on|hotel 99)\\b/i', $name)) {
            $brandMultiplier = 0.78;
        }

        $ratingMultiplier = 0.92 + min(0.28, max(0.0, $rating - 3.5) * 0.12);

        // Deterministic variation from Google Place ID/name avoids random UI
        // changes while still reflecting hotel-specific differences.
        $seed = crc32((string)($place["place_id"] ?? $name));
        $variation = 0.88 + (($seed % 25) / 100); // 0.88 to 1.12

        $rate = $base * $locationMultiplier * $brandMultiplier * $ratingMultiplier * $variation;

        // Budget is a soft cap for planning, not a fake real price. Keep higher
        // hotels visible but avoid estimates that destroy the user's whole trip.
        if ($budget > 0) {
            $softCap = max(90.0, $budget * 1.25);
            $rate = min($rate, $softCap);
        }

        return round(max(60.0, $rate) / 5) * 5;
    }

    private function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) return [];
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data["status"] ?? "OK") === "REQUEST_DENIED") return [];
        return $data;
    }

    private function hasGoogleKey(): bool
    {
        return defined("GOOGLE_MAPS_API_KEY") && trim((string)GOOGLE_MAPS_API_KEY) !== "";
    }

    private function validCoord(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && !($lat == 0.0 && $lng == 0.0);
    }

    private function dedupeHotels(array $hotels): array
    {
        $seen = [];
        $unique = [];
        foreach ($hotels as $hotel) {
            $key = strtolower(trim((string)($hotel["google_place_id"] ?? $hotel["name"] ?? "")));
            if ($key === "" || isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $hotel;
        }
        return $unique;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
