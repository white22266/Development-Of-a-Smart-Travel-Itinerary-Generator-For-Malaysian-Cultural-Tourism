#!/usr/bin/env bash
set -euo pipefail

PROJECT="${1:-/var/www/travel-system}"

if [ ! -d "$PROJECT" ]; then
  echo "ERROR: Project folder not found: $PROJECT"
  exit 1
fi

cd "$PROJECT"
echo "=== Fixing AI Travel Assistant routing, replies, and itinerary edits ==="

backup_dir="$HOME/ai_travel_agent_complete_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$backup_dir/itinerary" "$backup_dir/api" "$backup_dir/services"
for f in itinerary/trip_summary.php api/ai_travel_assistant.php api/ai_itinerary_editor.php services/AiTravelAssistantService.php .env; do
  if [ -f "$f" ]; then
    mkdir -p "$backup_dir/$(dirname "$f")"
    cp "$f" "$backup_dir/$f"
  fi
done

echo "Backup saved to: $backup_dir"

python3 <<'PY'
from pathlib import Path
import re

# ---------------------------------------------------------
# 1) trip_summary.php: robust front-end intent routing
# ---------------------------------------------------------
p = Path("itinerary/trip_summary.php")
s = p.read_text(encoding="utf-8")

# Professional initial message.
s = s.replace(
    'Hi, I am your local AI assistant. Ask for hotel recommendations, cost checks, route explanation, or itinerary changes. I will not save hotel or route changes unless you click a confirmation button.',
    'Hi, I am your travel assistant. Ask about cost, route, hotel options, trip date, starting point, timetable changes, or changing itinerary stops. I will only save changes after you confirm.'
)

start = s.find("        async function sendAiMessage(event) {")
end = s.find("        function renderPendingAction(container, action) {", start)
if start == -1 or end == -1:
    raise SystemExit("Could not locate sendAiMessage block in itinerary/trip_summary.php")

