<?php
/**
 * itinerary/transit_route.php
 *
 * AJAX endpoint — returns step-by-step transit directions for a single leg.
 *
 * POST params:
 *   o_lat, o_lng   — origin GPS
 *   d_lat, d_lng   — destination GPS
 *   mode           — 'transit' | 'driving' | 'walking' | 'motorcycle'
 *   depart         — unix timestamp or 'now' (optional)
 *
 * Returns JSON:
 *   { status, total_duration, total_distance, summary, fare, steps[], legs[], warnings[] }
 */
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";
require_once "../services/TransitService.php";

header('Content-Type: application/json; charset=utf-8');

// Auth guard
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Parse inputs
$oLat   = (float)($_POST['o_lat']  ?? 0);
$oLng   = (float)($_POST['o_lng']  ?? 0);
$dLat   = (float)($_POST['d_lat']  ?? 0);
$dLng   = (float)($_POST['d_lng']  ?? 0);
$mode   = strtolower(trim(str_replace('-', '_', (string)($_POST['mode'] ?? 'transit'))));
$mode   = preg_replace('/\s+/', '_', $mode) ?? $mode;
$depart = trim((string)($_POST['depart'] ?? 'now'));

// Validate
if ($oLat == 0 || $oLng == 0 || $dLat == 0 || $dLng == 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid coordinates']);
    exit;
}

// Normalise mode
$googleMode = match($mode) {
    'car', 'driving', 'motorcycle' => 'driving',
    'walking', 'walk'              => 'walking',
    'bicycling', 'bike'            => 'bicycling',
    'public', 'public_transport', 'publictransit', 'public_transit', 'transit', 'bus', 'train' => 'transit',
    default                        => 'transit',
};

$apiKey = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
$svc    = new TransitService($apiKey);

$result = $svc->getRoute($oLat, $oLng, $dLat, $dLng, $googleMode, $depart);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
