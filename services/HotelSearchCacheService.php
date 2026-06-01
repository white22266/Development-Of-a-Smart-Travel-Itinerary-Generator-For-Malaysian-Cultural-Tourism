<?php
/**
 * HotelSearchCacheService
 *
 * Stores live hotel search results in MySQL to reduce external API calls.
 * Cache key is based on the final stop context and rounded coordinates.
 */
class HotelSearchCacheService
{
    private mysqli $conn;
    private int $ttlHours;

    public function __construct(mysqli $conn, int $ttlHours = 168)
    {
        $this->conn = $conn;
        $this->ttlHours = max(1, $ttlHours);
        $this->ensureTable();
    }

    public function makeKey(float $lat, float $lng, string $placeName = '', string $state = '', string $district = ''): string
    {
        $payload = [
            'lat' => round($lat, 4),
            'lng' => round($lng, 4),
            'place' => strtolower(trim($placeName)),
            'state' => strtolower(trim($state)),
            'district' => strtolower(trim($district)),
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function get(string $cacheKey): ?array
    {
        $stmt = $this->conn->prepare("SELECT hotels_json, source, search_count, expires_at FROM hotel_search_cache WHERE cache_key = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('s', $cacheKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return null;
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }

        $hotels = json_decode((string)$row['hotels_json'], true);
        if (!is_array($hotels)) return null;

        return [
            'hotels' => $hotels,
            'source' => (string)($row['source'] ?? 'cache'),
            'search_count' => (int)($row['search_count'] ?? 0),
            'expires_at' => (string)($row['expires_at'] ?? ''),
        ];
    }

    public function set(
        string $cacheKey,
        float $lat,
        float $lng,
        string $placeName,
        string $state,
        string $district,
        array $hotels,
        string $source
    ): void {
        if (empty($hotels)) return;

        $hotelsJson = json_encode(array_values($hotels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($hotelsJson === false) return;

        $expiresAt = date('Y-m-d H:i:s', time() + ($this->ttlHours * 3600));
        $stmt = $this->conn->prepare("INSERT INTO hotel_search_cache
            (cache_key, final_stop_name, state, district, latitude, longitude, source, hotels_json, search_count, last_searched_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                final_stop_name = VALUES(final_stop_name),
                state = VALUES(state),
                district = VALUES(district),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                source = VALUES(source),
                hotels_json = VALUES(hotels_json),
                search_count = search_count + 1,
                last_searched_at = NOW(),
                expires_at = VALUES(expires_at)");
        if (!$stmt) return;
        $stmt->bind_param('ssssddsss', $cacheKey, $placeName, $state, $district, $lat, $lng, $source, $hotelsJson, $expiresAt);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS hotel_search_cache (
            cache_id INT AUTO_INCREMENT PRIMARY KEY,
            cache_key CHAR(64) NOT NULL UNIQUE,
            final_stop_name VARCHAR(255) NOT NULL,
            state VARCHAR(100) DEFAULT NULL,
            district VARCHAR(100) DEFAULT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            source VARCHAR(60) NOT NULL DEFAULT 'unknown',
            hotels_json LONGTEXT NOT NULL,
            search_count INT NOT NULL DEFAULT 1,
            last_searched_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hotel_cache_location (latitude, longitude),
            INDEX idx_hotel_cache_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->conn->query($sql);
    }
}