new_send = r'''        async function sendAiMessage(event) {
            if (event) event.preventDefault();
            const input = document.getElementById('aiChatInput');
            if (!input) return;
            const text = input.value.trim();
            if (!text) return;

            input.value = '';
            addAiMessage('user', text);
            clearAiPendingCards();

            const normalized = text.toLowerCase().replace(/[^a-z0-9\s:.\/-]/g, ' ').replace(/\s+/g, ' ').trim();

            // Fast local replies. Do not waste an AI call on short social/chat-control messages.
            if (/^(hi|hihi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/.test(normalized)) {
                addAiMessage('bot', 'Hello. I can help with cost, route, hotel options, trip date, starting point, timetable changes, and itinerary stop changes.');
                return;
            }
            if (/^(who are u|who are you|what are you|what is your role|what is your model|what model are you)$/.test(normalized)) {
                addAiMessage('bot', 'I am your Smart Travel Assistant for this itinerary. I help review the trip and prepare changes, but nothing is saved until you confirm.');
                return;
            }
            if (/^(what u can do|what can u do|what can you do|help|help me|what do you do)$/.test(normalized)) {
                addAiMessage('bot', 'I can explain trip cost, describe routes, suggest hotels, detect a new start date or starting point, rearrange timetable time, and prepare changes for the 1st, 2nd, or selected itinerary stop.');
                return;
            }
            if (/^(then|next|continue|more|ok|okay|thanks|thank you|tq|thx)$/.test(normalized)) {
                addAiMessage('bot', 'Please tell me what you want to adjust: cost, route, hotel, start date, starting point, timetable time, or a specific itinerary stop.');
                return;
            }

            const loading = addAiMessage('bot', 'Writing answer...');
            const controller = new AbortController();
            const aiTimeout = setTimeout(() => controller.abort(), 15000);

            try {
                const timePattern = /\b\d{1,2}(?::|\.)\d{2}\s*(?:am|pm)\b|\b\d{1,2}\s*(?:am|pm)\b/i;
                const scheduleTimeIntent = timePattern.test(text) && /\b(timetable|schedule|reschedule|rearrange|arrange|retime|time|start|begin|change timetable|change time)\b/i.test(text);

                const originIntent = !scheduleTimeIntent && /\b(starting\s+location|start\s+location|starting\s+point|start\s+point|origin|start\s+from|starting\s+from|depart\s+from|leave\s+from|change\s+starting|set\s+starting|use\s+.+\s+as\s+starting)\b/i.test(text);

                const dateIntent = /\b(start\s+date|starting\s+date|travel\s+date|trip\s+date|travel\s+on|go\s+on|visit\s+on|arrive\s+on|depart\s+on|change\s+date|set\s+date|move\s+date)\b|\b\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}\b|\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}\b|\b\d{1,2}(?:st|nd|rd|th)?\s*[a-zA-Z]+\s*\d{4}\b|\b[a-zA-Z]+\s*\d{1,2}(?:st|nd|rd|th)?[,]?\s*\d{4}\b/i.test(text);

                const weatherIntent = /\b(weather|forecast|rain|raining|temperature|hot|humid|storm|umbrella)\b/i.test(text);
                const smartHotelIntent = /\b(hotel|hotels|accommodation|stay|stays|room|rooms|sleep|overnight|check\s*in|nearby\s+hotel|budget\s+hotel|cheap\s+hotel|luxury\s+hotel|place\s+to\s+stay)\b/i.test(text);

                const placeEditIntent = /\b(?:replace|change|swap|modify|remove|delete|skip|add|extra|fill|more\s+places?|another\s+place|new\s+place|better\s+stop|change\s+stop|suggest\s+place|recommend\s+place|alternative|alternatives|dislike|don't\s+want|do\s+not\s+want|too\s+far|too\s+expensive|cheaper|reduce\s+cost|lower\s+cost)\b/i.test(text)
                    || /\b(?:1st|2nd|3rd|4th|5th|first|second|third|fourth|fifth)\s+(?:point|place|stop)\b/i.test(text)
                    || /\b(?:point|place|stop)\s*(?:#?\s*)?\d+\b/i.test(text)
                    || /\bday\s*\d+\b/i.test(text);

                const endpoint = scheduleTimeIntent || placeEditIntent
                    ? '../api/ai_itinerary_editor.php'
                    : (smartHotelIntent ? '../api/ai_hotel_assistant.php' : '../api/ai_travel_assistant.php');

                const isEditor = endpoint.includes('ai_itinerary_editor.php');
                const resp = await fetch(endpoint, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams(isEditor || smartHotelIntent ?
                        {
                            action: 'recommend',
                            itinerary_id: ITINERARY_ID,
                            message: text
                        } :
                        {
                            action: 'chat',
                            itinerary_id: ITINERARY_ID,
                            message: text
                        }),
                });

                const data = await parseJsonResponse(resp);
                clearTimeout(aiTimeout);

                if (loading) loading.textContent = data.answer || data.message || 'Please ask a clearer travel question about this itinerary.';

                if (data.pending_actions && data.pending_actions.length) {
                    const firstAction = data.pending_actions[0];
                    if (data.pending_actions.length > 1) firstAction.next_action = data.pending_actions[1];
                    renderPendingAction(loading, firstAction);
                } else if (data.pending_action) {
                    renderPendingAction(loading, data.pending_action);
                }
                if (smartHotelIntent && data.hotels && data.hotels.length) {
                    renderHotelCards(loading, data.hotels);
                }
                if (isEditor && data.proposals && data.proposals.length) {
                    renderChangeCards(loading, data.proposals);
                }
            } catch (e) {
                clearTimeout(aiTimeout);
                if (loading) {
                    loading.textContent = 'Please ask a clearer travel request, such as change timetable to 9.30am, change start date to 20 June 2026, set starting point to KL Sentral, or change the 1st stop.';
                }
            }
        }

'''

s = s[:start] + new_send + s[end:]
p.write_text(s, encoding="utf-8")

# ---------------------------------------------------------
# 2) api/ai_travel_assistant.php: rule-based travel answers + professional fallback
# ---------------------------------------------------------
p = Path("api/ai_travel_assistant.php")
s = p.read_text(encoding="utf-8")

