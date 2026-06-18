#!/usr/bin/env bash
set -euo pipefail

PROJECT="${1:-/var/www/travel-system}"
EDITOR_FILE="$PROJECT/api/ai_itinerary_editor.php"

if [ ! -f "$EDITOR_FILE" ]; then
  echo "ERROR: Cannot find $EDITOR_FILE"
  exit 1
fi

cd "$PROJECT"

cp api/ai_itinerary_editor.php "api/ai_itinerary_editor.php.bak_specific_replace_$(date +%Y%m%d_%H%M%S)"

python3 <<'PY'
from pathlib import Path
import re

p = Path('api/ai_itinerary_editor.php')
s = p.read_text(encoding='utf-8')

# 1) Replace target selector so "2nd point", "1st stop", "second place" lock the correct sequence.
new_select = r'''function select_target_items(array $items, string $message): array
{
    $msg = strtolower($message);

    $requestedSeq = extract_requested_sequence_no($message);

    if (preg_match('/day\s*(\d+).*?(?:#|place|point|stop)?\s*(\d+)?/i', $message, $m)) {
        $day = (int)$m[1];
        $seq = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : $requestedSeq;
        $matched = array_values(array_filter($items, fn($item) =>
            (int)$item["day_no"] === $day && ($seq <= 0 || (int)$item["sequence_no"] === $seq)
        ));
        if (!empty($matched)) return array_slice($matched, 0, 1);
    }

    if ($requestedSeq > 0) {
        $matched = array_values(array_filter($items, fn($item) => (int)$item["sequence_no"] === $requestedSeq));
        if (!empty($matched)) return array_slice($matched, 0, 1);
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

    if (str_contains($msg, "cost") || str_contains($msg, "cheap") || str_contains($msg, "budget") || str_contains($msg, "便宜") || str_contains($msg, "省钱")) {
        usort($items, fn($a, $b) => (float)$b["estimated_cost"] <=> (float)$a["estimated_cost"]);
        return array_slice($items, 0, 3);
    }

    return array_slice($items, 0, 3);
}

function extract_requested_sequence_no(string $message): int
{
    $patterns = [
        '/\b(\d+)(?:st|nd|rd|th)\s+(?:point|place|stop)\b/i',
        '/\b(?:point|place|stop)\s*(\d+)\b/i',
        '/\b(?:change|replace|edit|remove|update)\s+(\d+)(?:st|nd|rd|th)?\b/i',
        '/第\s*(\d+)\s*(?:个|站|点)/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $n = (int)$m[1];
            if ($n > 0 && $n <= 20) return $n;
        }
    }

    $wordMap = [
        'first' => 1,
        'second' => 2,
        'third' => 3,
        'fourth' => 4,
        'fifth' => 5,
        'sixth' => 6,
        'seventh' => 7,
        'eighth' => 8,
        'ninth' => 9,
        'tenth' => 10,
    ];

    foreach ($wordMap as $word => $number) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\s+(?:point|place|stop)\b/i', $message)) {
            return $number;
        }
    }

    return 0;
}
'''

s = re.sub(
    r'function select_target_items\(array \$items, string \$message\): array\s*\{.*?\n\}\s*\n\s*function preferred_category_from_message',
    new_select + "\nfunction preferred_category_from_message",
    s,
    flags=re.S,
)

# 2) Add requested replacement place parser and database lookup before find_alternative_place.
helper = r'''
function extract_requested_replacement_name(string $message): string
{
    $patterns = [
        '/\b(?:change|replace|switch|update|edit)\b.*?\b(?:to|with)\s+(.+)$/i',
        '/\b(?:replace with|change to|switch to|update to)\s+(.+)$/i',
        '/换成\s*(.+)$/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message, $m)) {
            $name = trim((string)$m[1]);
            $name = preg_replace('/[.!?。！？，,]+$/u', '', $name) ?? $name;
            $name = preg_replace('/\s+(?:please|pls|thanks|thank you)$/i', '', $name) ?? $name;
            if ($name !== '' && mb_strlen($name) <= 120) return trim($name);
        }
    }

    return '';
}

function find_requested_place_by_name(mysqli $conn, string $requestedName, array $target, array $usedPlaceIds, string $tripStartDate): ?array
{
    $requestedName = trim($requestedName);
    if ($requestedName === '') return null;

    $currentPlaceId = (int)($target['place_id'] ?? 0);
    $excludeIds = array_values(array_unique(array_filter($usedPlaceIds, fn($id) => (int)$id !== $currentPlaceId)));
    $excludeSql = '';
    $params = [];
    $types = '';

    if (!empty($excludeIds)) {
        $excludeSql = ' AND place_id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        $params = $excludeIds;
        $types = str_repeat('i', count($excludeIds));
    }

    $dateFilter = festival_date_filter($tripStartDate);
    $targetState = trim((string)($target['state'] ?? ''));
    $targetDistrict = trim((string)($target['district'] ?? ''));
    $targetCategory = trim((string)($target['category'] ?? ''));

    $sql = "
        SELECT place_id, name, state, district, category, latitude, longitude, estimated_cost,
               entrance_fee, opening_hours, rating, visit_duration_min
        FROM cultural_places
        WHERE is_active = 1
          AND (LOWER(name) = LOWER(?) OR LOWER(name) LIKE LOWER(?))
          {$excludeSql}
          {$dateFilter}
        ORDER BY
          CASE WHEN LOWER(name) = LOWER(?) THEN 0 ELSE 1 END,
          CASE WHEN state = ? THEN 0 ELSE 1 END,
          CASE WHEN district = ? THEN 0 ELSE 1 END,
          CASE WHEN category = ? THEN 0 ELSE 1 END,
          COALESCE(rating, avg_rating, 0) DESC,
          COALESCE(entrance_fee, estimated_cost, 0) ASC
        LIMIT 1
    ";

    $like = '%' . $requestedName . '%';
    $allParams = array_merge([$requestedName, $like], $params, [$requestedName, $targetState, $targetDistrict, $targetCategory]);
    $allTypes = 'ss' . $types . 'ssss';

    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

'''

