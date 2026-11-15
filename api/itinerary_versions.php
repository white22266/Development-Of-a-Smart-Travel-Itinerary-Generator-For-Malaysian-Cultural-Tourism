<?php
// api/itinerary_versions.php
// Alternative versions of a plan, for the review page (itinerary/itinerary_review.php, "Compare
// other versions"). A version is the same trip planned again in another style by
// ItineraryGenerator::createVersion() and stored as an itinerary of its own, marked as a version
// of the first (itineraries.version_of, plan_style); it is not one of the traveller's trips
// until it is chosen. One POST endpoint:
//
//   POST itinerary_id=<the itinerary under review>, csrf_token=<token> (or the X-CSRF-Token
//   header), action=<one of>:
//     create  style (relaxed | budget | interest)  -> createVersion(): one version at a time, one
//                                                     per style, at most three per itinerary
//     choose  version_id (a version of itinerary_id, or itinerary_id itself to keep the first
//             plan)                                -> ItineraryEditService::chooseVersion(): that
//                                                     one is the itinerary under review, the
//                                                     others are removed
//
// The answer (JSON): ok, status (done | refused), code (null, or a reason code: VERSION_LIMIT,
// VERSION_EXISTS, NOT_FOUND, UNAVAILABLE, NO_CHANGE), message (English), display (the sentence in
// the page's language), and for create version_id and versions (the versions now: itinerary_id,
// plan_style, created_at), for choose itinerary_id (the one that remains). A refused request is an
// answer with HTTP 200; a request that is not allowed or not valid gets status "error" and a 4xx
// code. Only the traveller who owns the itinerary may ask.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// The token is checked below, so a failure answers in JSON.
define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/ai_chat_text.php';
require_once __DIR__ . '/../services/ItineraryGenerator.php';
require_once __DIR__ . '/../services/ItineraryEditService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ITINERARY_VERSION_ACTIONS = ['create', 'choose'];

function itinerary_versions_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function itinerary_versions_error(string $key, int $httpStatus): never
{
    itinerary_versions_reply(['ok' => false, 'status' => 'error', 'code' => null, 'message' => t($key, [], 'en'), 'display' => t($key)], $httpStatus);
}

/** A whole number from a form field; anything else is a bad request. */
function itinerary_versions_int(string $name): int
{
    $raw = $_POST[$name] ?? null;
    if (!is_string($raw) || !preg_match('/^\d{1,9}$/', trim($raw)) || (int)trim($raw) < 1) itinerary_versions_error('edit.err_invalid', 400);
    return (int)trim($raw);
}

// ---- who may ask
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'traveller') {
    itinerary_versions_error('edit.err_login', 401);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    itinerary_versions_error('edit.err_invalid', 405);
}
$expected = (string)($_SESSION['csrf_token'] ?? '');
$provided = csrf_request_token();
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    itinerary_versions_error('edit.err_token', 403);
}
$travellerId = (int)($_SESSION['traveller_id'] ?? 0);
$lang = i18n_lang();
session_write_close();   // planning a version takes a few seconds: do not hold up the traveller's other requests
if ($travellerId <= 0) itinerary_versions_error('edit.err_login', 401);

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ITINERARY_VERSION_ACTIONS, true)) {
    itinerary_versions_error('edit.err_invalid', 400);
}
$itineraryId = itinerary_versions_int('itinerary_id');
$style = null;
$versionId = null;
if ($action === 'create') {
    $style = $_POST['style'] ?? '';
    if (!is_string($style) || !in_array($style, PlannerRules::STYLES, true)) itinerary_versions_error('edit.err_invalid', 400);
} else {
    $versionId = itinerary_versions_int('version_id');
}

// ---- the itinerary is the traveller's own, and not a version itself
if (!ItineraryEditService::hasVersions($conn)) {
    itinerary_versions_reply(['ok' => false, 'status' => 'refused', 'code' => 'UNAVAILABLE', 'message' => 'Versions of a plan cannot be stored on this database yet.',
        'display' => i18n_refusal('UNAVAILABLE', 'Versions of a plan cannot be stored on this database yet.')]);
}
$stmt = $conn->prepare('SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? AND version_of IS NULL LIMIT 1');
$stmt->bind_param('ii', $itineraryId, $travellerId);
$stmt->execute();
$own = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$own) {
    itinerary_versions_error('edit.err_not_yours', 404);
}

// ---- the request
try {
    if ($action === 'create') {
        $result = (new ItineraryGenerator($conn))->createVersion($travellerId, $itineraryId, $style);
        $result['versions'] = ItineraryEditService::versionList($conn, $itineraryId);
    } else {
        $result = ItineraryEditService::chooseVersion($conn, $itineraryId, $versionId);
    }
} catch (Throwable $e) {
    error_log('itinerary_versions (' . $action . '): ' . $e->getMessage());
    itinerary_versions_error('edit.err_failed', 500);
}

// ---- the sentence to show, in the page's language
$result['display'] = $result['ok']
    ? t($action === 'create' ? 'rv.version_made' : 'rv.version_chosen', ['style' => t('rv.style_' . ($style ?? 'standard'))])
    : i18n_refusal((string)$result['code'], (string)$result['message'], $lang);
itinerary_versions_reply($result);