# Replace the user-visible fallback.
s = s.replace(
    '$answer = "I can help with this trip, but the local AI service is not available right now. You can still use the rule-based itinerary generator, hotel confirmation, and route tools.";',
    '$answer = build_professional_fallback_answer($message, $itinerary, $items);'
)

# Insert quick answer after items loaded.
needle = '$items = load_itinerary_items($conn, $itineraryId);\n'
insert = '''$quickAnswer = build_fast_travel_answer($message, $itinerary, $items);
if ($quickAnswer !== null) {
    log_ai_chat($conn, $itineraryId, $travellerId, $message, $quickAnswer);
    echo json_encode([
        "status" => "success",
        "answer" => $quickAnswer,
        "pending_action" => null,
        "pending_actions" => [],
        "source" => "travel_rule"
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

'''
if "build_fast_travel_answer($message" not in s:
    if needle not in s:
        raise SystemExit("Could not insert quick answer in api/ai_travel_assistant.php")
    s = s.replace(needle, needle + insert, 1)

# Add helper functions before parse_trip_date.
marker = '\nfunction parse_trip_date(string $text): ?string\n'
helpers = r'''
function normalize_travel_message(string $message): string
{
    $value = strtolower(trim(strip_tags($message)));
    $value = preg_replace('/[^a-z0-9\s:.\/-]/', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function build_fast_travel_answer(string $message, array $itinerary, array $items): ?string
{
    $msg = normalize_travel_message($message);
    if ($msg === '') return null;

    if (preg_match('/^(hi|hihi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i', $msg)) {
        return 'Hello. I can help with cost, route, hotel options, trip date, starting point, timetable changes, and itinerary stop changes.';
    }

    if (preg_match('/^(who are u|who are you|what are you|what is your role|what is your model|what model are you)$/i', $msg)) {
        return 'I am your Smart Travel Assistant for this itinerary. I help review the trip and prepare changes, but nothing is saved until you confirm.';
    }

    if (preg_match('/^(what u can do|what can u do|what can you do|help|help me|what do you do)$/i', $msg)) {
        return 'I can explain trip cost, describe routes, suggest hotels, detect a new start date or starting point, rearrange timetable time, and prepare changes for the 1st, 2nd, or selected itinerary stop.';
    }

    if (preg_match('/^(then|next|continue|more|ok|okay|thanks|thank you|tq|thx)$/i', $msg)) {
        return 'Please tell me what you want to adjust: cost, route, hotel, start date, starting point, timetable time, or a specific itinerary stop.';
    }

    if (preg_match('/\b(cost|price|budget|total|rm|expensive|cheap|afford|how much)\b/i', $msg)) {
        $total = (float)($itinerary['total_estimated_cost'] ?? 0);
        $budget = (float)($itinerary['budget'] ?? 0);
        $parts = [];
        $parts[] = $total > 0 ? 'The current estimated trip cost is RM ' . number_format($total, 2) . '.' : 'The trip cost is not fully calculated yet.';
        if ($budget > 0 && $total > 0) {
            $diff = $budget - $total;
            $parts[] = $diff >= 0
                ? 'It is within your budget by RM ' . number_format($diff, 2) . '.'
                : 'It is over your budget by RM ' . number_format(abs($diff), 2) . '.';
        }
        $parts[] = 'For a cheaper plan, ask me to reduce cost or replace an expensive stop.';
        return implode(' ', $parts);
    }

    if (preg_match('/\b(route|travel time|distance|far|near|nearest|order|sequence)\b/i', $msg)) {
        $count = count(array_filter($items, fn($item) => strtolower((string)($item['type'] ?? '')) !== 'hotel'));
        $origin = trim((string)($itinerary['origin_name'] ?? ''));
        $start = $origin !== '' ? $origin : 'the saved starting location';
        return 'This itinerary route starts from ' . $start . ' and includes ' . $count . ' planned stop' . ($count === 1 ? '' : 's') . '. Ask me to change the timetable, starting point, or a specific stop if the route order is not suitable.';
    }

    if (preg_match('/\b(readiness|ready|complete|missing|before generate|before confirm|itinerary readiness|summary complete)\b/i', $msg)) {
        $missing = [];
        if (trim((string)($itinerary['start_date'] ?? '')) === '') $missing[] = 'start date';
        if (trim((string)($itinerary['origin_name'] ?? '')) === '') $missing[] = 'starting point';
        $hasHotel = false;
        foreach ($items as $item) {
            if (strtolower((string)($item['type'] ?? '')) === 'hotel') { $hasHotel = true; break; }
        }
        if ((int)($itinerary['total_days'] ?? 1) > 1 && !$hasHotel) $missing[] = 'confirmed hotel';
        return empty($missing)
            ? 'This itinerary looks ready. You can still review cost, route order, hotel choice, and timetable before confirming.'
            : 'Before this itinerary is complete, please confirm: ' . implode(', ', $missing) . '.';
    }

    return null;
}

function build_professional_fallback_answer(string $message, array $itinerary, array $items): string
{
    $msg = normalize_travel_message($message);
    if ($msg === '') return 'Please type a travel question about this itinerary.';

    if (preg_match('/\b(date|starting date|start date|travel date)\b/i', $msg)) {
        return 'I can help update the trip date. Please write it clearly, for example: change start date to 20 June 2026.';
    }
    if (preg_match('/\b(starting point|starting location|start point|origin|start from|depart from)\b/i', $msg)) {
        return 'I can help update the starting point. Please write it clearly, for example: set starting point to KL Sentral.';
    }
    if (preg_match('/\b(timetable|schedule|time|9\.30am|9:30am|10\.00am|10:00am)\b/i', $msg)) {
        return 'I can help rearrange the timetable. Please write it clearly, for example: change timetable to 9.30am.';
    }
    if (preg_match('/\b(1st|2nd|3rd|first|second|third|point|place|stop|replace|change)\b/i', $msg)) {
        return 'I can prepare an itinerary stop change. Please write it clearly, for example: change the 1st stop to a museum, or replace Day 1 stop 2.';
    }

    return 'I can help with this trip. Ask about cost, route, hotel options, trip date, starting point, timetable changes, or changing a specific itinerary stop.';
}

'''
if "function build_fast_travel_answer" not in s:
    if marker not in s:
        raise SystemExit("Could not find parse_trip_date marker in api/ai_travel_assistant.php")
    s = s.replace(marker, "\n" + helpers + marker.lstrip(), 1)

