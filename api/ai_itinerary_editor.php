<?php
// api/ai_itinerary_editor.php
// Itinerary editor for the chat: proposes one replacement, addition, removal, move, a day planned
// again (with more or less of some categories), a shorter route, or a new start time for one day
// or every day, and saves it only when the traveller presses a confirmation button.
// Every proposal is checked with ItineraryRuleValidationService, and every confirmation is made by
// ItineraryEditService, which judges it by the planner's rules and times the day again.
// Requests may be in English, Malay or Chinese. The local model reads the request first
// (ItineraryEditIntentService); when it is not running or gives no valid intent, the regular
// expressions below handle English requests; otherwise the traveller is asked to say it again.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/db_connect.php";
require_once "../config/ai_language_guard.php";
require_once "../services/CostEstimationService.php";
require_once "../services/ItineraryEditService.php";
require_once "../services/ItineraryRuleValidationService.php";
require_once "../services/ItineraryEditIntentService.php";

header("Content-Type: application/json; charset=utf-8");

$message = trim((string)($_POST["message"] ?? ""));
editor_lang(ai_request_language($message));

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    editor_reply(["status" => "error", "answer" => ai_text("unauthorized", editor_lang())], 401);
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
$itineraryId = (int)($_POST["itinerary_id"] ?? 0);
$action = trim((string)($_POST["action"] ?? "recommend"));
$lang = editor_lang();

if ($travellerId <= 0 || $itineraryId <= 0) {
    editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_request", $lang)], 400);
}

$itinerary = load_itinerary($conn, $itineraryId, $travellerId);
if (!$itinerary) {
    editor_reply(["status" => "error", "answer" => ai_text("itinerary_not_found", $lang)], 404);
}

if ($action === "confirm") {
    $itemId = (int)($_POST["item_id"] ?? 0);
    $placeId = (int)($_POST["place_id"] ?? 0);
    if ($itemId <= 0 || $placeId <= 0) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_replacement", $lang)]);
    }

    $item = load_item($conn, $itineraryId, $itemId);
    $place = load_place($conn, $placeId);
    if (!$item || !$place) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_replacement_not_found", $lang)]);
    }

    // Judged and saved by the planner's rules: the day is timed again, or nothing changes.
    $result = ItineraryEditService::replaceStop($conn, $itineraryId, $itemId, $placeId);
    if (!$result["ok"]) {
        editor_refusal($result);
    }

    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_updated", $lang),
        "updated_item" => [
            "item_id" => $itemId,
            "place_id" => $placeId,
            "name" => $place["name"],
        ],
    ]);
}

if ($action === "confirm_add") {
    $dayNo = (int)($_POST["day_no"] ?? 0);
    $placeId = (int)($_POST["place_id"] ?? 0);
    if ($dayNo <= 0 || $placeId <= 0 || $dayNo > (int)$itinerary["total_days"]) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_add", $lang)]);
    }

    $place = load_place($conn, $placeId);
    if (!$place) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_place_not_found", $lang)]);
    }

    // Added where it keeps every rule with the least travelling, and the day timed again.
    $result = ItineraryEditService::addStop($conn, $itineraryId, $dayNo, $placeId);
    if (!$result["ok"]) {
        editor_refusal($result);
    }

    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_added", $lang),
        "added_place" => [
            "day_no" => $dayNo,
            "place_id" => $placeId,
            "name" => $place["name"],
        ],
    ]);
}

if ($action === "confirm_retime") {
    $startTime = normalize_requested_start_time((string)($_POST["start_time"] ?? ""));
    $dayNo = editor_int($_POST["day_no"] ?? "0");
    if ($startTime === null || $dayNo === null || $dayNo < 0 || $dayNo > (int)$itinerary["total_days"]) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_time", $lang)]);
    }

    // The start is stored (the whole trip, or one day) and the day(s) timed again by the
    // planner's rules, or nothing changes. Later re-timings keep it.
    [$hour, $minute] = array_map("intval", explode(":", $startTime));
    $result = ItineraryEditService::setDayStart($conn, $itineraryId, $dayNo > 0 ? $dayNo : null, $hour * 60 + $minute);
    if (!$result["ok"]) {
        editor_refusal($result);
    }

    $label = display_time_label($startTime);
    editor_reply([
        "status" => "success",
        "answer" => $dayNo > 0 ? ai_text("edit_done_day_start", $lang, ["day" => $dayNo, "time" => $label]) : ai_text("edit_done_trip_start", $lang, ["time" => $label]),
        "reload" => true,
    ]);
}

if ($action === "confirm_remove") {
    $itemId = editor_int($_POST["item_id"] ?? "");
    $item = $itemId !== null && $itemId > 0 ? load_item($conn, $itineraryId, $itemId) : null;
    if (!$item) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_request", $lang)]);
    }
    $result = ItineraryEditService::removeStop($conn, $itineraryId, $itemId);
    if (!$result["ok"]) {
        editor_refusal($result);
    }
    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_done_removed", $lang, ["title" => (string)$item["item_title"], "day" => (int)$item["day_no"]]),
        "reload" => true,
    ]);
}

if ($action === "confirm_move") {
    $itemId = editor_int($_POST["item_id"] ?? "");
    $toDay = editor_int($_POST["day_no"] ?? "");
    $position = editor_int($_POST["position"] ?? "");
    $item = $itemId !== null && $itemId > 0 ? load_item($conn, $itineraryId, $itemId) : null;
    if (!$item || $toDay === null || $toDay < 1 || $toDay > (int)$itinerary["total_days"] || ($position !== null && ($position < 1 || $position > 50))) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_request", $lang)]);
    }
    $result = ItineraryEditService::moveStop($conn, $itineraryId, $itemId, $toDay, $position);
    if (!$result["ok"]) {
        editor_refusal($result);
    }
    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_done_moved", $lang, ["title" => (string)$item["item_title"], "day" => $toDay, "position" => (int)($result["position"] ?? 0)]),
        "reload" => true,
    ]);
}

if ($action === "confirm_regenerate") {
    $dayNo = editor_int($_POST["day_no"] ?? "");
    $more = editor_categories((string)($_POST["more"] ?? ""));
    $less = editor_categories((string)($_POST["less"] ?? ""));
    if ($dayNo === null || $dayNo < 1 || $dayNo > (int)$itinerary["total_days"] || $more === null || $less === null) {
        editor_reply(["status" => "error", "answer" => ai_text("edit_invalid_request", $lang)]);
    }
    $result = ItineraryEditService::regenerateDay($conn, $itineraryId, $dayNo, ["more" => $more, "less" => $less]);
    if (!$result["ok"]) {
        editor_refusal($result);
    }
    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_done_regenerated", $lang, ["day" => $dayNo, "stops" => (int)($result["stops"] ?? 0)]),
        "reload" => true,
    ]);
}

