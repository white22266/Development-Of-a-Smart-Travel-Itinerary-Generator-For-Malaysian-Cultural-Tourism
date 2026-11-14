<?php
// preference/preference_inputs.php
// Pure helpers that read and check the planner inputs of the preference form (day window,
// stops per day, party mix, interest weights, accessibility tags, must-see / avoid places).
// No database and no session here, so they can be tested from the command line.

const PREF_ACCESS_TAGS = ['avoid_heat', 'avoid_stairs', 'elderly', 'low_walking', 'wheelchair'];
const PREF_DAY_MIN = '06:00';
const PREF_DAY_MAX = '23:00';
const PREF_DAY_MIN_SPAN_MINUTES = 180;
const PREF_PLACES_MAX = 10;

/** "HH:MM" or "HH:MM:SS" to minutes after midnight; null when it is not a valid time. */
function pref_time_to_minutes(string $value): ?int
{
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m)) return null;
    return ((int)$m[1]) * 60 + (int)$m[2];
}

/**
 * Day window. Empty input means "not set" (null). Returns [start|null, end|null, errors];
 * start and end are "HH:MM:00" strings ready for TIME columns.
 */
function pref_parse_day_window(string $start, string $end): array
{
    $errors = [];
    $out = [null, null];
    $minutes = [null, null];
    $min = pref_time_to_minutes(PREF_DAY_MIN);
    $max = pref_time_to_minutes(PREF_DAY_MAX);
    foreach ([['Day start time', trim($start)], ['Day end time', trim($end)]] as $i => [$label, $raw]) {
        if ($raw === '') continue;
        $m = pref_time_to_minutes($raw);
        if ($m === null) {
            $errors[] = $label . ' is not a valid time.';
        } elseif ($m < $min || $m > $max) {
            $errors[] = $label . ' must be between ' . PREF_DAY_MIN . ' and ' . PREF_DAY_MAX . '.';
        } else {
            $minutes[$i] = $m;
            $out[$i] = sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
        }
    }
    if ($minutes[0] !== null && $minutes[1] !== null && $minutes[1] - $minutes[0] < PREF_DAY_MIN_SPAN_MINUTES) {
        $errors[] = 'The day must end at least 3 hours after it starts.';
    }
    return [$out[0], $out[1], $errors];
}

/** Stops per day: empty is null, otherwise a whole number from 2 to 8. Returns [value|null, errors]. */
function pref_parse_stops(string $value): array
{
    $value = trim($value);
    if ($value === '') return [null, []];
    if (!ctype_digit($value) || (int)$value < 2 || (int)$value > 8) {
        return [null, ['Stops per day must be a whole number from 2 to 8.']];
    }
    return [(int)$value, []];
}

/**
 * Party mix. All three blank returns [null, errors=[]]. Otherwise blanks count as 0 and the
 * result is ['adults'=>, 'children'=>, 'seniors'=>, 'total'=>]. Each number is 0 to 20; the
 * total is at least 1 and there must be at least one adult or senior.
 */
function pref_parse_party(string $adults, string $children, string $seniors): array
{
    $raw = ['Adults' => trim($adults), 'Children' => trim($children), 'Seniors' => trim($seniors)];
    if ($raw['Adults'] === '' && $raw['Children'] === '' && $raw['Seniors'] === '') return [null, []];
    $errors = [];
    $n = [];
    foreach ($raw as $label => $value) {
        if ($value === '') {
            $n[$label] = 0;
        } elseif (!ctype_digit($value) || (int)$value > 20) {
            $errors[] = $label . ' must be a whole number from 0 to 20.';
            $n[$label] = 0;
        } else {
            $n[$label] = (int)$value;
        }
    }
    if ($errors) return [null, $errors];
    if ($n['Adults'] + $n['Seniors'] < 1) {
        return [null, ['At least one adult or senior must be travelling.']];
    }
    return [[
        'adults' => $n['Adults'], 'children' => $n['Children'], 'seniors' => $n['Seniors'],
        'total' => $n['Adults'] + $n['Children'] + $n['Seniors'],
    ], []];
}

/** Interest weight 1 (a little), 2 (like), 3 (love). Blank means the default 2; null when invalid. */
function pref_parse_weight($value): ?int
{
    $value = trim((string)$value);
    if ($value === '') return 2;
    if (!in_array($value, ['1', '2', '3'], true)) return null;
    return (int)$value;
}

/**
 * Sorted, comma-separated accessibility tags: the ticked tags plus the derived ones, limited
 * to the five names the planner knows. Returns null when there are none.
 */
function pref_accessibility_tag_list(array $ticked, array $derived): ?string
{
    $tags = [];
    foreach (array_merge($ticked, $derived) as $tag) {
        $tag = strtolower(trim((string)$tag));
        if (in_array($tag, PREF_ACCESS_TAGS, true)) $tags[$tag] = true;
    }
    $list = array_keys($tags);
    sort($list, SORT_STRING);
    return $list ? implode(',', $list) : null;
}

/** Posted list of place ids to a list of unique positive ints; null entries are dropped. */
function pref_place_ids($posted): array
{
    if (!is_array($posted)) return [];
    $ids = [];
    foreach ($posted as $value) {
        $value = trim((string)$value);
        if ($value !== '' && ctype_digit($value) && (int)$value > 0) $ids[(int)$value] = true;
    }
    return array_keys($ids);
}

/**
 * Check the two place lists. $rawMust / $rawAvoid are the posted arrays. Returns
 * [mustIds, avoidIds, errors]; ids that are not positive integers are reported as errors.
 */
function pref_check_place_lists($rawMust, $rawAvoid): array
{
    $errors = [];
    foreach (['Places I must see' => $rawMust, 'Places to avoid' => $rawAvoid] as $label => $raw) {
        if ($raw === null || $raw === '') continue;
        if (!is_array($raw)) { $errors[] = $label . ' is invalid.'; continue; }
        foreach ($raw as $value) {
            $value = trim((string)$value);
            if ($value !== '' && (!ctype_digit($value) || (int)$value < 1)) {
                $errors[] = $label . ' contains an invalid place.';
                break;
            }
        }
    }
    $must = pref_place_ids($rawMust);
    $avoid = pref_place_ids($rawAvoid);
    if (count($must) > PREF_PLACES_MAX) $errors[] = 'Choose at most ' . PREF_PLACES_MAX . ' places you must see.';
    if (count($avoid) > PREF_PLACES_MAX) $errors[] = 'Choose at most ' . PREF_PLACES_MAX . ' places to avoid.';
    if (array_intersect($must, $avoid)) $errors[] = 'A place cannot be both a must-see and a place to avoid.';
    return [$must, $avoid, $errors];
}
