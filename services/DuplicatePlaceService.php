<?php
// services/DuplicatePlaceService.php

class DuplicatePlaceService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function findMatches(array $candidate, int $limit = 8, ?int $excludePlaceId = null): array
    {
        $name = trim((string)($candidate["name"] ?? ""));
        $state = trim((string)($candidate["state"] ?? ""));
        $category = trim((string)($candidate["category"] ?? ""));
        $lat = $this->numOrNull($candidate["latitude"] ?? null);
        $lng = $this->numOrNull($candidate["longitude"] ?? null);

        if ($name === "" && ($lat === null || $lng === null)) return [];

        $params = [];
        $types = "";
        $where = "WHERE is_active = 1";

        if ($state !== "") {
            $where .= " AND state = ?";
            $params[] = $state;
            $types .= "s";
        }
        if ($category !== "") {
            $where .= " AND category = ?";
            $params[] = $category;
            $types .= "s";
        }
        if ($excludePlaceId !== null && $excludePlaceId > 0) {
            $where .= " AND place_id <> ?";
            $params[] = $excludePlaceId;
            $types .= "i";
        }

        $districtCol = $this->hasColumn("cultural_places", "district") ? "district" : "NULL AS district";
        $sql = "
            SELECT place_id, name, state, {$districtCol}, category, address, latitude, longitude, estimated_cost
            FROM cultural_places
            {$where}
            ORDER BY updated_at DESC, place_id DESC
            LIMIT 500
        ";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];
        if ($types !== "") $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();

        $matches = [];
        while ($row = $res->fetch_assoc()) {
            $score = $this->score($candidate, $row);
            if ($score["score"] >= 45) {
                $row["_duplicate_score"] = $score["score"];
                $row["_duplicate_reason"] = $score["reason"];
                $row["_distance_km"] = $score["distance_km"];
                $matches[] = $row;
            }
        }

        usort($matches, fn($a, $b) => $b["_duplicate_score"] <=> $a["_duplicate_score"]);
        return array_slice($matches, 0, max(1, $limit));
    }

    public function hasHighConfidenceDuplicate(array $candidate, ?int $excludePlaceId = null): bool
    {
        $matches = $this->findMatches($candidate, 1, $excludePlaceId);
        return !empty($matches) && (int)$matches[0]["_duplicate_score"] >= 75;
    }

    private function score(array $candidate, array $place): array
    {
        $candName = $this->normalizeName((string)($candidate["name"] ?? ""));
        $placeName = $this->normalizeName((string)($place["name"] ?? ""));
        $nameScore = 0.0;
        if ($candName !== "" && $placeName !== "") {
            similar_text($candName, $placeName, $pct);
            $nameScore = $pct;
            if ($candName === $placeName) $nameScore = 100.0;
        }

        $candLat = $this->numOrNull($candidate["latitude"] ?? null);
        $candLng = $this->numOrNull($candidate["longitude"] ?? null);
        $placeLat = $this->numOrNull($place["latitude"] ?? null);
        $placeLng = $this->numOrNull($place["longitude"] ?? null);
        $distanceKm = null;
        $geoScore = 0.0;
        if ($candLat !== null && $candLng !== null && $placeLat !== null && $placeLng !== null) {
            $distanceKm = $this->distanceKm($candLat, $candLng, $placeLat, $placeLng);
            if ($distanceKm <= 0.10) $geoScore = 100.0;
            elseif ($distanceKm <= 0.30) $geoScore = 85.0;
            elseif ($distanceKm <= 1.00) $geoScore = 60.0;
            elseif ($distanceKm <= 3.00) $geoScore = 35.0;
        }

        $stateScore = strcasecmp((string)($candidate["state"] ?? ""), (string)($place["state"] ?? "")) === 0 ? 10 : 0;
        $categoryScore = strcasecmp((string)($candidate["category"] ?? ""), (string)($place["category"] ?? "")) === 0 ? 10 : 0;

        $score = max($nameScore, ($nameScore * 0.65) + ($geoScore * 0.35));
        if ($geoScore >= 85 && $nameScore >= 45) $score = max($score, 82);
        if ($nameScore >= 88 && $stateScore > 0) $score = max($score, 86);
        $score = min(100, (int)round($score + (($stateScore + $categoryScore) * 0.25)));

        $reason = [];
        if ($nameScore >= 88) $reason[] = "very similar name";
        elseif ($nameScore >= 65) $reason[] = "similar name";
        if ($geoScore >= 85) $reason[] = "nearby coordinates";
        elseif ($geoScore >= 60) $reason[] = "same area";
        if ($stateScore > 0) $reason[] = "same state";
        if ($categoryScore > 0) $reason[] = "same category";
        if (empty($reason)) $reason[] = "possible duplicate";

        return [
            "score" => $score,
            "reason" => implode(", ", $reason),
            "distance_km" => $distanceKm,
        ];
    }

    private function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace("/\([^)]*\)/", " ", $value);
        $value = preg_replace("/[^a-z0-9]+/", " ", $value);
        $value = preg_replace("/\b(the|restaurant|restoran|cafe|museum|muzium|temple|park|taman)\b/", " ", $value);
        return trim(preg_replace("/\s+/", " ", $value));
    }

    private function numOrNull($value): ?float
    {
        if ($value === null || $value === "") return null;
        return is_numeric($value) ? (float)$value : null;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function hasColumn(string $table, string $column): bool
    {
        $table = $this->conn->real_escape_string($table);
        $column = $this->conn->real_escape_string($column);
        $res = $this->conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $res && $res->num_rows > 0;
    }
}