p.write_text(s, encoding="utf-8")

# ---------------------------------------------------------
# 3) api/ai_itinerary_editor.php: detect dot times + 1st/2nd/point changes + requested place names
# ---------------------------------------------------------
p = Path("api/ai_itinerary_editor.php")
s = p.read_text(encoding="utf-8")

# Avoid Ollama overwrite when local proposals exist: use local answer directly for consistency.
s = s.replace(
    "$context = [\n    \"task\" => \"itinerary stop replacement suggestion only\",",
    "$context = [\n    \"task\" => \"itinerary stop replacement suggestion only\","
)
s = s.replace(
    "if (($result[\"status\"] ?? \"\") !== \"success\" || $answer === \"\") {\n    $answer = $fallbackAnswer;\n    $source = \"local_fallback\";\n}",
    "$answer = $fallbackAnswer;\n$source = \"local_rule\";"
)

# Insert requested place direct replacement after proposals initialized and before foreach alternatives.
needle = "$proposals = [];\n\nforeach ($targets as $target) {"
insert = r'''$requestedPlace = find_requested_replacement_place($conn, $message, $usedPlaceIds);
if ($requestedPlace && !empty($targets)) {
    foreach ($targets as $target) {
        $proposals[] = [
            "item_id" => (int)$target["item_id"],
            "current_title" => (string)$target["item_title"],
            "day_no" => (int)$target["day_no"],
            "sequence_no" => (int)$target["sequence_no"],
            "current_category" => (string)($target["category"] ?? $target["item_type"]),
            "new_place" => format_place($requestedPlace),
            "reason" => "matches the place requested by the traveller",
        ];
    }
}

if (empty($proposals)) {
    foreach ($targets as $target) {'''
