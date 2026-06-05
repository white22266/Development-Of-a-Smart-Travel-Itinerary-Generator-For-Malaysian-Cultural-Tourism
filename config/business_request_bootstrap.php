<?php
// config/business_request_bootstrap.php
// Page/API-specific business hooks. Keep db_connect.php focused only on database connectivity.

$businessScriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

if (in_array($businessScriptName, ['ai_itinerary_editor.php', 'review_replace.php'], true)) {
    require_once __DIR__ . '/../services/ItineraryRuleValidationService.php';
    ItineraryRuleValidationService::validateCurrentRequestIfNeeded($conn);
}

if ($businessScriptName === 'itinerary_view.php') {
    ob_start(static function (string $html): string {
        $asset = '<script src="../assets/itinerary_route_map_fix.js?v=20260606-business-bootstrap"></script>';
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
