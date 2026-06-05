<?php
// config/db_connect.php
// Database connection configuration.
// Configure values through server environment variables or a local .env file.

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/security.php';

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS');
if ($pass === false) {
    $pass = '';
}
$db = getenv('DB_NAME') ?: 'travel_itinerary_db';

try {
    $conn = new mysqli($host, $user, $pass, $db);
} catch (mysqli_sql_exception $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('System temporarily unavailable. Please try again later.');
}

if ($conn->connect_error) {
    error_log('Database connection failed.');
    http_response_code(500);
    die('System temporarily unavailable. Please try again later.');
}

if (!$conn->set_charset('utf8mb4')) {
    error_log('Database charset setup failed.');
    http_response_code(500);
    die('System temporarily unavailable. Please try again later.');
}

// Load the official-origin route-map controller only on itinerary_view.php.
// Output buffering keeps this isolated from JSON endpoints and other pages.
if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'itinerary_view.php') {
    ob_start(static function (string $html): string {
        $asset = '<script src="../assets/itinerary_route_map_fix.js?v=20260605-part1"></script>';
        if (str_contains($html, 'itinerary_route_map_fix.js')) {
            return $html;
        }
        $bodyEnd = strripos($html, '</body>');
        if ($bodyEnd === false) {
            return $html . $asset;
        }
        return substr($html, 0, $bodyEnd) . $asset . "\n" . substr($html, $bodyEnd);
    });
}