if "find_requested_replacement_place($conn" not in s:
    if needle not in s:
        raise SystemExit("Could not insert requested place logic in api/ai_itinerary_editor.php")
    s = s.replace(needle, insert, 1)
    s = s.replace("}\n\nif (empty($proposals)) {\n    echo json_encode([", "    }\n}\n\nif (empty($proposals)) {\n    echo json_encode([", 1)

# Replace select_target_items with broader ordinal support.
start = s.find("function select_target_items(array $items, string $message): array")
end = s.find("function preferred_category_from_message", start)
if start == -1 or end == -1:
    raise SystemExit("Could not locate select_target_items function")
new_func = r'''function select_target_items(array $items, string $message): array
{
    $msg = strtolower($message);
    $items = array_values($items);

    if (preg_match('/day\s*(\d+).*?(?:#|place|point|stop)?\s*(\d+)?/i', $message, $m)) {
        $day = (int)$m[1];
        $seq = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
        $matched = array_values(array_filter($items, fn($item) =>
            (int)$item["day_no"] === $day && ($seq <= 0 || (int)$item["sequence_no"] === $seq)
        ));
        if (!empty($matched)) return array_slice($matched, 0, 2);
    }

    $ordinal = requested_stop_number($message);
    if ($ordinal > 0) {
        $globalStops = array_values(array_filter($items, fn($item) => strtolower((string)($item["item_type"] ?? "")) !== "hotel"));
        if (isset($globalStops[$ordinal - 1])) return [$globalStops[$ordinal - 1]];
        foreach ($items as $item) {
            if ((int)$item["sequence_no"] === $ordinal) return [$item];
        }
    }

    foreach ($items as $item) {
        $title = strtolower((string)$item["item_title"]);
        if ($title !== "" && str_contains($msg, $title)) return [$item];
    }

    $category = preferred_category_from_message($message);
    if ($category !== "") {
        $matched = array_values(array_filter($items, fn($item) =>
            strtolower((string)($item["category"] ?? $item["item_type"])) === $category
        ));
        if (!empty($matched)) return array_slice($matched, 0, 3);
    }

    if (str_contains($msg, "cost") || str_contains($msg, "cheap") || str_contains($msg, "budget")) {
        usort($items, fn($a, $b) => (float)$b["estimated_cost"] <=> (float)$a["estimated_cost"]);
        return array_slice($items, 0, 3);
    }

    return array_slice($items, 0, 1);
}

function requested_stop_number(string $message): int
{
    $msg = strtolower($message);
    $map = [
        '1st' => 1, 'first' => 1,
        '2nd' => 2, 'second' => 2,
        '3rd' => 3, 'third' => 3,
        '4th' => 4, 'fourth' => 4,
        '5th' => 5, 'fifth' => 5,
        '6th' => 6, 'sixth' => 6,
        '7th' => 7, 'seventh' => 7,
        '8th' => 8, 'eighth' => 8,
        '9th' => 9, 'ninth' => 9,
        '10th' => 10, 'tenth' => 10,
    ];
    foreach ($map as $word => $no) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\s+(?:point|place|stop)\b/i', $message)) return $no;
    }
    if (preg_match('/\b(?:point|place|stop)\s*#?\s*(\d+)\b/i', $message, $m)) return (int)$m[1];
    if (preg_match('/\b(\d+)(?:st|nd|rd|th)\s+(?:point|place|stop)\b/i', $message, $m)) return (int)$m[1];
    return 0;
}

'''
s = s[:start] + new_func + s[end:]