if 'function extract_requested_replacement_name' not in s:
    s = s.replace('function find_alternative_place(mysqli $conn, array $target, array $usedPlaceIds, string $message, string $tripStartDate): ?array', helper + 'function find_alternative_place(mysqli $conn, array $target, array $usedPlaceIds, string $message, string $tripStartDate): ?array')

# 3) Use the requested place first; if user specified a place and it is not found, do not suggest random alternatives.
old_loop = r'''foreach ($targets as $target) {
    $alternative = find_alternative_place($conn, $target, $usedPlaceIds, $message, $tripStartDate);
    if (!$alternative) continue;
    $proposals[] = [
        "item_id" => (int)$target["item_id"],
        "current_title" => (string)$target["item_title"],
        "day_no" => (int)$target["day_no"],
        "sequence_no" => (int)$target["sequence_no"],
        "current_category" => (string)($target["category"] ?? $target["item_type"]),
        "new_place" => format_place($alternative),
        "reason" => build_replacement_reason($target, $alternative),
    ];
}

if (empty($proposals)) {
    echo json_encode([
        "status" => "success",
        "answer" => "I could not find a suitable replacement from the current cultural places database. Try asking for a different category, state, or budget direction.",
        "proposals" => [],
        "source" => "local_fallback",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
'''

new_loop = r'''$requestedPlaceName = extract_requested_replacement_name($message);

foreach ($targets as $target) {
    $alternative = null;
    $reason = '';

    if ($requestedPlaceName !== '') {
        $alternative = find_requested_place_by_name($conn, $requestedPlaceName, $target, $usedPlaceIds, $tripStartDate);
        $reason = $alternative ? "matches requested place name" : '';
    }

    if (!$alternative && $requestedPlaceName === '') {
        $alternative = find_alternative_place($conn, $target, $usedPlaceIds, $message, $tripStartDate);
        $reason = $alternative ? build_replacement_reason($target, $alternative) : '';
    }

    if (!$alternative) continue;

    $proposals[] = [
        "item_id" => (int)$target["item_id"],
        "current_title" => (string)$target["item_title"],
        "day_no" => (int)$target["day_no"],
        "sequence_no" => (int)$target["sequence_no"],
        "current_category" => (string)($target["category"] ?? $target["item_type"]),
        "new_place" => format_place($alternative),
        "reason" => $reason !== '' ? $reason : build_replacement_reason($target, $alternative),
    ];
}

if (empty($proposals)) {
    $answer = $requestedPlaceName !== ''
        ? "I could not find \"{$requestedPlaceName}\" in the current cultural places database for this itinerary. Please check the place name or choose another available cultural place."
        : "I could not find a suitable replacement from the current cultural places database. Try asking for a different category, state, or budget direction.";

    echo json_encode([
        "status" => "success",
        "answer" => $answer,
        "proposals" => [],
        "source" => "local_rule",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($requestedPlaceName !== '') {
    echo json_encode([
        "status" => "success",
        "answer" => build_local_editor_answer($proposals),
        "proposals" => $proposals,
        "source" => "local_rule",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
'''

if old_loop in s:
    s = s.replace(old_loop, new_loop, 1)
elif '$requestedPlaceName = extract_requested_replacement_name($message);' not in s:
    raise SystemExit('Could not find replacement loop to patch.')

p.write_text(s, encoding='utf-8')
PY

php -l api/ai_itinerary_editor.php

grep -n "extract_requested_sequence_no" api/ai_itinerary_editor.php
grep -n "extract_requested_replacement_name" api/ai_itinerary_editor.php
grep -n "matches requested place name" api/ai_itinerary_editor.php

echo "Specific itinerary replacement patch applied."
