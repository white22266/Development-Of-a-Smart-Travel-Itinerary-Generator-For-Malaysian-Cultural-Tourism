#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/var/www/travel-system}"
DB_NAME="${DB_NAME:-travel_itinerary_db}"

cd "$PROJECT_DIR"

echo "=== Add festival date columns ==="
if [ -f database/add_festival_dates_to_suggestions.sql ]; then
  sudo mariadb "$DB_NAME" < database/add_festival_dates_to_suggestions.sql
else
  sudo mariadb "$DB_NAME" <<'SQL'
ALTER TABLE cultural_place_suggestions
  ADD COLUMN IF NOT EXISTS festival_start_date DATE NULL AFTER estimated_cost,
  ADD COLUMN IF NOT EXISTS festival_end_date DATE NULL AFTER festival_start_date;

ALTER TABLE cultural_places
  ADD COLUMN IF NOT EXISTS festival_start_date DATE NULL AFTER opening_hours,
  ADD COLUMN IF NOT EXISTS festival_end_date DATE NULL AFTER festival_start_date;
SQL
fi

echo "=== Patch backend PHP files ==="
python3 <<'PY'
from pathlib import Path

root = Path('/var/www/travel-system')

# 1) Traveller submit backend
p = root / 'cultural' / 'suggest_place_process.php'
s = p.read_text()

if 'function normalize_optional_date' not in s:
    marker = '''function table_has_column(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}
'''
    insert = marker + '''
function normalize_optional_date(string $value): ?string
{
    $value = trim($value);
    if ($value === "") return null;
    $dt = DateTime::createFromFormat("Y-m-d", $value);
    return ($dt && $dt->format("Y-m-d") === $value) ? $value : null;
}
'''
    s = s.replace(marker, insert)

if '$festivalStartDate = normalize_optional_date' not in s:
    s = s.replace(
        '''$cost = (float)($_POST["estimated_cost"] ?? 0);
''',
        '''$cost = (float)($_POST["estimated_cost"] ?? 0);
$festivalStartDate = normalize_optional_date((string)($_POST["festival_start_date"] ?? ""));
$festivalEndDate = normalize_optional_date((string)($_POST["festival_end_date"] ?? ""));
'''
    )

if 'Festival start date and end date are required when category is Festival.' not in s:
    s = s.replace(
        '''if ($cost < 0) back("Estimated cost cannot be negative.", true);

if ($latitude === "" || $longitude === "") back("Latitude and Longitude are required.", true);
''',
        '''if ($cost < 0) back("Estimated cost cannot be negative.", true);

if ($category === "festival") {
    if ($festivalStartDate === null || $festivalEndDate === null) {
        back("Festival start date and end date are required when category is Festival.", true);
    }
    if ($festivalEndDate < $festivalStartDate) {
        back("Festival end date cannot be earlier than start date.", true);
    }
} else {
    $festivalStartDate = null;
    $festivalEndDate = null;
}

if ($latitude === "" || $longitude === "") back("Latitude and Longitude are required.", true);
'''
    )

start = s.find('$hasSuggestionDistrict = table_has_column($conn, "cultural_place_suggestions", "district");')
end = s.find('if (!$stmt->execute()) {', start)
end2 = s.find('$stmt->close();', end)
if start != -1 and end != -1 and end2 != -1 and '$hasSuggestionFestivalStart' not in s[start:end2]:
    tail = s[end:end2 + len('$stmt->close();')]
    new_block = r'''$hasSuggestionDistrict = table_has_column($conn, "cultural_place_suggestions", "district");
$hasSuggestionFestivalStart = table_has_column($conn, "cultural_place_suggestions", "festival_start_date");
$hasSuggestionFestivalEnd = table_has_column($conn, "cultural_place_suggestions", "festival_end_date");

$columns = ["traveller_id", "state"];
$values = ["?", "?"];
$types = "is";
$params = [$travellerId, $state];

if ($hasSuggestionDistrict) {
    $columns[] = "district";
    $values[] = "?";
    $types .= "s";
    $params[] = $district;
}

foreach ([
    "category" => $category,
    "name" => $name,
    "description" => $description,
    "address" => $address,
] as $column => $value) {
    $columns[] = $column;
    $values[] = "?";
    $types .= "s";
    $params[] = $value;
}

$columns[] = "latitude";
$values[] = "?";
$types .= "d";
$params[] = (float)$latitude;

$columns[] = "longitude";
$values[] = "?";
$types .= "d";
$params[] = (float)$longitude;

$columns[] = "opening_hours";
$values[] = "?";
$types .= "s";
$params[] = $opening;

$columns[] = "estimated_cost";
$values[] = "?";
$types .= "d";
$params[] = $cost;

if ($hasSuggestionFestivalStart && $hasSuggestionFestivalEnd) {
    $columns[] = "festival_start_date";
    $values[] = "?";
    $types .= "s";
    $params[] = $festivalStartDate;

    $columns[] = "festival_end_date";
    $values[] = "?";
    $types .= "s";
    $params[] = $festivalEndDate;
}

$columns[] = "image_url";
$values[] = "?";
$types .= "s";
$params[] = $imageUrl;

$columns[] = "status";
$values[] = "'pending'";

$sql = "INSERT INTO cultural_place_suggestions (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $values) . ")";
$stmt = $conn->prepare($sql);
if (!$stmt) back("System error: cannot submit suggestion. (" . $conn->error . ")", true);

$stmt->bind_param($types, ...$params);

'''
    s = s[:start] + new_block + tail + s[end2 + len('$stmt->close();'):]