# Add find_requested_replacement_place helper before find_alternative_place.
marker = "function find_alternative_place(mysqli $conn, array $target, array $usedPlaceIds, string $message, string $tripStartDate): ?array"
helper = r'''function find_requested_replacement_place(mysqli $conn, string $message, array $usedPlaceIds): ?array
{
    $name = extract_requested_replacement_name($message);
    if ($name === '') return null;

    $excludeSql = '';
    $params = [$name, '%' . $name . '%'];
    $types = 'ss';
    if (!empty($usedPlaceIds)) {
        $excludeSql = ' AND place_id NOT IN (' . implode(',', array_fill(0, count($usedPlaceIds), '?')) . ')';
        foreach ($usedPlaceIds as $id) {
            $params[] = (int)$id;
            $types .= 'i';
        }
    }

    $sql = "
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND (LOWER(name) = LOWER(?) OR LOWER(name) LIKE LOWER(?))
          {$excludeSql}
        ORDER BY CASE WHEN LOWER(name) = LOWER(?) THEN 0 ELSE 1 END,
                 COALESCE(rating, avg_rating, 0) DESC
        LIMIT 1
    ";
    $params[] = $name;
    $types .= 's';
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function extract_requested_replacement_name(string $message): string
{
    $patterns = [
        '/\b(?:replace|change|swap)\s+(?:the\s+)?(?:\d+(?:st|nd|rd|th)|first|second|third|fourth|fifth)?\s*(?:point|place|stop)?\s*(?:to|with|into)\s+(.+?)(?:[.!?]|$)/iu',
        '/\b(?:to|with)\s+([A-Za-z0-9&\'\-\s]{3,80})(?:[.!?]|$)/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $value = trim(strip_tags((string)$m[1]));
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            $value = preg_replace('/\b(?:please|pls|thanks|thank you)\b.*$/iu', '', $value) ?? $value;
            return trim($value, " \t\n\r\0\x0B,;:");
        }
    }
    return '';
}

'''
if "function find_requested_replacement_place" not in s:
    if marker not in s:
        raise SystemExit("Could not find helper insertion point")
    s = s.replace(marker, helper + marker, 1)

# Support dot time in editor functions.
s = s.replace("(?::(\\d{2}))?\\s*(am|pm)", "(?::|\\.)?(\\d{2})?\\s*(am|pm)")
s = s.replace("^\\d{1,2}(?::(\\d{2}))?\\s*(am|pm)$", "^(\\d{1,2})(?::|\\.)?(\\d{2})?\\s*(am|pm)$")
s = s.replace("if (preg_match('/^(\\d{1,2})(?::(\\d{2}))?\\s*(am|pm)$/i', $value, $m))", "if (preg_match('/^(\\d{1,2})(?::|\\.)?(\\d{2})?\\s*(am|pm)$/i', $value, $m))")
s = s.replace("return (bool)preg_match('/\\b(rearrange|arrange|new\\s+timetable|timetable|schedule|reschedule|start\\s+(?:at|from)|begin\\s+(?:at|from))\\b/i', $message);",
              "return (bool)preg_match('/\\b(rearrange|arrange|new\\s+timetable|timetable|schedule|reschedule|retime|change\\s+time|change\\s+timetable|start\\s+(?:at|from)|begin\\s+(?:at|from))\\b/i', $message);")

p.write_text(s, encoding="utf-8")
PY

# Set stable fast model settings.
touch .env
set_env_value() {
  KEY="$1"
  VALUE="$2"
  if grep -q "^${KEY}=" .env; then
    sed -i "s|^${KEY}=.*|${KEY}=${VALUE}|g" .env
  else
    echo "${KEY}=${VALUE}" >> .env
  fi
}
set_env_value "AI_USE_GEMINI" "false"
set_env_value "OLLAMA_BASE_URL" "http://127.0.0.1:11434"
set_env_value "OLLAMA_MODEL" "qwen2.5:1.5b"
set_env_value "OLLAMA_TIMEOUT" "10"
set_env_value "OLLAMA_CONNECT_TIMEOUT" "2"
set_env_value "OLLAMA_NUM_CTX" "512"
set_env_value "OLLAMA_NUM_PREDICT" "100"
set_env_value "OLLAMA_NUM_THREAD" "4"
set_env_value "OLLAMA_TEMPERATURE" "0.1"
set_env_value "OLLAMA_KEEP_ALIVE" "30m"

php -l itinerary/trip_summary.php
php -l api/ai_travel_assistant.php
php -l api/ai_itinerary_editor.php
php -l services/AiTravelAssistantService.php

echo "=== Done fixing AI Travel Assistant ==="
echo "Next: chown www-data and restart Apache/Ollama."
