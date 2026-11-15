<?php
// api/itinerary_edit.php
// The edit controls of the itinerary page (assets/itinerary_edit.js). One POST endpoint; every
// action maps to one operation of ItineraryEditService, which judges the change by the
// planner's rules and stores it in a transaction of its own, or refuses it and changes nothing.
//
//   POST itinerary_id=<id>, csrf_token=<token> (or the X-CSRF-Token header), action=<one of>:
//     suggest         item_id, [offset]                 -> suggestReplacements() (3 options)
//     replace         item_id, place_id (0: a meal break) -> replaceStop()
//     remove          item_id                           -> removeStop()
//     add             day_no, place_id, [position]      -> addStop()
//     move            item_id, to_day, [to_position]    -> moveStop()
//     regenerate_day  day_no, [more], [less]            -> regenerateDay() (more/less: comma-separated wishes:
//                                                           categories, type:<type> or group:<group> of
//                                                           PlaceTypeClassifier, PlannerRules::wishTokens())
//     set_day_start   day_no (0: every day), start_time (HH:MM, empty: back to the usual start) -> setDayStart()
//     optimise        -                                 -> optimise()
//     replan_rain     -                                 -> replanRainyDays() with the forecast
//
// The answer is the service's result as JSON (ok, status done|refused, code, message, days,
// and the fields of the operation) plus "display": the sentence to show, in the page's
// language. A refused change is an answer with HTTP 200; a request that is not allowed or not
// valid gets status "error" and a 4xx code. Only the traveller who owns the itinerary may
// change it.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// The token is checked below, so a failure answers in JSON.
define('CSRF_DISABLE_AUTO_VERIFY', true);
define('CSRF_DISABLE_AUTO_INJECTION', true);
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/i18n.php';
require_once __DIR__ . '/../config/ai_chat_text.php';
require_once __DIR__ . '/../services/ItineraryEditService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ITINERARY_EDIT_ACTIONS = ['suggest', 'replace', 'remove', 'add', 'move', 'regenerate_day', 'set_day_start', 'optimise', 'replan_rain'];

function itinerary_edit_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function itinerary_edit_error(string $key, int $httpStatus): never
{
    itinerary_edit_reply(['ok' => false, 'status' => 'error', 'code' => null, 'message' => t($key, [], 'en'), 'display' => t($key), 'days' => []], $httpStatus);
}

/** A whole number from a form field: null when empty or absent; anything else is a bad request. */
function itinerary_edit_int(string $name, bool $required, int $min, int $max): ?int
{
    $raw = $_POST[$name] ?? null;
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        if ($required) itinerary_edit_error('edit.err_invalid', 400);
        return null;
    }
    if (!is_string($raw) || !preg_match('/^\d{1,9}$/', trim($raw))) itinerary_edit_error('edit.err_invalid', 400);
    $value = (int)trim($raw);
    if ($value < $min || $value > $max) itinerary_edit_error('edit.err_invalid', 400);
    return $value;
}

/**
 * Comma-separated wishes for a day planned again: categories of the planner's list
 * (PlannerRules::VALID_INTERESTS), "type:<type>" and "group:<group>" of PlaceTypeClassifier
 * (PlannerRules::wishTokens()). Anything else is a bad request.
 */
function itinerary_edit_categories(string $name): array
{
    $raw = $_POST[$name] ?? '';
    if (!is_string($raw) || strlen($raw) > 500) itinerary_edit_error('edit.err_invalid', 400);
    $out = PlannerRules::wishTokens($raw);
    if ($out === null) itinerary_edit_error('edit.err_invalid', 400);
    return $out;
}

// ---- who may ask
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || ($_SESSION['role'] ?? '') !== 'traveller') {
    itinerary_edit_error('edit.err_login', 401);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    itinerary_edit_error('edit.err_invalid', 405);
}
$expected = (string)($_SESSION['csrf_token'] ?? '');
$provided = csrf_request_token();
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    itinerary_edit_error('edit.err_token', 403);
}
$travellerId = (int)($_SESSION['traveller_id'] ?? 0);
$lang = i18n_lang();
session_write_close();   // planning can take a few seconds: do not hold up the traveller's other requests
if ($travellerId <= 0) itinerary_edit_error('edit.err_login', 401);

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ITINERARY_EDIT_ACTIONS, true)) {
    itinerary_edit_error('edit.err_invalid', 400);
}
$itineraryId = itinerary_edit_int('itinerary_id', true, 1, PHP_INT_MAX);