p.write_text(s)

# 2) Admin review detail display
p = root / 'admin' / 'admin_pending.php'
s = p.read_text()
if 'Festival Date:</strong>' not in s:
    s = s.replace(
        '''<p><strong>Estimated Cost:</strong> RM <?php echo number_format((float)($viewRow["estimated_cost"] ?? 0), 2); ?></p>
''',
        '''<p><strong>Estimated Cost:</strong> RM <?php echo number_format((float)($viewRow["estimated_cost"] ?? 0), 2); ?></p>
                        <?php if (($viewRow["category"] ?? "") === "festival"): ?>
                            <p><strong>Festival Date:</strong> <?php echo htmlspecialchars(($viewRow["festival_start_date"] ?? "-") . " to " . ($viewRow["festival_end_date"] ?? "-")); ?></p>
                        <?php endif; ?>
'''
    )
p.write_text(s)

# 3) Admin approve backend copies date to cultural_places
p = root / 'admin' / 'admin_pending_process.php'
s = p.read_text()
if 'Festival start/end dates are required before approving festival suggestions.' not in s:
    s = s.replace(
        '''    $category = $sug["category"];
''',
        '''    $category = $sug["category"];
    if ($category === "festival") {
        if (empty($sug["festival_start_date"]) || empty($sug["festival_end_date"])) {
            throw new Exception("Festival start/end dates are required before approving festival suggestions.");
        }
        if ($sug["festival_end_date"] < $sug["festival_start_date"]) {
            throw new Exception("Festival end date cannot be earlier than start date.");
        }
    }
'''
    )

if 'festival_start_date = ?' not in s:
    s = s.replace(
        '''    if (table_has_column($conn, "cultural_places", "is_free")) {
        $postSets[] = "is_free = ?";
        $postParams[] = ($cost <= 0.0) ? 1 : 0;
        $postTypes .= "i";
    }

    if (!empty($postSets)) {
''',
        '''    if (table_has_column($conn, "cultural_places", "is_free")) {
        $postSets[] = "is_free = ?";
        $postParams[] = ($cost <= 0.0) ? 1 : 0;
        $postTypes .= "i";
    }
    if (($sug["category"] ?? "") === "festival"
        && table_has_column($conn, "cultural_places", "festival_start_date")
        && table_has_column($conn, "cultural_places", "festival_end_date")) {
        $postSets[] = "festival_start_date = ?";
        $postParams[] = $sug["festival_start_date"] ?? null;
        $postTypes .= "s";
        $postSets[] = "festival_end_date = ?";
        $postParams[] = $sug["festival_end_date"] ?? null;
        $postTypes .= "s";
    }

    if (!empty($postSets)) {
'''
    )
p.write_text(s)
PY

echo "=== Syntax check ==="
php -l cultural/suggest_place_process.php
php -l admin/admin_pending.php
php -l admin/admin_pending_process.php

echo "=== Fix permissions and restart Apache ==="
sudo chown -R www-data:www-data "$PROJECT_DIR"
sudo find "$PROJECT_DIR" -type d -exec chmod 755 {} \;
sudo find "$PROJECT_DIR" -type f -exec chmod 644 {} \;
sudo chmod -R 775 "$PROJECT_DIR/uploads" 2>/dev/null || true
sudo systemctl restart apache2

echo "=== Verify columns ==="
sudo mariadb "$DB_NAME" -e "SHOW COLUMNS FROM cultural_place_suggestions LIKE 'festival_%'; SHOW COLUMNS FROM cultural_places LIKE 'festival_%';"

echo "DONE"