if ($action === "confirm_optimise") {
    $result = ItineraryEditService::optimise($conn, $itineraryId);
    if (!$result["ok"]) {
        editor_refusal($result);
    }
    $report = (array)($result["report"] ?? []);
    $days = (array)($report["reordered_days"] ?? []);
    $answer = $days ? ai_text("edit_done_optimised", $lang, ["days" => implode(", ", $days)]) : ai_text("edit_done_optimised_same", $lang);
    if (!empty($report["removed"])) {
        $answer .= " " . ai_text("edit_done_optimised_removed", $lang, ["list" => implode("; ", (array)$report["removed"])]);
    }
    editor_reply(["status" => "success", "answer" => $answer, "reload" => true]);
}

// ---------------------------------------------------------------- a chat message: work out the change
if ($message === "") {
    editor_reply(["status" => "success", "answer" => ai_text("edit_clarify", $lang), "proposals" => [], "source" => "clarify"]);
}

$items = load_editable_items($conn, $itineraryId);
$totalDays = max(1, (int)($itinerary["total_days"] ?? 1));

// 1. The local model.
$intentService = new ItineraryEditIntentService();
$intent = $intentService->detect($message, $items, $totalDays, $lang);
$modelDown = $intent === null && in_array($intentService->lastFailure(), ["unreachable", "timeout", "model_error"], true);
if ($intent !== null && $intent["action"] !== "none") {
    handle_model_intent($conn, $travellerId, $itinerary, $items, $message, $intent, $lang);
}

// 2. The regular expressions, for English requests they recognise.
if ($lang === "en" && regex_sees_edit_request($message)) {
    run_regex_editor($conn, $travellerId, $itinerary, $items, $message, $lang);
}

// 3. Neither understood it: ask again, in the traveller's language.
$answer = ai_text("edit_clarify", $lang);
if ($modelDown && $lang !== "en") {
    $answer .= " " . ai_text("edit_model_down", $lang);
}
editor_reply(["status" => "success", "answer" => $answer, "proposals" => [], "source" => $modelDown ? "clarify_model_down" : "clarify"]);

// ================================================================ replies

/** The language of this request; set once at the start. */
function editor_lang(?string $set = null): string
{
    static $lang = "en";
    if ($set !== null) $lang = in_array($set, AI_LANGUAGES, true) ? $set : "en";
    return $lang;
}