$stmt = $conn->prepare('SELECT itinerary_id, total_days FROM itineraries WHERE itinerary_id = ? AND traveller_id = ? LIMIT 1');
$stmt->bind_param('ii', $itineraryId, $travellerId);
$stmt->execute();
$itinerary = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$itinerary) {
    itinerary_edit_error('edit.err_not_yours', 404);
}
$totalDays = max(1, (int)$itinerary['total_days']);

// ---- the change
try {
    switch ($action) {
        case 'suggest':
            $itemId = itinerary_edit_int('item_id', true, 1, PHP_INT_MAX);
            $offset = itinerary_edit_int('offset', false, 0, 200) ?? 0;
            $result = ItineraryEditService::suggestReplacements($conn, $itineraryId, $itemId, ItineraryEditService::SUGGEST_LIMIT, $offset);
            break;
        case 'replace':
            $itemId = itinerary_edit_int('item_id', true, 1, PHP_INT_MAX);
            $placeId = itinerary_edit_int('place_id', true, 0, PHP_INT_MAX);
            $result = ItineraryEditService::replaceStop($conn, $itineraryId, $itemId, $placeId);
            break;
        case 'remove':
            $itemId = itinerary_edit_int('item_id', true, 1, PHP_INT_MAX);
            $result = ItineraryEditService::removeStop($conn, $itineraryId, $itemId);
            break;
        case 'add':
            $dayNo = itinerary_edit_int('day_no', true, 1, $totalDays);
            $placeId = itinerary_edit_int('place_id', true, 1, PHP_INT_MAX);
            $position = itinerary_edit_int('position', false, 1, 50);
            $result = ItineraryEditService::addStop($conn, $itineraryId, $dayNo, $placeId, $position);
            break;
        case 'move':
            $itemId = itinerary_edit_int('item_id', true, 1, PHP_INT_MAX);
            $toDay = itinerary_edit_int('to_day', true, 1, $totalDays);
            $toPosition = itinerary_edit_int('to_position', false, 1, 50);
            $result = ItineraryEditService::moveStop($conn, $itineraryId, $itemId, $toDay, $toPosition);
            break;
        case 'regenerate_day':
            $dayNo = itinerary_edit_int('day_no', true, 1, $totalDays);
            $more = itinerary_edit_categories('more');
            $less = itinerary_edit_categories('less');
            $result = ItineraryEditService::regenerateDay($conn, $itineraryId, $dayNo, ['more' => $more, 'less' => array_values(array_diff($less, $more))]);
            break;
        case 'set_day_start':
            $dayNo = itinerary_edit_int('day_no', true, 0, $totalDays);
            $raw = $_POST['start_time'] ?? '';
            if (!is_string($raw)) itinerary_edit_error('edit.err_invalid', 400);
            $raw = trim($raw);
            $minute = null;
            if ($raw !== '') {
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw, $m)) itinerary_edit_error('edit.err_time', 400);
                $minute = (int)$m[1] * 60 + (int)$m[2];
            }
            $result = ItineraryEditService::setDayStart($conn, $itineraryId, $dayNo > 0 ? $dayNo : null, $minute);
            break;
        case 'optimise':
            $result = ItineraryEditService::optimise($conn, $itineraryId);
            break;
        default:   // replan_rain
            $result = ItineraryEditService::replanRainyDays($conn, $itineraryId);
            break;
    }
} catch (Throwable $e) {
    error_log('itinerary_edit (' . $action . '): ' . $e->getMessage());
    itinerary_edit_error('edit.err_failed', 500);
}

// ---- the sentence to show, in the page's language (English: the service's own sentence)
$display = (string)$result['message'];
if ($lang !== 'en') {
    if (!$result['ok']) {
        $key = 'reason_code_' . (string)$result['code'];
        $reason = ai_text($key, $lang);
        if ($reason === $key) $reason = ai_text('reason_code_other', $lang);
        $display = t('edit.refused', ['reason' => $reason]);
    } elseif ($action === 'suggest') {
        $display = $result['options'] ? t('edit.suggest_found', ['count' => count($result['options'])]) : t('edit.suggest_none');
    } elseif ($action === 'replan_rain') {
        $parts = [];
        foreach ((array)($result['changes'] ?? []) as $day => $change) {
            $vars = ['day' => (int)$day, 'chance' => (int)$change['rain']];
            $parts[] = $change['status'] === 'done' ? t('edit.rain_day_done', $vars) : t('edit.rain_day_kept', $vars);
        }
        $display = $parts ? implode(' ', $parts) : t('edit.rain_none');
    } else {
        $display = t('edit.done');
    }
}
itinerary_edit_reply($result + ['display' => $display]);
