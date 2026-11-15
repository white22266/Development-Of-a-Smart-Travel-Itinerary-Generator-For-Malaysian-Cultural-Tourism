<?php
// preference/preference_inputs.php
// Pure helpers that read and check the planner inputs of the preference form (day window,
// stops per day, party mix, interest weights, accessibility tags, must-see / avoid places).
// No database and no session here, so they can be tested from the command line.

require_once __DIR__ . '/../config/i18n.php';

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
    foreach ([[t('pref.f_day_start'), trim($start)], [t('pref.f_day_end'), trim($end)]] as $i => [$label, $raw]) {
        if ($raw === '') continue;
        $m = pref_time_to_minutes($raw);
        if ($m === null) {
            $errors[] = t('pref.err_time_invalid', ['field' => $label]);
        } elseif ($m < $min || $m > $max) {
            $errors[] = t('pref.err_time_range', ['field' => $label, 'min' => PREF_DAY_MIN, 'max' => PREF_DAY_MAX]);
        } else {
            $minutes[$i] = $m;
            $out[$i] = sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
        }
    }
    if ($minutes[0] !== null && $minutes[1] !== null && $minutes[1] - $minutes[0] < PREF_DAY_MIN_SPAN_MINUTES) {
        $errors[] = t('pref.err_day_span');
    }
    return [$out[0], $out[1], $errors];
}

/** Stops per day: empty is null, otherwise a whole number from 2 to 8. Returns [value|null, errors]. */
function pref_parse_stops(string $value): array
{
    $value = trim($value);
    if ($value === '') return [null, []];
    if (!ctype_digit($value) || (int)$value < 2 || (int)$value > 8) {
        return [null, [t('pref.err_stops')]];
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
    $names = ['Adults' => t('pref.adults'), 'Children' => t('pref.children'), 'Seniors' => t('pref.seniors')];
    $errors = [];
    $n = [];
    foreach ($raw as $label => $value) {
        if ($value === '') {
            $n[$label] = 0;
        } elseif (!ctype_digit($value) || (int)$value > 20) {
            $errors[] = t('pref.err_count', ['field' => $names[$label]]);
            $n[$label] = 0;
        } else {
            $n[$label] = (int)$value;
        }
    }
    if ($errors) return [null, $errors];
    if ($n['Adults'] + $n['Seniors'] < 1) {
        return [null, [t('pref.err_party_min')]];
    }
    return [[
        'adults' => $n['Adults'], 'children' => $n['Children'], 'seniors' => $n['Seniors'],
        'total' => $n['Adults'] + $n['Children'] + $n['Seniors'],
    ], []];
}

/** The choices for places of worship ('' = no preference): at most one a day, or none. */
const PREF_WORSHIP_LIMITS = ['one', 'none'];

/**
 * Places of worship: blank is no preference (null), otherwise 'one' (at most one a day) or
 * 'none'. Returns [value|null, errors].
 */
function pref_parse_worship_limit(string $value): array
{
    $value = strtolower(trim($value));
    if ($value === '') return [null, []];
    if (!in_array($value, PREF_WORSHIP_LIMITS, true)) return [null, [t('pref.err_worship')]];
    return [$value, []];
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
    foreach ([t('pref.must_label') => $rawMust, t('pref.avoid_label') => $rawAvoid] as $label => $raw) {
        if ($raw === null || $raw === '') continue;
        if (!is_array($raw)) { $errors[] = t('pref.err_places_invalid', ['field' => $label]); continue; }
        foreach ($raw as $value) {
            $value = trim((string)$value);
            if ($value !== '' && (!ctype_digit($value) || (int)$value < 1)) {
                $errors[] = t('pref.err_places_contains', ['field' => $label]);
                break;
            }
        }
    }
    $must = pref_place_ids($rawMust);
    $avoid = pref_place_ids($rawAvoid);
    if (count($must) > PREF_PLACES_MAX) $errors[] = t('pref.err_must_max', ['max' => PREF_PLACES_MAX]);
    if (count($avoid) > PREF_PLACES_MAX) $errors[] = t('pref.err_avoid_max', ['max' => PREF_PLACES_MAX]);
    if (array_intersect($must, $avoid)) $errors[] = t('pref.err_both');
    return [$must, $avoid, $errors];
}