function editor_reply(array $payload, int $httpStatus = 200): never
{
    http_response_code($httpStatus);
    $payload["lang"] = editor_lang();
    $payload["ui"] = ai_ui_labels(editor_lang());
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Act on a validated model intent. Replace, add and retime use the same paths as the rules below. */
function handle_model_intent(mysqli $conn, int $travellerId, array $itinerary, array $items, string $message, array $intent, string $lang): never
{
    $target = null;
    foreach ($items as $item) {
        if ((int)$item["item_id"] === (int)($intent["stop_id"] ?? 0)) $target = $item;
    }
    // The model's English wish ("cheaper") lets the rules see wishes written in any language.
    $wishText = trim($message . " " . (string)($intent["wish"] ?? ""));

    switch ($intent["action"]) {
        case "replace":
            reply_replacement($conn, $travellerId, $itinerary, $items, $target, $wishText, (string)($intent["place_name"] ?? ""), (string)($intent["category"] ?? ""), "model");

        case "add":
            if ((string)($intent["place_name"] ?? "") !== "") {
                reply_named_addition($conn, $travellerId, $itinerary, $items, $intent);
            }
            $additions = build_addition_proposals($conn, $travellerId, $itinerary, $items, $wishText, $intent["day"], (string)($intent["category"] ?? ""));
            reply_additions($additions, "model");

        case "retime":
            if ($target !== null) {
                reply_retime_stop($intent, $target, $lang);
            }
            $day = ((int)$itinerary["total_days"] > 1 && $intent["day"] !== null) ? (int)$intent["day"] : 0;
            reply_retime_pending($intent["time"] . ":00", "model", "", $day);

        case "remove":
        case "move":
        case "regenerate_day":
        case "optimise":
            reply_change_pending($intent, $target, $lang);

        default:
            reply_not_available($intent, $target, $lang);
    }
}

/** The existing rule-based editor for English requests. */
function run_regex_editor(mysqli $conn, int $travellerId, array $itinerary, array $items, string $message, string $lang): never
{
    $requestedStartTime = extract_requested_start_time($message);
    if ($requestedStartTime !== null && is_timetable_rearrange_request($message)) {
        $day = parse_stop_reference($message)["day"];
        reply_retime_pending($requestedStartTime, "local_rule", "", ($day >= 1 && $day <= (int)$itinerary["total_days"] && (int)$itinerary["total_days"] > 1) ? $day : 0);
    }

    // An explicit replace request ("change day 2 stop 1 to X") is never treated as an add request.
    if (!is_replace_request($message) && is_add_or_regenerate_request($message)) {
        reply_additions(build_addition_proposals($conn, $travellerId, $itinerary, $items, $message), "local_rule");
    }

    if (empty($items)) {
        editor_reply(["status" => "success", "answer" => ai_text("edit_no_stops", $lang), "proposals" => []]);
    }

    $reference = parse_stop_reference($message);
    $target = select_target_item($items, $message, $reference);
    if ($target === null) {
        editor_reply([
            "status" => "success",
            "answer" => describe_missing_stop($items, $reference),
            "proposals" => [],
            "source" => "local_rule",
        ]);
    }
    reply_replacement($conn, $travellerId, $itinerary, $items, $target, $message, extract_requested_replacement_name($message), null, "local_rule");
}

/** Does the English request look like any change the rules can handle? */
function regex_sees_edit_request(string $message): bool
{
    if (extract_requested_start_time($message) !== null && is_timetable_rearrange_request($message)) return true;
    if (is_replace_request($message) || is_add_or_regenerate_request($message)) return true;
    $reference = parse_stop_reference($message);
    if ($reference["day"] > 0 || $reference["stop"] > 0) return true;
    // The other change words of EDIT_WORDS in assets/trip_ai_chat.js.
    return (bool)preg_match('/\b(?:change|modify|regenerate|replan|reroute|alternatives?|another\s+(?:place|stop)|dislike|don\'?t\s+(?:want|like)|do\s+not\s+(?:want|like)|too\s+(?:far|expensive|crowded|tiring)|cheaper|lower\s+cost|reduce\s+cost|save\s+money|budget\s+friendly|improve)\b/i', $message);
}

/**
 * Offer one replacement for $target: the named place, or the best alternative of the wanted
 * category. $categoryOverride null = read the category from the message, "" = keep the stop's own.
 */
function reply_replacement(mysqli $conn, int $travellerId, array $itinerary, array $items, ?array $target, string $message, string $requestedName, ?string $categoryOverride, string $source): never
{
    $lang = editor_lang();
    if ($target === null) {
        editor_reply(["status" => "success", "answer" => ai_text("edit_clarify", $lang), "proposals" => [], "source" => $source]);
    }
    $usedPlaceIds = array_values(array_filter(array_map(fn($item) => (int)($item["place_id"] ?? 0), $items)));

    if ($requestedName !== "") {
        // The traveller named the new place: offer exactly that place or explain why not.
        $outcome = find_requested_place_by_name($conn, $travellerId, $itinerary, $target, $requestedName, $usedPlaceIds);
        if ($outcome["place"] === null) {
            editor_reply(["status" => "success", "answer" => $outcome["message"], "proposals" => [], "source" => $source]);
        }
        $newPlace = $outcome["place"];
        $reason = ai_text("reason_requested", $lang);
    } else {
        $newPlace = find_alternative_place($conn, $travellerId, $itinerary, $target, $usedPlaceIds, $message, $categoryOverride);
        if ($newPlace === null) {
            editor_reply([
                "status" => "success",
                "answer" => ai_text("edit_replace_none", $lang, ["title" => $target["item_title"]]),
                "proposals" => [],
                "source" => $source,
            ]);
        }
        $reason = build_replacement_reason($target, $newPlace);
    }

    $proposal = [
        "item_id" => (int)$target["item_id"],
        "current_title" => (string)$target["item_title"],
        "day_no" => (int)$target["day_no"],
        "sequence_no" => (int)$target["sequence_no"],
        "current_category" => (string)($target["category"] ?? $target["item_type"]),
        "new_place" => format_place($newPlace),
        "reason" => $reason,
    ];

    // The answer is built from the proposal itself, so the text always matches the confirmation card.
    editor_reply([
        "status" => "success",
        "answer" => build_local_editor_answer($proposal),
        "proposals" => [$proposal],
        "source" => $source,
    ]);
}

function reply_additions(array $additions, string $source): never
{
    $lang = editor_lang();
    editor_reply([
        "status" => "success",
        "answer" => empty($additions) ? ai_text("edit_add_none", $lang) : build_local_addition_answer($additions),
        "proposals" => $additions,
        "source" => $source,
    ]);
}

/** Add the place the traveller named, after the same rule check the confirm step uses. */
function reply_named_addition(mysqli $conn, int $travellerId, array $itinerary, array $items, array $intent): never
{
    $totalDays = max(1, (int)($itinerary["total_days"] ?? 1));
    $dayNo = $intent["day"] !== null ? (int)$intent["day"] : requested_day_no("", $totalDays, $items);
    $slot = ["item_id" => 0, "place_id" => 0, "day_no" => $dayNo, "district" => "", "state" => ""];
    $usedPlaceIds = array_values(array_filter(array_map(fn($item) => (int)($item["place_id"] ?? 0), $items)));

    $outcome = find_requested_place_by_name($conn, $travellerId, $itinerary, $slot, (string)$intent["place_name"], $usedPlaceIds, $items);
    if ($outcome["place"] === null) {
        editor_reply(["status" => "success", "answer" => $outcome["message"], "proposals" => [], "source" => "model"]);
    }
    $existingDayCount = count(array_filter($items, fn($item) => (int)$item["day_no"] === $dayNo));
    reply_additions([addition_proposal($outcome["place"], $dayNo, $existingDayCount, ai_text("reason_requested", editor_lang()))], "model");
}

/** A timetable change, waiting for the Confirm Timetable button: every day, or day $dayNo (> 0). */
function reply_retime_pending(string $startTime, string $source, string $note = "", int $dayNo = 0): never
{
    $lang = editor_lang();
    $label = display_time_label($startTime);
    $pending = [
        "type" => "retime_itinerary",
        "start_time" => $startTime,
        "day_no" => $dayNo > 0 ? $dayNo : null,
        "label" => $label,
        "summary" => $dayNo > 0
            ? ai_text("edit_retime_day_summary", $lang, ["day" => $dayNo, "time" => $label])
            : ai_text("edit_retime_summary", $lang, ["time" => $label]),
    ];
    $answer = $dayNo > 0
        ? ai_text("edit_retime_day_detected", $lang, ["day" => $dayNo, "time" => $label])
        : ai_text("edit_retime_detected", $lang, ["time" => $label]);
    editor_reply([
        "status" => "success",
        "answer" => $answer . ($note !== "" ? " " . $note : ""),
        "pending_action" => $pending,
        "pending_actions" => [$pending],
        "source" => $source,
    ]);
}

/**
 * Remove, move, plan a day again or optimise: a change waiting for its confirm button. The
 * confirm step (confirm_remove, confirm_move, confirm_regenerate, confirm_optimise) makes it
 * through ItineraryEditService, which judges it by the planner's rules.
 */
function reply_change_pending(array $intent, ?array $target, string $lang): never
{
    $title = $target !== null ? (string)$target["item_title"] : "";
    $day = (int)($intent["day"] ?? 0);
    $pending = ["available" => true, "day_no" => $intent["day"], "item_id" => $intent["stop_id"], "intent" => $intent];
    switch ($intent["action"]) {
        case "remove":
            $what = ai_text("what_remove", $lang, ["title" => $title, "day" => $day]);
            $pending += ["type" => "remove_stop", "summary" => ai_text("pending_remove_summary", $lang, ["title" => $title, "day" => $day])];
            break;
        case "move":
            $position = $intent["position"] !== null ? ai_text("what_move_position", $lang, ["position" => $intent["position"]]) : "";
            $what = ai_text("what_move", $lang, ["title" => $title, "day" => $day, "position" => $position]);
            $pending += ["type" => "move_stop", "position" => $intent["position"],
                         "summary" => ai_text("pending_move_summary", $lang, ["title" => $title, "day" => $day, "position" => $position])];
            break;
        case "regenerate_day":
            $more = array_values((array)($intent["more"] ?? []));
            $less = array_values((array)($intent["less"] ?? []));
            $wishes = ($more ? ai_text("what_more", $lang, ["list" => category_list_text($more, $lang)]) : "")
                . ($less ? ai_text("what_less", $lang, ["list" => category_list_text($less, $lang)]) : "");
            $what = ai_text("what_regenerate_day", $lang, ["day" => $day]) . $wishes;
            $pending += ["type" => "regenerate_day", "more" => $more, "less" => $less,
                         "summary" => ai_text("pending_regenerate_summary", $lang, ["day" => $day, "wishes" => $wishes])];
            break;
        default:   // optimise: the optimiser orders every day; each stop stays on its day
            $pending += ["type" => "optimise_route", "summary" => ai_text("pending_optimise_summary", $lang)];
            editor_reply([
                "status" => "success",
                "answer" => ai_text("edit_optimise_offer", $lang),
                "pending_action" => $pending,
                "pending_actions" => [$pending],
                "proposals" => [],
                "source" => "model",
            ]);
    }
    $pending["label"] = $what;
    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_confirm_offer", $lang, ["what" => $what]),
        "pending_action" => $pending,
        "pending_actions" => [$pending],
        "proposals" => [],
        "source" => "model",
    ]);
}

