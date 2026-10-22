<?php
// config/business_request_bootstrap.php
// Page/API-specific business hooks. Keep db_connect.php focused only on database connectivity.

$businessScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

// Planner and review replacement must never see festivals with missing or invalid
// dates. This connection-scoped pool shadows cultural_places only for the current
// request and never modifies the permanent place records. (The AI editor filters
// festivals itself and validates every change with ItineraryRuleValidationService.)
if (in_array($businessScriptName, ['generate_itinerary.php', 'review_replace.php'], true)) {
    require_once __DIR__ . '/../services/StrictFestivalCandidatePoolService.php';
    StrictFestivalCandidatePoolService::install($conn);
}

if ($businessScriptName === 'itinerary_view.php') {
    ob_start(static function (string $html): string {
        $asset = '<script src="../assets/itinerary_route_map_fix.js?v=20260606-single-map-controller"></script>';
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