/**
 * Retiming a single stop: the rules time each stop from the one before it, so a stop cannot be
 * pinned to a clock time. The answer offers moving the stop or changing the day's start.
 */
function reply_retime_stop(array $intent, array $target, string $lang): never
{
    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_retime_stop_offer", $lang, [
            "title" => (string)$target["item_title"],
            "day" => (int)$target["day_no"],
            "time" => (string)($intent["time"] ?? "09:00"),
        ]),
        "proposals" => [],
        "source" => "model",
    ]);
}

/** Category codes as words of the language: "museums, food places". */
function category_list_text(array $categories, string $lang): string
{
    return implode(ai_text("list_separator", $lang), array_map(fn($c) => ai_text("cat_" . $c, $lang), $categories));
}

/** A refused change in the traveller's language, with its reason; nothing was changed. */
function editor_refusal(array $result): never
{
    $lang = editor_lang();
    $code = (string)($result["code"] ?? "");
    if ($lang === "en" && (string)($result["message"] ?? "") !== "") {
        $answer = ai_text("edit_refused_detail", "en", ["message" => (string)$result["message"]]);
    } else {
        $key = "reason_code_" . $code;
        $reason = ai_text($key, $lang);
        if ($reason === $key) $reason = ai_text("reason_code_other", $lang);
        $answer = ai_text("edit_refused", $lang, ["reason" => $reason]);
    }
    editor_reply(["status" => "error", "answer" => $answer, "code" => $code !== "" ? $code : null]);
}

/** A whole number from a form field: null when empty or absent, -1 when not a whole number. */
function editor_int($value): ?int
{
    $value = trim((string)$value);
    if ($value === "") return null;
    return preg_match('/^\d{1,9}$/', $value) ? (int)$value : -1;
}

/** Comma-separated categories of the planner's list; null when one is unknown. */
function editor_categories(string $value): ?array
{
    $out = [];
    foreach (array_filter(array_map("trim", explode(",", strtolower($value))), "strlen") as $category) {
        if (!in_array($category, PlannerRules::VALID_INTERESTS, true)) return null;
        if (!in_array($category, $out, true)) $out[] = $category;
    }
    return $out;
}

/**
 * An action the chat cannot carry out (none at present: remove, move, regenerate_day and
 * optimise have their confirm steps, and a single-stop retime is answered by
 * reply_retime_stop()). The pending action has the shape of the others plus "available": false,
 * and the page shows no confirm button for it.
 */
function reply_not_available(array $intent, ?array $target, string $lang): never
{
    $title = $target !== null ? (string)$target["item_title"] : "";
    $day = (int)($intent["day"] ?? 0);
    switch ($intent["action"]) {
        case "remove":
            $type = "remove_stop";
            $what = ai_text("what_remove", $lang, ["title" => $title, "day" => $day]);
            break;
        case "move":
            $type = "move_stop";
            $position = $intent["position"] !== null ? ai_text("what_move_position", $lang, ["position" => $intent["position"]]) : "";
            $what = ai_text("what_move", $lang, ["title" => $title, "day" => $day, "position" => $position]);
            break;
        case "regenerate_day":
            $type = "regenerate_day";
            $what = ai_text("what_regenerate_day", $lang, ["day" => $day]);
            break;
        case "optimise":
            $type = "optimise_route";
            $what = $day > 0 ? ai_text("what_optimise_day", $lang, ["day" => $day]) : ai_text("what_optimise_trip", $lang);
            break;
        default:
            $type = "retime_stop";
            $what = ai_text("what_retime_stop", $lang, ["title" => $title, "time" => (string)$intent["time"]]);
    }

    $pending = [
        "type" => $type,
        "available" => false,
        "day_no" => $intent["day"],
        "item_id" => $intent["stop_id"],
        "position" => $intent["position"],
        "start_time" => $intent["time"],
        "category" => $intent["category"],
        "place_name" => $intent["place_name"],
        "wish" => $intent["wish"],
        "label" => $what,
        "summary" => ai_text("pending_not_available", $lang),
        "intent" => $intent,
    ];
    editor_reply([
        "status" => "success",
        "answer" => ai_text("edit_not_available", $lang, ["what" => $what]),
        "pending_action" => $pending,
        "pending_actions" => [$pending],
        "proposals" => [],
        "source" => "model",
    ]);
}

// ================================================================ data and rules

function load_itinerary(mysqli $conn, int $itineraryId, int $travellerId): ?array
{
    $stmt = $conn->prepare("
        SELECT i.itinerary_id, i.title, i.start_date, i.total_days, i.total_estimated_cost,
               tp.budget, tp.budget_tier, tp.transport_type, tp.interests, tp.preferred_states, tp.travel_pace
        FROM itineraries i
        LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
        WHERE i.itinerary_id = ? AND i.traveller_id = ?
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $itineraryId, $travellerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function load_editable_items(mysqli $conn, int $itineraryId): array
{
    $stmt = $conn->prepare("
        SELECT ii.item_id, ii.place_id, ii.day_no, ii.sequence_no, ii.item_type, ii.item_title,
               ii.start_time, ii.end_time, ii.estimated_cost,
               COALESCE(ii.item_latitude, cp.latitude) AS latitude,
               COALESCE(ii.item_longitude, cp.longitude) AS longitude,
               cp.state, cp.district, cp.category, cp.opening_hours, cp.rating
        FROM itinerary_items ii
        LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
        WHERE ii.itinerary_id = ? AND ii.item_type <> 'hotel' AND ii.place_id IS NOT NULL
        ORDER BY ii.day_no, ii.sequence_no
    ");
    if (!$stmt) return [];
    $stmt->bind_param("i", $itineraryId);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
    return $items;
}

function load_item(mysqli $conn, int $itineraryId, int $itemId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM itinerary_items WHERE itinerary_id = ? AND item_id = ? AND item_type <> 'hotel' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param("ii", $itineraryId, $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function load_place(mysqli $conn, int $placeId): ?array
{
    $stmt = $conn->prepare("
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE place_id = ? AND is_active = 1
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $placeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Read "day 2", "stop 3", "2nd stop", "second place", "#3" from a message.
 * Stop numbers follow sequence_no, which starts at 1 for each day's first place.
 *
 * @return array{day: int, stop: int} 0 when not given
 */
function parse_stop_reference(string $message): array
{
    $day = preg_match('/\bday\s*(\d{1,2})\b/i', $message, $m) ? (int)$m[1] : 0;

    $stop = 0;
    $stopNoun = '(?:stop|place|point|location|destination|attraction)';
    if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+' . $stopNoun . '\b/i', $message, $m)) {
        $stop = (int)$m[1];
    } elseif (preg_match('/\b' . $stopNoun . '\s*(?:no\.?|number|#)?\s*(\d{1,2})\b/i', $message, $m)) {
        $stop = (int)$m[1];
    } else {
        $words = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10];
        foreach ($words as $word => $number) {
            if (preg_match('/\b' . $word . '\s+' . $stopNoun . '\b/i', $message)) {
                $stop = $number;
                break;
            }
        }
    }

    return ['day' => ($day >= 1 && $day <= 30) ? $day : 0, 'stop' => ($stop >= 1 && $stop <= 20) ? $stop : 0];
}

/**
 * Pick the one stop the traveller means. Returns null when a day/stop was given but does not exist,
 * so the caller can say so instead of silently changing a different stop.
 */
function select_target_item(array $items, string $message, array $reference): ?array
{
    $day = $reference['day'];
    $stop = $reference['stop'];

    if ($day > 0 || $stop > 0) {
        foreach ($items as $item) {
            $dayMatches = $day <= 0 || (int)$item["day_no"] === $day;
            $stopMatches = $stop <= 0 || (int)$item["sequence_no"] === $stop;
            if ($dayMatches && $stopMatches) return $item;   // items are ordered by day, then sequence
        }
        return null;
    }

    $msg = strtolower($message);
    foreach ($items as $item) {
        $title = strtolower((string)$item["item_title"]);
        if ($title !== "" && str_contains($msg, $title)) return $item;
    }

    $category = preferred_category_from_message($message);
    if ($category !== "") {
        foreach ($items as $item) {
            if (strtolower((string)($item["category"] ?? $item["item_type"])) === $category) return $item;
        }
    }

    if (preg_match('/\b(?:cost|cheap|cheaper|budget|expensive|save money)\b/i', $message)) {
        usort($items, fn($a, $b) => (float)$b["estimated_cost"] <=> (float)$a["estimated_cost"]);
    }
    return $items[0];
}

function describe_missing_stop(array $items, array $reference): string
{
    $day = $reference['day'];
    $stop = $reference['stop'];
    $dayItems = array_values(array_filter($items, fn($item) => $day <= 0 || (int)$item["day_no"] === $day));
    $lang = editor_lang();
    if ($day > 0 && empty($dayItems)) {
        return ai_text("edit_day_empty", $lang, ["day" => $day]);
    }
    $count = count($dayItems);
    return $day > 0
        ? ai_text("edit_no_such_stop_day", $lang, ["day" => $day, "count" => $count, "stop" => $stop])
        : ai_text("edit_no_such_stop_trip", $lang, ["count" => $count, "stop" => $stop]);
}

/** "replace", "change ... to", "swap" etc.: a request to change an existing stop. */
function is_replace_request(string $message): bool
{
    return (bool)preg_match('/\b(?:replace|swap|switch|substitute|instead\s+of)\b|\bchange\b.*\b(?:to|with|into)\b/i', $message);
}

/**
 * "change the 2nd stop to Muzium Negara" -> "Muzium Negara".
 * Returns "" when no name was given or the tail is a category request such as "a museum".
 */
function extract_requested_replacement_name(string $message): string
{
    if (!preg_match('/\b(?:replace|change|swap|switch|update)\b.*?\b(?:with|to|into)\s+(.+)$/i', $message, $m)) {
        return "";
    }
    $name = trim((string)$m[1]);
    $name = preg_replace('/\s+(?:please|pls|thanks|thank you)\s*$/i', '', $name) ?? $name;
    $name = trim(preg_replace('/[.!?,;:]+$/', '', $name) ?? $name);
    $name = trim(preg_replace('/^(?:the|a|an)\s+/i', '', $name) ?? $name);
    if ($name === "" || mb_strlen($name) > 120) return "";

    // A category or a vague wish is not a place name: let the normal alternative search handle it.
    if (preg_match('/^(?:something|somewhere|another|other|different|cheaper|closer|nearby|better)\b/i', $name)
        || preg_match('/^(?:museum|food|restaurant|nature|park|temple|heritage|culture|cultural|shopping|mall|festival|place|stop)s?$/i', $name)
        || preg_match('/\b(?:day|stop|date|time|am|pm)\b/i', $name)) {
        return "";
    }
    return $name;
}

/**
 * Look up a place the traveller named, inside the itinerary's states, and check it against the
 * itinerary rules before offering it.
 *
 * @return array{place: ?array, message: string}
 */
function find_requested_place_by_name(mysqli $conn, int $travellerId, array $itinerary, array $target, string $name, array $usedPlaceIds, ?array $stateItems = null): array
{
    $lang = editor_lang();
    $isAdd = (int)($target["item_id"] ?? 0) === 0;   // a new stop on $target["day_no"]
    $states = itinerary_states($itinerary, $stateItems ?? [$target]);
    if (empty($states)) {
        return ["place" => null, "message" => ai_text("name_no_state", $lang, ["name" => $name])];
    }
    $statePlaceholders = implode(",", array_fill(0, count($states), "?"));
    $like = '%' . addcslashes($name, '%_\\') . '%';
    $sql = "
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND state IN ($statePlaceholders)
          AND (name = ? OR name LIKE ?)
        ORDER BY CASE WHEN name = ? THEN 0 ELSE 1 END,
                 CASE WHEN district = ? THEN 0 ELSE 1 END,
                 COALESCE(rating, avg_rating, 0) DESC
        LIMIT 5
    ";
    $params = array_merge($states, [$name, $like, $name, (string)($target["district"] ?? "")]);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $matches = [];
    while ($row = $res->fetch_assoc()) $matches[] = $row;
    $stmt->close();

    $stateText = implode(", ", $states);
    if (empty($matches)) {
        if ($stateText === "") $stateText = ai_text("name_states_fallback", $lang);
        return ["place" => null, "message" => ai_text("name_not_found", $lang, ["name" => $name, "states" => $stateText])];
    }

    $lastReason = "";
    foreach ($matches as $place) {
        if (!$isAdd && (int)$place["place_id"] === (int)$target["place_id"]) {
            return ["place" => null, "message" => ai_text("name_already_this_stop", $lang, ["name" => $place["name"]])];
        }
        if (in_array((int)$place["place_id"], $usedPlaceIds, true)) {
            $lastReason = ai_text("name_already_in_trip", $lang, ["name" => $place["name"]]);
            continue;
        }
        $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, (int)$itinerary["itinerary_id"], (int)$place["place_id"], (int)$target["day_no"], (int)$target["item_id"]);
        if ($check["ok"]) {
            return ["place" => $place, "message" => ""];
        }
        $lastReason = ai_text($isAdd ? "name_cannot_add" : "name_cannot_replace", $lang, ["name" => $place["name"], "reason" => $check["message"]]);
    }
    return ["place" => null, "message" => $lastReason];
}

/** States the itinerary may use: its preferred states, else the states of its current stops. */
function itinerary_states(array $itinerary, array $items): array
{
    $states = array_values(array_filter(array_map('trim', explode(",", (string)($itinerary["preferred_states"] ?? "")))));
    if (empty($states)) {
        foreach ($items as $item) {
            $state = trim((string)($item["state"] ?? ""));
            if ($state !== "" && !in_array($state, $states, true)) $states[] = $state;
        }
    }
    return $states;
}

function preferred_category_from_message(string $message): string
{
    $msg = strtolower($message);
    $map = [
        "food" => ["food", "restaurant", "eat", "halal", "makan", "美食", "吃"],
        "nature" => ["nature", "park", "outdoor", "自然"],
        "shopping" => ["shopping", "mall", "shop", "购物"],
        "museum" => ["museum", "gallery", "博物馆"],
        "heritage" => ["heritage", "historical", "history", "temple", "文化", "历史"],
        "culture" => ["culture", "cultural", "文化"],
    ];
    foreach ($map as $category => $terms) {
        foreach ($terms as $term) {
            if (str_contains($msg, $term)) return $category;
        }
    }
    return "";
}

/**
 * Find a replacement for one stop: same state, preferably the category the traveller asked for (or
 * the stop's own category), nearest district first. Candidates are checked with the same rules the
 * confirm step uses, so the traveller is never offered a change that cannot be saved.
 */
function find_alternative_place(mysqli $conn, int $travellerId, array $itinerary, array $target, array $usedPlaceIds, string $message, ?string $categoryOverride = null): ?array
{
    $state = (string)($target["state"] ?? "");
    $district = (string)($target["district"] ?? "");
    // The model's category when it gave one ("" = none asked for); otherwise read the message.
    $requestedCategory = $categoryOverride ?? preferred_category_from_message($message);
    $category = $requestedCategory !== "" ? $requestedCategory : strtolower((string)($target["category"] ?? ""));
    $excludeIds = array_values(array_unique(array_filter(array_merge($usedPlaceIds, [(int)($target["place_id"] ?? 0)]))));
    $wantsCheaper = (bool)preg_match('/\b(?:cheap|cheaper|cost|budget|expensive|save money)\b/i', $message);

    $excludeSql = empty($excludeIds) ? "" : " AND place_id NOT IN (" . implode(",", array_fill(0, count($excludeIds), "?")) . ")";
    $festivalSql = festival_date_filter((string)($itinerary["start_date"] ?? ""), (int)($itinerary["total_days"] ?? 1));
    $costOrder = $wantsCheaper ? "COALESCE(entrance_fee, estimated_cost, 0) ASC, COALESCE(rating, avg_rating, 0) DESC" : "COALESCE(rating, avg_rating, 0) DESC, COALESCE(entrance_fee, estimated_cost, 0) ASC";

    // First try the wanted category; then any category in the same state.
    foreach ([$category, ""] as $categoryFilter) {
        $sql = "
            SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
                   entrance_fee, opening_hours, rating, visit_duration_min
            FROM cultural_places
            WHERE is_active = 1
              AND state = ?
              " . ($categoryFilter !== "" ? "AND category = ?" : "") . "
              {$excludeSql}
              {$festivalSql}
            ORDER BY CASE WHEN district = ? THEN 0 ELSE 1 END, {$costOrder}
            LIMIT 15
        ";
        $params = array_merge([$state], $categoryFilter !== "" ? [$categoryFilter] : [], $excludeIds, [$district]);
        $types = "s" . ($categoryFilter !== "" ? "s" : "") . str_repeat("i", count($excludeIds)) . "s";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $candidates = [];
        while ($row = $res->fetch_assoc()) $candidates[] = $row;
        $stmt->close();

        foreach ($candidates as $place) {
            if ($wantsCheaper && (float)($place["entrance_fee"] ?? $place["estimated_cost"] ?? 0) > (float)($target["estimated_cost"] ?? 0)) {
                continue;
            }
            $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, (int)$itinerary["itinerary_id"], (int)$place["place_id"], (int)$target["day_no"], (int)$target["item_id"]);
            if ($check["ok"]) return $place;
        }
    }
    return null;
}

/**
 * Festivals are only offered when they have verified dates that overlap the trip
 * (start_date .. start_date + total_days - 1). The exact day is checked later by the rules.
 */
function festival_date_filter(string $tripStartDate, int $totalDays): string
{
    $start = DateTimeImmutable::createFromFormat("Y-m-d", $tripStartDate);
    if (!$start) {
        return "AND category <> 'festival'";
    }
    $from = $start->format("Y-m-d");
    $to = $start->modify("+" . max(0, $totalDays - 1) . " days")->format("Y-m-d");
    return "AND (category <> 'festival' OR (festival_start_date IS NOT NULL AND festival_end_date IS NOT NULL"
        . " AND festival_start_date <= '{$to}' AND festival_end_date >= '{$from}'))";
}

function format_place(array $place): array
{
    $cost = array_key_exists("entrance_fee", $place)
        ? (float)$place["entrance_fee"]
        : (float)($place["estimated_cost"] ?? 0);
    return [
        "place_id" => (int)$place["place_id"],
        "name" => (string)$place["name"],
        "state" => (string)($place["state"] ?? ""),
        "district" => (string)($place["district"] ?? ""),
        "category" => (string)($place["category"] ?? ""),
        "estimated_cost" => $cost,
        "opening_hours" => (string)($place["opening_hours"] ?? ""),
        "rating" => isset($place["rating"]) ? (float)$place["rating"] : null,
        "visit_duration_min" => (int)($place["visit_duration_min"] ?? 90),
    ];
}

function build_replacement_reason(array $target, array $place): string
{
    $lang = editor_lang();
    $parts = [];
    if (($target["district"] ?? "") !== "" && ($target["district"] ?? "") === ($place["district"] ?? "")) {
        $parts[] = ai_text("reason_same_district", $lang);
    }
    if (($target["category"] ?? "") === ($place["category"] ?? "")) {
        $parts[] = ai_text("reason_same_category", $lang);
    }
    $newCost = (float)($place["entrance_fee"] ?? $place["estimated_cost"] ?? 0);
    $oldCost = (float)($target["estimated_cost"] ?? 0);
    if ($newCost < $oldCost) $parts[] = ai_text("reason_lower_cost", $lang);
    if (!empty($place["opening_hours"])) $parts[] = ai_text("reason_opening_hours", $lang);
    return !empty($parts) ? implode(ai_text("reason_list_separator", $lang), $parts) : ai_text("reason_better", $lang);
}

function build_local_editor_answer(array $proposal): string
{
    $lang = editor_lang();
    $place = $proposal["new_place"];
    $cost = (float)($place["estimated_cost"] ?? 0);
    return ai_text("edit_replace_answer", $lang, [
        "day" => $proposal["day_no"],
        "stop" => $proposal["sequence_no"],
        "current" => $proposal["current_title"],
        "new" => $place["name"],
        "category" => ($place["category"] ?? "") !== "" ? $place["category"] : ai_text("edit_place_word", $lang),
        "cost" => $cost > 0 ? "RM " . number_format($cost, 2) : ai_text("edit_free_entry", $lang),
        "reason" => $proposal["reason"],
    ]);
}

/** "add a place on day 2", "fill my day", "more places", "another stop": a request to add stops. */
function is_add_or_regenerate_request(string $message): bool
{
    return (bool)preg_match(
        '/\b(?:add|include|fill|extra|empty|regenerate|replan)\b|\b(?:more|another|new)\s+(?:places?|stops?|attractions?)\b|\b(?:complete|plan)\s+my\s+day\b/i',
        $message
    );
}

/** The day named in the message, or else the day with the fewest stops (the most room). */
function requested_day_no(string $message, int $totalDays, array $items): int
{
    if (preg_match('/\bday\s*(\d+)\b/i', $message, $m)) {
        $day = (int)$m[1];
        if ($day >= 1 && $day <= $totalDays) return $day;
    }
    $bestDay = 1;
    $fewest = PHP_INT_MAX;
    for ($day = 1; $day <= $totalDays; $day++) {
        $count = count(array_filter($items, fn($item) => (int)$item["day_no"] === $day));
        if ($count < $fewest) {
            $fewest = $count;
            $bestDay = $day;
        }
    }
    return $bestDay;
}

function denied_states_from_message(string $message): array
{
    $msg = strtolower($message);
    $denied = [];
    $states = ["johor", "kelantan", "perak", "penang", "melaka", "kuala lumpur", "selangor", "kedah", "perlis", "pahang", "terengganu", "sabah", "sarawak", "negeri sembilan"];
    foreach ($states as $state) {
        if ((str_contains($msg, "no " . $state) || str_contains($msg, "dont want " . $state) || str_contains($msg, "don't want " . $state)) && !in_array($state, $denied, true)) {
            $denied[] = $state;
        }
    }
    return $denied;
}

function allowed_states_for_addition(array $itinerary, array $items, string $message): array
{
    $states = itinerary_states($itinerary, $items);
    $denied = denied_states_from_message($message);
    if (!empty($denied)) {
        $states = array_values(array_filter($states, fn($state) => !in_array(strtolower($state), $denied, true)));
    }
    return $states;
}

/**
 * $dayOverride / $categoryOverride come from the model's intent; null means read them from the
 * message as before ("" category = any kind of place).
 */
function build_addition_proposals(mysqli $conn, int $travellerId, array $itinerary, array $items, string $message, ?int $dayOverride = null, ?string $categoryOverride = null): array
{
    $totalDays = max(1, (int)($itinerary["total_days"] ?? 1));
    $dayNo = ($dayOverride !== null && $dayOverride >= 1 && $dayOverride <= $totalDays) ? $dayOverride : requested_day_no($message, $totalDays, $items);
    $states = allowed_states_for_addition($itinerary, $items, $message);
    if (empty($states)) return [];

    $existingDayCount = count(array_filter($items, fn($item) => (int)$item["day_no"] === $dayNo));
    $usedPlaceIds = array_values(array_filter(array_map(fn($item) => (int)($item["place_id"] ?? 0), $items)));
    $category = $categoryOverride ?? preferred_category_from_message($message);
    $festivalSql = festival_date_filter((string)($itinerary["start_date"] ?? ""), $totalDays);

    $params = $states;
    $types = str_repeat("s", count($states));
    $categorySql = "";
    if ($category !== "") {
        $categorySql = " AND category = ?";
        $params[] = $category;
        $types .= "s";
    }
    $excludeSql = "";
    if (!empty($usedPlaceIds)) {
        $excludeSql = " AND place_id NOT IN (" . implode(",", array_fill(0, count($usedPlaceIds), "?")) . ")";
        $params = array_merge($params, $usedPlaceIds);
        $types .= str_repeat("i", count($usedPlaceIds));
    }

    $statePlaceholders = implode(",", array_fill(0, count($states), "?"));
    $stmt = $conn->prepare("
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, avg_rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND state IN ($statePlaceholders)
          {$categorySql}
          {$excludeSql}
          {$festivalSql}
        ORDER BY
          CASE WHEN category='food' THEN 1 ELSE 0 END,
          COALESCE(rating, avg_rating, 0) DESC,
          COALESCE(entrance_fee, estimated_cost, 0) ASC,
          name ASC
        LIMIT 40
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $candidates = [];
    while ($place = $res->fetch_assoc()) $candidates[] = $place;
    $stmt->close();

    // Prefer places close to where the day currently ends.
    $anchor = addition_anchor_for_day($conn, (int)$itinerary["itinerary_id"], $dayNo);
    if ($anchor !== null) {
        usort($candidates, function ($a, $b) use ($anchor) {
            $da = place_distance_from_anchor($a, $anchor);
            $db = place_distance_from_anchor($b, $anchor);
            if ($da === $db) {
                return ((float)($b["rating"] ?? $b["avg_rating"] ?? 0)) <=> ((float)($a["rating"] ?? $a["avg_rating"] ?? 0));
            }
            return $da <=> $db;
        });
    }

    // One proposal, the first candidate that passes the same rules as the confirm step.
    foreach ($candidates as $place) {
        $check = ItineraryRuleValidationService::validatePlaceChange($conn, $travellerId, (int)$itinerary["itinerary_id"], (int)$place["place_id"], $dayNo, 0);
        if (!$check["ok"]) continue;
        $reason = $anchor !== null
            ? ai_text("reason_close_to", editor_lang(), ["title" => $anchor["title"], "source" => $anchor["source"]])
            : ai_text("reason_highly_rated", editor_lang(), ["state" => $place["state"]]);
        return [addition_proposal($place, $dayNo, $existingDayCount, $reason)];
    }
    return [];
}

function addition_proposal(array $place, int $dayNo, int $existingDayCount, string $reason): array
{
    return [
        "proposal_type" => "add",
        "item_id" => 0,
        "current_title" => ai_text("edit_add_stop_title", editor_lang()),
        "day_no" => $dayNo,
        "sequence_no" => $existingDayCount + 1,
        "current_category" => "",
        "new_place" => format_place($place),
        "reason" => $reason,
    ];
}

function addition_anchor_for_day(mysqli $conn, int $itineraryId, int $dayNo): ?array
{
    $queries = [];

    // If the requested day already has places, continue from its last real stop.
    $queries[] = [
        "source" => ai_text("anchor_last_place_day", editor_lang()),
        "sql" => "
            SELECT ii.item_title AS title,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ? AND ii.day_no = ? AND ii.item_type <> 'hotel'
            ORDER BY ii.sequence_no DESC
            LIMIT 1
        ",
        "day" => $dayNo,
    ];

    if ($dayNo > 1) {
        // For an empty day, start from previous night's hotel when available.
        $queries[] = [
            "source" => ai_text("anchor_prev_hotel", editor_lang()),
            "sql" => "
                SELECT ii.item_title AS title,
                       ii.item_latitude AS latitude,
                       ii.item_longitude AS longitude
                FROM itinerary_items ii
                WHERE ii.itinerary_id = ? AND ii.day_no = ? AND ii.item_type = 'hotel'
                ORDER BY ii.sequence_no DESC
                LIMIT 1
            ",
            "day" => $dayNo - 1,
        ];

        // Fallback: previous day's last stop.
        $queries[] = [
            "source" => ai_text("anchor_prev_last", editor_lang()),
            "sql" => "
                SELECT ii.item_title AS title,
                       COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                       COALESCE(ii.item_longitude, cp.longitude) AS longitude
                FROM itinerary_items ii
                LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
                WHERE ii.itinerary_id = ? AND ii.day_no = ?
                ORDER BY ii.sequence_no DESC
                LIMIT 1
            ",
            "day" => $dayNo - 1,
        ];
    }

    foreach ($queries as $query) {
        $stmt = $conn->prepare($query["sql"]);
        if (!$stmt) continue;
        $anchorDay = (int)$query["day"];
        $stmt->bind_param("ii", $itineraryId, $anchorDay);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) continue;
        $lat = $row["latitude"] !== null ? (float)$row["latitude"] : null;
        $lng = $row["longitude"] !== null ? (float)$row["longitude"] : null;
        if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) continue;

        return [
            "title" => (string)($row["title"] ?? "anchor point"),
            "latitude" => $lat,
            "longitude" => $lng,
            "source" => $query["source"],
        ];
    }

    return null;
}

function place_distance_from_anchor(array $place, array $anchor): float
{
    $lat = $place["latitude"] !== null ? (float)$place["latitude"] : null;
    $lng = $place["longitude"] !== null ? (float)$place["longitude"] : null;
    if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) return PHP_FLOAT_MAX;

    $earthRadius = 6371.0;
    $dLat = deg2rad($lat - (float)$anchor["latitude"]);
    $dLng = deg2rad($lng - (float)$anchor["longitude"]);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad((float)$anchor["latitude"])) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function build_local_addition_answer(array $proposals): string
{
    $lang = editor_lang();
    $lines = [ai_text("edit_add_intro", $lang)];
    foreach ($proposals as $proposal) {
        $place = $proposal["new_place"];
        $lines[] = ai_text("edit_add_line", $lang, ["day" => $proposal["day_no"], "place" => $place["name"], "district" => $place["district"], "state" => $place["state"]]);
    }
    return implode("\n", $lines);
}

/**
 * An explicit request to restart the days at a clock time, e.g. "start at 9.30am",
 * "rearrange the timetable to 8am", "change the start time to 10am". Same rule as
 * detectChatRoute() in assets/trip_ai_chat.js.
 */
function is_timetable_rearrange_request(string $message): bool
{
    if (preg_match('/\b(?:hotels?|accommodation|check\s*-?in)\b/i', $message)) return false;
    return (bool)preg_match('/\b(?:re-?arrange|reschedule|re-?time|new\s+timetable|timetable|schedule)\b/i', $message)
        || (bool)preg_match('/\b(?:start|begin)(?:\s+time)?(?:\s+(?:the\s+)?day\s*\d{1,2}|\s+(?:the|my|each|every)\s+days?)?\s*(?:at|from|to)?\s*\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)\b/i', $message)
        || (bool)preg_match('/\b(?:change|set|move|update)\s+(?:the\s+|my\s+)?(?:start(?:ing)?\s+)?time\b/i', $message);
}

function extract_requested_start_time(string $message): ?string
{
    if (preg_match('/\b(?:start|begin|starting|beginning)(?:\s+time)?\s*(?:at|from|to)?\s*(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)\b/i', $message, $m)) {
        return build_sql_time_from_match($m);
    }
    if (preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)\b/i', $message, $m)) {
        return build_sql_time_from_match($m);
    }
    return null;
}

function normalize_requested_start_time(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
        $hour = (int)$m[1];
        $minute = (int)$m[2];
        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
            return sprintf("%02d:%02d:00", $hour, $minute);
        }
    }
    if (preg_match('/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm)$/i', $value, $m)) {
        return build_sql_time_from_match($m);
    }
    return null;
}

function build_sql_time_from_match(array $m): ?string
{
    $hour = (int)($m[1] ?? 0);
    $minute = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
    $ampm = strtolower((string)($m[3] ?? ""));
    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59 || !in_array($ampm, ["am", "pm"], true)) {
        return null;
    }
    if ($ampm === "pm" && $hour < 12) $hour += 12;
    if ($ampm === "am" && $hour === 12) $hour = 0;
    return sprintf("%02d:%02d:00", $hour, $minute);
}

function display_time_label(string $sqlTime): string
{
    $dt = DateTime::createFromFormat("H:i:s", $sqlTime);
    return $dt ? $dt->format("g:i A") : $sqlTime;
}
