<?php
// services/ItineraryEditService.php
// The one place that changes a stored itinerary. Every page and endpoint that edits a trip
// (the review page, the AI editor, the hotel choice, the "Optimise route" button) comes here.
//
// Each change runs in one transaction of its own (call it outside a transaction), is judged by
// PlannerRules and re-times the days it touches with the generator's own timing
// (ItineraryRouteOptimiser::timeInOrder(), which is ItineraryDayBuilder::fit(): meals placed
// again, rest and day window kept). A change that would break a hard rule is refused and
// leaves every row as it was. The stops keep the order the traveller asked for; a replacement
// keeps the stop's position in the day, not its old clock times.
//
// Which days are re-timed: the days whose stops change, and every later day whose starting
// point moves because of it (a day starts at the hotel of the night before when one is
// chosen, otherwise at the last stop of the day before, as the generator plans it).
//
// Note rows stay consistent: a day left without stops gets the generator's "Free day" note,
// which goes again when the day has a stop; notes about must-see places stay. Meal rows are
// not stops: they are placed again whenever their day is re-timed. A meal the traveller chose
// (replaceStop() on a meal row) is marked in its notes and kept while it still fits.
//
// A day may have a start time of its own (table itinerary_days, migration
// 20261117_itinerary_days.sql, set with setDayStart()): every re-timing of that day (an edit,
// a hotel choice, the optimiser, planning the day again) then starts it there instead of at
// the start of the preference's day window. The end of the day and every other rule stay.
//
// Road figures: the legs stored with the trip are the figures at hand. A change is screened on
// them (estimates for the rest); the legs the days it touches need are then measured on the
// road together, in one route request (a second only for legs the new timing needs), and the
// days timed again: at most MAX_REQUESTS per operation, ROUTE_TIMEOUT seconds each, estimates
// when the server does not answer. Suggestions and positions are measured in a batch, never
// one by one. Setting a day's start asks nothing. The figures a plan was timed with are the
// ones stored (routes() with the operation's route service). See docs/PLANNER.md.
//
// Every operation returns a result of one shape:
//   ['ok' => bool, 'status' => 'done'|'refused', 'code' => null|reason code
//    (PlannerRules::REASONS), 'message' => one sentence for the traveller, 'days' => the days
//    that were re-timed, ...] plus the fields each operation names.
// The caller checks that the itinerary belongs to the signed-in traveller.

require_once __DIR__ . '/RouteService.php';
require_once __DIR__ . '/CostEstimationService.php';
require_once __DIR__ . '/PlannerRules.php';
require_once __DIR__ . '/PlannerCandidates.php';
require_once __DIR__ . '/ItineraryDayBuilder.php';
require_once __DIR__ . '/ItineraryRouteOptimiser.php';
require_once __DIR__ . '/ItineraryHotelService.php';
require_once __DIR__ . '/ItineraryGenerator.php';
require_once __DIR__ . '/WeatherService.php';

class ItineraryEditService
{
    /** How many options suggestReplacements() gives when no limit is asked for. */
    public const SUGGEST_LIMIT = 3;
    /** Ranked candidates timed with the whole trip per option wanted (at least SUGGEST_MIN_TRIED in all). */
    private const SUGGEST_LOOKAHEAD = 6;
    private const SUGGEST_MIN_TRIED = 40;
    /**
     * Road figures (see "How road figures are asked for" in docs/PLANNER.md): an operation
     * makes at most this many routing requests, waits at most ROUTE_TIMEOUT seconds for each,
     * and goes on with estimates when the server is slow or does not answer.
     */
    private const MAX_REQUESTS = 2;
    private const ROUTE_TIMEOUT = 5;
    /** Options screened on the estimates and measured on the road together, beyond those wanted. */
    private const SUGGEST_SPARE = 2;
    /** Positions of a stop measured on the road together when addStop() or moveStop() choose the position. */
    private const POSITIONS_MEASURED = 3;
    /** Why a day is empty, in the generator's "Free day" note, when an edit left it so. */
    private const EMPTIED = 'its stops were removed or moved';

    /** Loaded itineraries for checks that change nothing; every change clears it. */
    private static array $cache = [];

    // =====================================================================================
    // Public operations
    // =====================================================================================

    /**
     * Feasible replacements for one stop, best first by the planner's own score (ties by
     * place_id), each checked by timing the whole trip with it in the stop's position.
     * Deterministic: $offset skips the options already shown ("more options").
     *
     * For a meal row: suitable food places for that meal, least detour first, then a break
     * without a place (place_id 0) when the meal is not one already.
     *
     * @param int[] $exclude place ids not to offer (e.g. replacements pending on the page)
     * @return array result + kind ('visit'|'meal'), slot (meal only), day_no, item_id, offset,
     *         more (bool: further options exist), options: list of [place_id, name, category,
     *         item_type, state, district, address, opening_hours, cost (per person), rating,
     *         start, end ('HH:MM' as it would be planned), score (null for meals), detour_km
     *         (meals), reasons (string[])]
     */
    public static function suggestReplacements(mysqli $conn, int $itineraryId, int $itemId, int $limit = self::SUGGEST_LIMIT, int $offset = 0, array $exclude = []): array
    {
        $ctx = self::context($conn, $itineraryId);
        if ($ctx === null) return self::refused('NOT_FOUND', 'This itinerary was not found.');
        $found = self::findItem($ctx, $itemId);
        if ($found === null) return self::refused('NOT_FOUND', 'This stop is not part of the itinerary.');
        [$kind, $dayNo, $index] = $found;
        $limit = max(1, min(20, $limit));
        $offset = max(0, $offset);
        $exclude = array_fill_keys(array_map('intval', $exclude), true);
        if ($kind === 'meal') return self::mealOptions($ctx, $dayNo, $index, $limit, $offset, $exclude);
        if ($kind !== 'visit') return self::refused('NOT_EDITABLE', 'Only a visit or a meal can be replaced.');
        if ($ctx['days'][$dayNo]['locked']) return self::refused('LOCKED', 'Day ' . $dayNo . ' holds a row the planner cannot re-time, so its stops cannot be replaced here.');

        $ctx['budget_from'] = $ctx['route']->requestsMade();   // a loaded itinerary may be used again: this call has its own budget
        $stops = self::visits($ctx)[$dayNo];
        $target = $stops[$index];
        $used = self::usedPlaces($ctx) + $exclude;   // the stop's own place is in it: not offered again
        [$state, $dayCtx] = self::stateBefore($ctx, $dayNo, $stops, $index, $used);
        $builder = new ItineraryDayBuilder(self::dayProfile($ctx, $dayNo), $ctx['route'], $ctx['meal_pool']);
        $want = $offset + $limit + 1;
        $ignored = null;
        $ranked = $builder->ranked($ctx['pool'], $dayCtx, $state, max(self::SUGGEST_MIN_TRIED, $want * self::SUGGEST_LOOKAHEAD), $ignored, false, true);

        // Candidates are screened on the figures at hand (the stored legs, estimates for the
        // rest), best first. Those that pass are measured on the road together, in one
        // request, and timed again; one that fails then gives way to the next. Once the
        // requests are used up, the figures at hand decide.
        $options = [];
        $next = 0;
        $count = count($ranked);
        while (count($options) < $want && $next < $count) {
            $room = self::requestsLeft($ctx) > 0;
            $size = $want - count($options) + ($room ? self::SUGGEST_SPARE : 0);
            $short = [];
            while ($next < $count && count($short) < $size) {
                $candidate = $ranked[$next++];
                $days = self::visits($ctx);
                $days[$dayNo][$index] = self::stopOf($candidate['place'], (int)$target['item_id'], $candidate['tier'], $candidate['reasons'], (bool)$candidate['must']);
                $timed = self::timeDays($ctx, $days, [$dayNo], []);
                if ($timed['result']['ok']) $short[] = [$candidate, $days, $timed];
            }
            if (!$short) break;
            $measured = 0;
            if ($room) {
                $legs = [];
                foreach ($short as [, $days, $timed]) array_push($legs, ...self::unknownLegs($ctx, $days, $timed));
                $measured = self::measure($ctx, $legs);
            }
            foreach ($short as [$candidate, $days, $timed]) {
                if ($measured > 0) $timed = self::timeDays($ctx, $days, [$dayNo], []);
                if (!$timed['result']['ok']) continue;
                $options[] = self::option($candidate['place'], $timed['result']['plans'][$dayNo]['stops'][$index], $candidate['score'], $candidate['reasons']);
                if (count($options) >= $want) break;
            }
        }
        $page = array_slice($options, $offset, $limit);
        return self::done($page ? count($page) . ' option(s) fit this stop.' : 'No other place fits this stop under the rules of your trip.', [
            'kind' => 'visit', 'item_id' => $itemId, 'day_no' => $dayNo, 'offset' => $offset,
            'more' => count($options) > $offset + $limit, 'options' => $page,
        ]);
    }

    /**
     * Put another place into a stop. A visit keeps its position in the day and gets new times.
     * On a meal row: $placeId a food place for that meal, or 0 for a break without a place.
     *
     * @return array result + item_id
     */
    public static function replaceStop(mysqli $conn, int $itineraryId, int $itemId, int $placeId): array
    {
        return self::change($conn, $itineraryId, static fn(array $ctx): array => self::buildReplace($ctx, $itemId, $placeId));
    }

    /** Whether replaceStop() would be done, without changing anything (same result, nothing stored). */
    public static function canReplace(mysqli $conn, int $itineraryId, int $itemId, int $placeId): array
    {
        return self::check($conn, $itineraryId, static fn(array $ctx): array => self::buildReplace($ctx, $itemId, $placeId));
    }

    /**
     * Take a visit out of its day. The last visit of a day stays unless $allowEmptyDay, and the
     * day then gets the "Free day" note.
     *
     * @return array result + item_id
     */
    public static function removeStop(mysqli $conn, int $itineraryId, int $itemId, bool $allowEmptyDay = false): array
    {
        return self::change($conn, $itineraryId, static function (array $ctx) use ($itemId, $allowEmptyDay): array {
            $found = self::findItem($ctx, $itemId);
            if ($found === null) return ['refused' => self::refused('NOT_FOUND', 'This stop is not part of the itinerary.')];
            [$kind, $dayNo, $index] = $found;
            if ($kind === 'meal') return ['refused' => self::refused('NOT_EDITABLE', 'A meal is planned with its day; replace it with a break instead of removing it.')];
            if ($kind !== 'visit') return ['refused' => self::refused('NOT_EDITABLE', 'Only a visit can be removed.')];
            $days = self::visits($ctx);
            $title = (string)$days[$dayNo][$index]['row']['item_title'];
            if (count($days[$dayNo]) === 1 && !$allowEmptyDay) {
                return ['refused' => self::refused('LAST_STOP', $title . ' is the last stop of day ' . $dayNo . ', so it stays. Replace it instead, or keep at least one stop on each day.')];
            }
            array_splice($days[$dayNo], $index, 1);
            return ['days' => $days, 'changed' => [$dayNo], 'deleted' => [$itemId],
                    'message' => $title . ' was removed from day ' . $dayNo . ', and the day was timed again.', 'extra' => ['item_id' => $itemId]];
        });
    }

    /**
     * Add a place to a day, at $position (1 = first visit of the day; meals do not count) or,
     * when null, at the feasible position with the least travelling.
     *
     * @return array result + item_id (the new row), position
     */
    public static function addStop(mysqli $conn, int $itineraryId, int $dayNo, int $placeId, ?int $position = null): array
    {
        return self::change($conn, $itineraryId, static fn(array $ctx): array => self::buildAdd($ctx, $dayNo, $placeId, $position));
    }

    /** Whether addStop() would be done, without changing anything. */
    public static function canAdd(mysqli $conn, int $itineraryId, int $dayNo, int $placeId, ?int $position = null): array
    {
        return self::check($conn, $itineraryId, static fn(array $ctx): array => self::buildAdd($ctx, $dayNo, $placeId, $position));
    }

    /**
     * Move a visit to another day (or to another position in its own day). $toPosition as for
     * addStop(); null: the feasible position with the least travelling. A day keeps its last
     * visit unless $allowEmptyDay.
     *
     * @return array result + item_id, position
     */
    public static function moveStop(mysqli $conn, int $itineraryId, int $itemId, int $toDay, ?int $toPosition = null, bool $allowEmptyDay = false): array
    {
        return self::change($conn, $itineraryId, static function (array $ctx) use ($itemId, $toDay, $toPosition, $allowEmptyDay): array {
            $found = self::findItem($ctx, $itemId);
            if ($found === null) return ['refused' => self::refused('NOT_FOUND', 'This stop is not part of the itinerary.')];
            [$kind, $fromDay, $index] = $found;
            if ($kind !== 'visit') return ['refused' => self::refused('NOT_EDITABLE', 'Only a visit can be moved.')];
            if ($toDay < 1 || $toDay > $ctx['total_days']) return ['refused' => self::refused('NOT_FOUND', 'The trip has no day ' . $toDay . '.')];
            $days = self::visits($ctx);
            $stop = $days[$fromDay][$index];
            $title = (string)$stop['row']['item_title'];
            if ($toDay !== $fromDay) {
                if (count($days[$toDay]) >= (int)$ctx['profile']['target']) {
                    return ['refused' => self::refused('DAY_FULL', 'Day ' . $toDay . ' already has its ' . (int)$ctx['profile']['target'] . ' planned stops, so ' . $title . ' cannot move there.')];
                }
                if (count($days[$fromDay]) === 1 && !$allowEmptyDay) {
                    return ['refused' => self::refused('LAST_STOP', $title . ' is the last stop of day ' . $fromDay . ', so it stays there. Keep at least one stop on each day.')];
                }
            }
            array_splice($days[$fromDay], $index, 1);
            $changed = array_values(array_unique([$fromDay, $toDay]));
            $layout = self::placeInDay($ctx, $days, $toDay, $stop, $toPosition, $changed, ['why_empty' => self::EMPTIED]);
            if (isset($layout['refused'])) return $layout;
            return ['days' => $layout['days'], 'changed' => $changed, 'opts' => ['why_empty' => self::EMPTIED],
                    'message' => $title . ' moved to day ' . $toDay . ' as stop ' . $layout['position'] . ', and the days were timed again.',
                    'extra' => ['item_id' => $itemId, 'position' => $layout['position']]];
        });
    }

    /**
     * Plan one day again. The other days and their places stay as they are and are not used
     * again; the must-see places of the day are kept.
     *
     * @param array $options more (categories to see more of: they become interests loved),
     *                       less (categories to see less of: weight "a little"), forecast
     *                       (Y-m-d => chance of rain) or rain (chance of rain for this day)
     * @return array result + stops (the number of visits the day now has)
     */
    public static function regenerateDay(mysqli $conn, int $itineraryId, int $dayNo, array $options = []): array
    {
        return self::change($conn, $itineraryId, static fn(array $ctx): array => self::buildRegenerate($ctx, $dayNo, $options));
    }

    /**
     * Save the hotel of each night (night N = after day N; a night left out has none), then
     * start every day after a hotel night from that hotel and time it again. A night whose
     * hotel would make the next day break a rule is not saved; the others are.
     *
     * @param array<int,array> $hotelByNight night => a hotel as HotelRecommendationService returns it
     * @return array result + nights (saved), refused_nights (night => [code, message])
     */
    public static function setHotels(mysqli $conn, int $itineraryId, array $hotelByNight): array
    {
        self::$cache = [];
        $refusedNights = [];
        $nights = 0;
        $retimed = [];
        $route = null;   // one for every attempt: the road figures and the request budget are shared
        while (true) {
            $conn->begin_transaction();
            try {
                $before = self::context($conn, $itineraryId, false, $route);
                if ($before === null) { $conn->rollback(); return self::refused('NOT_FOUND', 'This itinerary was not found.'); }
                $route = $before['route'];
                $oldStarts = self::starts($before, self::visits($before));
                $saved = ItineraryHotelService::saveNights($conn, $itineraryId, $before['total_days'], $hotelByNight);
                $ctx = self::context($conn, $itineraryId, false, $route, false);   // the legs of $before stay at hand
                $plan = self::plan($ctx, self::visits($ctx), [], ['old_starts' => $oldStarts, 'budget' => false]);
                if (!$plan['ok']) {
                    $conn->rollback();
                    $night = (int)$plan['day'] - 1;
                    if ($night < 1 || !isset($hotelByNight[$night])) {
                        return self::refused((string)$plan['code'], (string)$plan['message']);
                    }
                    $refusedNights[$night] = [(string)$plan['code'], 'The hotel for night ' . $night . ' was not saved: ' . lcfirst((string)$plan['message'])];
                    unset($hotelByNight[$night]);
                    continue;
                }
                self::store($conn, $ctx, self::visits($ctx), $plan, [], []);
                $conn->commit();
                $nights = (int)$saved['nights'];
                $retimed = $plan['retimed'];
                break;
            } catch (Throwable $e) {
                $conn->rollback();
                self::$cache = [];
                throw $e;
            }
        }
        self::$cache = [];
        $message = $nights . ' hotel night(s) saved' . ($retimed ? '; day(s) ' . implode(', ', $retimed) . ' timed again from the hotel.' : '.');
        if ($refusedNights) $message .= ' ' . implode(' ', array_column($refusedNights, 1));
        return self::done($message, ['days' => $retimed, 'nights' => $nights, 'refused_nights' => $refusedNights]);
    }

    /**
     * Start every day at $startMinute (minutes after midnight) instead of the start of the
     * day window, and time every day again. The start is stored for every day, so later
     * re-timings keep it (setDayStart() for the whole trip). Refused when a stop would then
     * break a rule.
     */
    public static function retimeTrip(mysqli $conn, int $itineraryId, int $startMinute): array
    {
        return self::setDayStart($conn, $itineraryId, null, $startMinute);
    }

    /**
     * Store the time day $dayNo starts (null: every day of the trip) and time the day(s) again
     * from it. $startMinute null removes the stored start: the day starts at the start of the
     * preference's day window again. The day's end and every other rule stay as they are; a
     * start that leaves no room for the day's stops is refused with the reason, and nothing
     * is stored.
     *
     * @return array result + day_no (null: every day), start ('HH:MM' or null)
     */
    public static function setDayStart(mysqli $conn, int $itineraryId, ?int $dayNo, ?int $startMinute): array
    {
        return self::change($conn, $itineraryId, static function (array $ctx) use ($dayNo, $startMinute): array {
            if (!self::hasDayTable($ctx['conn'])) {
                return ['refused' => self::refused('UNAVAILABLE', 'Day start times cannot be stored on this database yet.')];
            }
            if ($dayNo !== null && ($dayNo < 1 || $dayNo > $ctx['total_days'])) {
                return ['refused' => self::refused('NOT_FOUND', 'The trip has no day ' . $dayNo . '.')];
            }
            $end = (int)$ctx['profile']['end'];
            if ($startMinute !== null) {
                if ($startMinute < PlannerRules::EARLIEST_START) {
                    return ['refused' => self::refused('EARLY_START', 'A day cannot start before ' . self::hm(PlannerRules::EARLIEST_START) . '.')];
                }
                if ($startMinute + PlannerRules::MAX_WAIT_MIN > $end) {
                    return ['refused' => self::refused('DAY_END', 'A day starting at ' . self::hm($startMinute) . ' leaves no time before the end of your day at ' . self::hm($end) . '.')];
                }
            }
            $days = $dayNo === null ? array_keys($ctx['days']) : [$dayNo];
            $starts = $ctx['day_starts'];
            foreach ($days as $d) {
                if ($startMinute === null) unset($starts[$d]);
                else $starts[$d] = $startMinute;
            }
            $what = $dayNo === null ? 'Every day' : 'Day ' . $dayNo;
            $message = $startMinute === null
                ? $what . ' now starts at the start of your day window again (' . self::hm((int)$ctx['profile']['start']) . '), and the times were planned again.'
                : $what . ' now starts at ' . self::hm($startMinute) . ', and the times were planned again.';
            // No stop moves, so nothing is asked of the routing server: the stored legs time the day.
            return ['days' => self::visits($ctx), 'changed' => $days, 'opts' => ['day_starts' => $starts, 'measure' => false],
                    'message' => $message,
                    'extra' => ['day_no' => $dayNo, 'start' => $startMinute !== null ? self::hm($startMinute) : null]];
        });
    }

    /**
     * The stored start of each day: day => minutes after midnight. A day without one is left
     * out; a database without the table gives an empty list.
     */
    public static function dayStarts(mysqli $conn, int $itineraryId): array
    {
        if (!self::hasDayTable($conn)) return [];
        $stmt = $conn->prepare("SELECT day_no, start_time FROM itinerary_days WHERE itinerary_id = ? AND start_time IS NOT NULL ORDER BY day_no");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $minute = PlannerRules::minutesOf($row['start_time']);
            if ($minute !== null) $out[(int)$row['day_no']] = $minute;
        }
        $stmt->close();
        return $out;
    }

    /**
     * The forecast chance of rain (percent) of the trip's dates inside the forecast horizon
     * (today and the 15 days after it). As the generator asks it: once for the trip, at its
     * starting point (without one: the first stop). A date without a figure is left out;
     * nothing is asked when no trip date lies inside the horizon. Never throws.
     *
     * @return array<string,int> Y-m-d => chance of rain
     */
    public static function forecast(mysqli $conn, int $itineraryId): array
    {
        $stmt = $conn->prepare("SELECT start_date, total_days, origin_lat, origin_lng FROM itineraries WHERE itinerary_id = ? LIMIT 1");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta || empty($meta['start_date'])) return [];
        $zone = new DateTimeZone('Asia/Kuala_Lumpur');
        $today = (new DateTimeImmutable('today', $zone))->format('Y-m-d');
        $horizon = (new DateTimeImmutable('today', $zone))->modify('+15 days')->format('Y-m-d');
        $dates = [];
        for ($d = 1; $d <= max(1, (int)$meta['total_days']); $d++) {
            $date = (new DateTimeImmutable((string)$meta['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
            if ($date >= $today && $date <= $horizon) $dates[] = $date;
        }
        if (!$dates) return [];

        $point = self::point($meta['origin_lat'] ?? null, $meta['origin_lng'] ?? null);
        if ($point === null) {
            $stmt = $conn->prepare("
                SELECT COALESCE(ii.item_latitude, cp.latitude) AS lat, COALESCE(ii.item_longitude, cp.longitude) AS lng
                FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
                WHERE ii.itinerary_id = ? AND ii.item_type IN ('attraction','food','festival')
                ORDER BY ii.day_no, ii.sequence_no, ii.item_id
            ");
            $stmt->bind_param('i', $itineraryId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $point = self::point($row['lat'], $row['lng']);
                if ($point !== null) break;
            }
            $stmt->close();
        }
        if ($point === null) return [];
        try {
            return WeatherService::rainChances($point[0], $point[1], $dates);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Plan again every day whose chance of rain is $threshold percent or more, with the
     * forecast (the day builder then prefers indoor places, and from 80 percent tries them
     * first). The other days stay as they are. Each day is planned in a transaction of its
     * own: a day that cannot be planned again keeps its stops, and the others are still done.
     *
     * @param array|null $forecast Y-m-d => chance of rain; null: forecast()
     * @return array result + rainy (day => chance), changes (day => status, code, message,
     *         rain, removed (names), added (names))
     */
    public static function replanRainyDays(mysqli $conn, int $itineraryId, ?array $forecast = null, int $threshold = ItineraryDayBuilder::RAIN_PREFER): array
    {
        $stmt = $conn->prepare("SELECT start_date, total_days FROM itineraries WHERE itinerary_id = ? LIMIT 1");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta) return self::refused('NOT_FOUND', 'This itinerary was not found.');
        $forecast ??= self::forecast($conn, $itineraryId);

        $rainy = [];
        for ($d = 1; $d <= max(1, (int)$meta['total_days']); $d++) {
            $date = (new DateTimeImmutable((string)$meta['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
            if (isset($forecast[$date]) && is_numeric($forecast[$date]) && (int)$forecast[$date] >= $threshold) $rainy[$d] = (int)$forecast[$date];
        }
        if (!$rainy) {
            return self::done('No day of the trip has a forecast chance of rain of ' . $threshold . '% or more, so nothing was changed.', ['rainy' => [], 'changes' => []]);
        }

        $changes = [];
        $retimed = [];
        $sentences = [];
        $firstRefusal = null;
        foreach ($rainy as $d => $chance) {
            $before = self::dayTitles($conn, $itineraryId, $d);
            $r = self::regenerateDay($conn, $itineraryId, $d, ['forecast' => $forecast]);
            $after = self::dayTitles($conn, $itineraryId, $d);
            $removed = array_values(array_diff_key($before, $after));
            $added = array_values(array_diff_key($after, $before));
            $changes[$d] = ['status' => $r['status'], 'code' => $r['code'], 'message' => $r['message'], 'rain' => $chance,
                            'removed' => $removed, 'added' => $added];
            if ($r['ok']) {
                $retimed = array_merge($retimed, (array)$r['days']);
                $sentences[] = 'Day ' . $d . ' (' . $chance . '% chance of rain): '
                    . ($removed || $added
                        ? ($removed ? implode(', ', $removed) . ' out' : '') . ($removed && $added ? '; ' : '') . ($added ? implode(', ', $added) . ' in' : '') . '.'
                        : 'the same places fit best, times planned again.');
            } else {
                $firstRefusal ??= $r;
                $sentences[] = 'Day ' . $d . ' (' . $chance . '% chance of rain) was kept: ' . lcfirst((string)$r['message']);
            }
        }
        $extra = ['rainy' => $rainy, 'changes' => $changes];
        if (count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'done')) === 0) {
            return self::refused((string)$firstRefusal['code'], implode(' ', $sentences), $extra);
        }
        return self::done(implode(' ', $sentences), ['days' => array_values(array_unique($retimed))] + $extra);
    }

    /**
     * Reorder and re-time every day (the "Optimise route" button), in a transaction of its
     * own; a day the optimiser left without stops gets the "Free day" note.
     *
     * @return array result + report (ItineraryRouteOptimiser::optimise())
     */
    public static function optimise(mysqli $conn, int $itineraryId): array
    {
        self::$cache = [];
        $conn->begin_transaction();
        try {
            $report = ItineraryRouteOptimiser::optimise($conn, $itineraryId);
            $ctx = self::context($conn, $itineraryId, false);
            if ($ctx !== null) {
                $days = self::visits($ctx);
                $empty = array_keys(array_filter($days, static fn(array $stops): bool => $stops === []));
                if ($empty) {
                    $plan = self::plan($ctx, $days, $empty, ['budget' => false, 'best_effort' => true]);
                    self::store($conn, $ctx, $days, $plan, [], ['why_empty' => 'no order keeps its stops within the rules']);
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            self::$cache = [];
            throw $e;
        }
        self::$cache = [];
        return self::done('The route was optimised.', ['days' => array_keys($report['days']), 'report' => $report]);
    }

    /**
     * Renumber, re-time from the chosen hotels where the rules allow, re-measure and re-cost an
     * itinerary. Call it inside the caller's transaction (e.g. after
     * ItineraryHotelService::saveNights()); a day that cannot be timed again keeps its times.
     */
    public static function refresh(mysqli $conn, int $itineraryId): void
    {
        self::$cache = [];
        self::resequence($conn, $itineraryId);
        // Called after rows changed without their legs (saveNights()): the stored legs are not trusted.
        $ctx = self::context($conn, $itineraryId, false, null, false);
        if ($ctx !== null) {
            $days = self::visits($ctx);
            $plan = self::plan($ctx, $days, array_keys($ctx['days']), ['budget' => false, 'best_effort' => true]);
            self::store($conn, $ctx, $days, $plan, [], []);
        } else {
            self::routes($conn, $itineraryId);
            self::total($conn, $itineraryId);
        }
        self::$cache = [];
    }

    /** Number the items of each day 1, 2, 3... in their current order. */
    public static function resequence(mysqli $conn, int $itineraryId): void
    {
        $stmt = $conn->prepare("SELECT item_id, day_no FROM itinerary_items WHERE itinerary_id = ? ORDER BY day_no, sequence_no, item_id");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $update = $conn->prepare("UPDATE itinerary_items SET sequence_no = ? WHERE item_id = ?");
        $position = [];
        foreach ($rows as $row) {
            $dayNo = (int)$row['day_no'];
            $sequenceNo = $position[$dayNo] = ($position[$dayNo] ?? 0) + 1;
            $itemId = (int)$row['item_id'];
            $update->bind_param('ii', $sequenceNo, $itemId);
            $update->execute();
        }
        $update->close();
    }

    /**
     * Distance and travel time of every leg: from the trip's starting point to the first stop,
     * then from each stop to the next. A new day starts where the last one ended (at the hotel,
     * if one was chosen). Without $route the figures are measured on the road for the trip's
     * transport, with one request for the whole trip; without an answer from the routing
     * server they are estimates.
     *
     * @param RouteService|null $route the service the days were timed with: its figures are
     *                                 stored as they are and nothing is asked, so the stored
     *                                 legs are the ones every rule was checked on
     */
    public static function routes(mysqli $conn, int $itineraryId, ?RouteService $route = null): void
    {
        $measure = $route === null;
        $metaStmt = $conn->prepare("
            SELECT tp.transport_type, i.origin_lat, i.origin_lng
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ?
            LIMIT 1
        ");
        $metaStmt->bind_param('i', $itineraryId);
        $metaStmt->execute();
        $meta = $metaStmt->get_result()->fetch_assoc();
        $metaStmt->close();
        if (!$meta) return;

        $route ??= new RouteService((string)($meta['transport_type'] ?? 'car'));
        $itemsStmt = $conn->prepare("
            SELECT ii.item_id, ii.day_no,
                   COALESCE(ii.item_latitude, cp.latitude) AS latitude,
                   COALESCE(ii.item_longitude, cp.longitude) AS longitude
            FROM itinerary_items ii
            LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
            WHERE ii.itinerary_id = ?
            ORDER BY ii.day_no, ii.sequence_no, ii.item_id
        ");
        $itemsStmt->bind_param('i', $itineraryId);
        $itemsStmt->execute();
        $days = [];   // day => [[item id, [lat, lng] or null], ...]
        foreach ($itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $item) {
            $here = null;
            if ($item['latitude'] !== null && $item['longitude'] !== null && RouteService::validCoord((float)$item['latitude'], (float)$item['longitude'])) {
                $here = [(float)$item['latitude'], (float)$item['longitude']];
            }
            $days[(int)$item['day_no']][] = [(int)$item['item_id'], $here];
        }
        $itemsStmt->close();

        $previous = null;
        if ($meta['origin_lat'] !== null && $meta['origin_lng'] !== null && RouteService::validCoord((float)$meta['origin_lat'], (float)$meta['origin_lng'])) {
            $previous = [(float)$meta['origin_lat'], (float)$meta['origin_lng']];
        }

        $chain = $previous !== null ? [$previous] : [];
        foreach ($days as $items) {
            foreach ($items as [, $here]) {
                if ($here !== null) $chain[] = $here;
            }
        }
        if ($measure) $route->preloadChain($chain);

        $update = $conn->prepare("UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ?");
        foreach ($days as $items) {
            foreach ($items as [$itemId, $here]) {
                $distanceKm = null;
                $travelMin = null;
                if ($previous !== null && $here !== null) {
                    $segment = $route->getSegment($previous[0], $previous[1], $here[0], $here[1]);
                    $distanceKm = (float)$segment['distance_km'];
                    $travelMin = (int)$segment['travel_time_min'];
                }
                $update->bind_param('dii', $distanceKm, $travelMin, $itemId);
                $update->execute();
                if ($here !== null) $previous = $here;
            }
        }
        $update->close();
    }

    /** Total estimated cost of the trip for the whole party. */
    public static function total(mysqli $conn, int $itineraryId): void
    {
        $stmt = $conn->prepare("
            SELECT i.total_days, tp.transport_type, tp.budget, tp.budget_tier, tp.traveller_type, tp.party_size
            FROM itineraries i
            LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id
            WHERE i.itinerary_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta) return;

        $itemsStmt = $conn->prepare("SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ?");
        $itemsStmt->bind_param('i', $itineraryId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();
        $totalDistanceKm = array_sum(array_map(static fn($item) => (float)($item['distance_km'] ?? 0), $items));

        $transportType = (string)($meta['transport_type'] ?? 'car');
        $totalDays = (int)($meta['total_days'] ?? 1);
        $budget = (float)($meta['budget'] ?? 0);
        $travellerType = (string)($meta['traveller_type'] ?? 'solo');
        $partySize = CostEstimationService::resolvePartySize($travellerType, isset($meta['party_size']) ? (int)$meta['party_size'] : null);
        $tierDefaults = CostEstimationService::budgetTierDefaults(strtolower((string)($meta['budget_tier'] ?? 'normal')), $budget, $totalDays, $partySize);

        $costService = new CostEstimationService($transportType, $totalDays, $budget, $travellerType, $partySize);
        $breakdown = $costService->calculate($items, $totalDistanceKm, $tierDefaults['hotel'], 3, $tierDefaults['meal']);
        $total = (float)($breakdown['total_cost'] ?? 0);

        $update = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
        $update->bind_param('di', $total, $itineraryId);
        $update->execute();
        $update->close();
    }

    // =====================================================================================
    // The changes, worked out on a loaded itinerary. Each returns either ['refused' => result]
    // or ['days' => day => stops, 'changed' => days, 'deleted' => item ids, 'opts' => plan
    // options, 'message' => sentence, 'extra' => result fields].
    // =====================================================================================

    private static function buildReplace(array $ctx, int $itemId, int $placeId): array
    {
        $found = self::findItem($ctx, $itemId);
        if ($found === null) return ['refused' => self::refused('NOT_FOUND', 'This stop is not part of the itinerary.')];
        [$kind, $dayNo, $index] = $found;
        if ($kind === 'meal') return self::buildMeal($ctx, $dayNo, $index, $placeId);
        if ($kind !== 'visit') return ['refused' => self::refused('NOT_EDITABLE', 'Only a visit or a meal can be replaced.')];

        $days = self::visits($ctx);
        $old = $days[$dayNo][$index];
        $oldTitle = (string)$old['row']['item_title'];
        if ((int)$old['place']['place_id'] === $placeId) return ['refused' => self::refused('NO_CHANGE', $oldTitle . ' is already this stop.')];
        $place = self::loadPlace($ctx, $placeId);
        $why = self::placeRefusal($ctx, $place, $placeId, $itemId);
        if ($why !== null) return ['refused' => $why];

        $scored = self::scoreAt($ctx, $dayNo, $days[$dayNo], $index, $place);
        $days[$dayNo][$index] = self::stopOf($place, $itemId, $scored['tier'], array_merge(['chosen by you'], $scored['reasons']), self::isMust($ctx, $placeId));
        return ['days' => $days, 'changed' => [$dayNo], 'focus' => $placeId,
                'message' => $place['name'] . ' now replaces ' . $oldTitle . ' on day ' . $dayNo . ', and the day was timed again.',
                'extra' => ['item_id' => $itemId]];
    }

    /** A meal row: another food place for that meal, or a break without a place ($placeId 0). */
    private static function buildMeal(array $ctx, int $dayNo, int $index, int $placeId): array
    {
        $row = $ctx['days'][$dayNo]['meals'][$index];
        $slot = (string)$row['meal_slot'];
        $label = ucfirst($slot);
        $current = (int)($row['place_id'] ?? 0);
        if ($placeId === $current) return ['refused' => self::refused('NO_CHANGE', 'This ' . $slot . ' is already planned that way.')];
        $place = null;
        if ($placeId > 0) {
            $place = self::loadPlace($ctx, $placeId);
            $why = self::placeRefusal($ctx, $place, $placeId, (int)$row['item_id']);
            if ($why !== null) return ['refused' => $why];
            if (strtolower((string)$place['category']) !== 'food') {
                return ['refused' => self::refused('NOT_EDITABLE', $place['name'] . ' is not a food place, so it cannot be your ' . $slot . '.')];
            }
            if (!isset($ctx['meal_ids'][$placeId])) {
                return ['refused' => self::refused('DIETARY', $place['name'] . ' cannot be your ' . $slot . ': it is not among the food places your trip may use.')];
            }
        }
        return ['days' => self::visits($ctx), 'changed' => [$dayNo], 'focus' => $placeId,
                'opts' => ['meal_choice' => [$dayNo => [$slot => ['place' => $place, 'strict' => true]]]],
                'message' => $label . ' on day ' . $dayNo . ($place !== null ? ' is now at ' . $place['name'] : ' is now a break without a set place') . ', and the day was timed again.',
                'extra' => ['item_id' => (int)$row['item_id']]];
    }

    private static function buildAdd(array $ctx, int $dayNo, int $placeId, ?int $position): array
    {
        if ($dayNo < 1 || $dayNo > $ctx['total_days']) return ['refused' => self::refused('NOT_FOUND', 'The trip has no day ' . $dayNo . '.')];
        $days = self::visits($ctx);
        $target = (int)$ctx['profile']['target'];
        if (count($days[$dayNo]) >= $target) {
            return ['refused' => self::refused('DAY_FULL', 'Day ' . $dayNo . ' already has its ' . $target . ' planned stops. Replace or remove one first.')];
        }
        $place = self::loadPlace($ctx, $placeId);
        $why = self::placeRefusal($ctx, $place, $placeId, null);
        if ($why !== null) return ['refused' => $why];
        $scoreIndex = $position !== null ? max(0, min(count($days[$dayNo]), $position - 1)) : count($days[$dayNo]);
        $withGap = $days[$dayNo];
        array_splice($withGap, $scoreIndex, 0, [null]);
        $scored = self::scoreAt($ctx, $dayNo, $withGap, $scoreIndex, $place);
        $stop = self::stopOf($place, null, $scored['tier'], array_merge(['chosen by you'], $scored['reasons']), self::isMust($ctx, $placeId));
        $layout = self::placeInDay($ctx, $days, $dayNo, $stop, $position, [$dayNo], [], $placeId);
        if (isset($layout['refused'])) return $layout;
        return ['days' => $layout['days'], 'changed' => [$dayNo], 'focus' => $placeId,
                'message' => $place['name'] . ' was added to day ' . $dayNo . ' as stop ' . $layout['position'] . ', and the day was timed again.',
                'extra' => ['position' => $layout['position']]];
    }

    private static function buildRegenerate(array $ctx, int $dayNo, array $options): array
    {
        if ($dayNo < 1 || $dayNo > $ctx['total_days']) return ['refused' => self::refused('NOT_FOUND', 'The trip has no day ' . $dayNo . '.')];
        if ($ctx['days'][$dayNo]['locked']) return ['refused' => self::refused('LOCKED', 'Day ' . $dayNo . ' holds a row the planner cannot re-time, so it cannot be planned again.')];

        // More of a category: it becomes an interest that is loved. Less: liked only a little.
        $pref = $ctx['pref'];
        $interests = (array)$pref['interests'];
        $weights = (array)$pref['interest_weights'];
        foreach ((array)($options['more'] ?? []) as $category) {
            $category = strtolower(trim((string)$category));
            if (!in_array($category, PlannerRules::VALID_INTERESTS, true)) continue;
            if (!in_array($category, $interests, true)) $interests[] = $category;
            $weights[$category] = 3;
        }
        foreach ((array)($options['less'] ?? []) as $category) {
            $category = strtolower(trim((string)$category));
            if (in_array($category, $interests, true)) $weights[$category] = 1;
        }
        $profile = PlannerRules::profile(['interests' => $interests, 'interest_weights' => $weights] + $ctx['profile_input']);
        $profile['meals'] = $ctx['profile']['meals'];
        $profile = self::dayProfile($ctx, $dayNo, $profile);

        $days = self::visits($ctx);
        $musts = array_values(array_filter($days[$dayNo], static fn(array $s): bool => $s['must']));
        $mustIds = array_fill_keys(array_map(static fn(array $s): int => (int)$s['place']['place_id'], $musts), true);
        $start = self::starts($ctx, $days)[$dayNo];
        $date = $ctx['dates'][$dayNo];
        $rain = $options['rain'] ?? ($options['forecast'][$date] ?? null);
        $hotelPoint = $ctx['days'][$dayNo]['hotel']['point'] ?? null;
        $budget = self::budgetOutside($ctx, $dayNo, $days, []);
        $dayCtx = [
            'day_no' => $dayNo,
            'date' => $date,
            'rain' => is_numeric($rain) ? max(0, min(100, (int)$rain)) : null,
            'start' => $start['point'],
            'first_leg_exempt' => $start['exempt'],
            'used' => self::usedOutside($ctx, $dayNo, $days, []),
            'trip_items' => $budget['items'],
            'trip_km' => $budget['km'],
            'reserve_km' => 0.0,
            'previous_theme' => self::previousTheme($days, $dayNo),
            'musts' => array_map(static fn(array $s): array => $s['place'], $musts),
            'finish' => $dayNo === $ctx['total_days'] && $hotelPoint === null ? $ctx['finish'] : null,
        ];
        $pool = array_values(array_filter($ctx['pool'], static fn(array $p): bool => !isset($mustIds[(int)$p['place_id']])));
        $builder = new ItineraryDayBuilder($profile, $ctx['route'], $ctx['meal_pool']);
        $built = $builder->build($pool, $dayCtx);
        foreach ($built['unplaced'] as $id => $reason) {
            $name = (string)($ctx['places'][$id]['name'] ?? ('Place #' . $id));
            return ['refused' => self::refused((string)$reason, 'Day ' . $dayNo . ' cannot be planned again without losing the must-see place ' . $name . ' (' . PlannerRules::reasonText((string)$reason) . ').')];
        }
        $ordered = ItineraryRouteOptimiser::orderDay($ctx['route'], $profile, $start['point'], $built['stops'], $dayCtx + [
            'end' => $hotelPoint, 'meal_pool' => $ctx['meal_pool'], 'budget' => $budget + ['reserve_km' => 0.0],
        ]);

        // Rows are kept for places the day already had; the others are new.
        $rowsByPlace = [];
        foreach ($days[$dayNo] as $s) $rowsByPlace[(int)$s['place']['place_id']] = (int)$s['item_id'];
        $newStops = [];
        $kept = [];
        foreach ($ordered['stops'] as $s) {
            $id = (int)$s['place']['place_id'];
            $itemId = $rowsByPlace[$id] ?? null;
            if ($itemId !== null) $kept[$itemId] = true;
            $newStops[] = self::stopOf($s['place'], $itemId, (int)$s['tier'], (array)$s['reasons'], (bool)($s['must'] ?? false));
        }
        $deleted = array_values(array_filter(array_values($rowsByPlace), static fn(int $id): bool => !isset($kept[$id])));
        $days[$dayNo] = $newStops;
        $rejections = $built['rejections'];
        arsort($rejections);
        $main = array_key_first($rejections);
        $why = $main !== null ? 'most places were ' . PlannerRules::reasonText((string)$main) : 'no place is left in the chosen area';
        return ['days' => $days, 'changed' => [$dayNo], 'deleted' => $deleted,
                'opts' => ['why_empty' => $why, 'notes_profile' => $profile],
                'message' => 'Day ' . $dayNo . ' was planned again with ' . count($newStops) . ' stop(s).',
                'extra' => ['stops' => count($newStops)]];
    }

    /**
     * Put a stop into a day: at the given position, or at the feasible position with the least
     * travelling (ties: the later position). Every position is judged on the whole trip: first
     * on the figures at hand, then the best few (all, when none fits) on the road, measured
     * together; a position that fails on the road gives way to the next.
     *
     * @return array{days:array,position:int}|array{refused:array}
     */
    private static function placeInDay(array $ctx, array $days, int $dayNo, array $stop, ?int $position, array $changed, array $opts, int $focus = 0): array
    {
        $n = count($days[$dayNo]);
        $indexes = $position !== null ? [max(0, min($n, $position - 1))] : range($n, 0);
        $opts += ['focus' => $focus];
        $trials = [];
        foreach ($indexes as $i) {
            $trial = $days;
            array_splice($trial[$dayNo], $i, 0, [$stop]);
            $trials[$i] = [$trial, self::timeDays($ctx, $trial, $changed, $opts)];
        }
        $ranking = static function (array $trials) use ($dayNo): array {
            $ranked = [];
            foreach ($trials as $i => [, $timed]) {
                if ($timed['result']['ok']) $ranked[] = [(int)$timed['result']['plans'][$dayNo]['minutes'], count($ranked), $i];
            }
            sort($ranked);
            return array_column($ranked, 2);
        };
        $best = $ranking($trials);
        $short = $best ? array_slice($best, 0, self::POSITIONS_MEASURED) : array_keys($trials);
        $legs = [];
        foreach ($short as $i) array_push($legs, ...self::unknownLegs($ctx, $trials[$i][0], $trials[$i][1]));
        if (self::measure($ctx, $legs) > 0) {
            foreach ($trials as $i => [$trial]) $trials[$i][1] = self::timeDays($ctx, $trial, $changed, $opts);
            $best = $ranking($trials);
        }
        $refusal = null;
        foreach ($best as $i) {
            $plan = self::plan($ctx, $trials[$i][0], $changed, $opts);
            if ($plan['ok']) return ['days' => $trials[$i][0], 'position' => $i + 1];
            $refusal ??= $plan;
        }
        foreach ($trials as [, $timed]) $refusal ??= $timed['result']['ok'] ? null : $timed['result'];
        return ['refused' => $refusal ?? self::refused('NO_CHANGE', 'No position of day ' . $dayNo . ' fits this stop.')];
    }

    // =====================================================================================
    // Running a change
    // =====================================================================================

    /** Work out a change, time the days it touches and store it, in one transaction. */
    private static function change(mysqli $conn, int $itineraryId, callable $build): array
    {
        self::$cache = [];
        $conn->begin_transaction();
        try {
            $ctx = self::context($conn, $itineraryId, false);
            if ($ctx === null) {
                $conn->rollback();
                return self::refused('NOT_FOUND', 'This itinerary was not found.');
            }
            $out = $build($ctx);
            if (isset($out['refused'])) {
                $conn->rollback();
                return $out['refused'];
            }
            $opts = (array)($out['opts'] ?? []) + ['focus' => (int)($out['focus'] ?? 0)];
            $plan = self::plan($ctx, $out['days'], $out['changed'], $opts);
            if (!$plan['ok']) {
                $conn->rollback();
                return $plan;
            }
            $newIds = self::store($conn, $ctx, $out['days'], $plan, (array)($out['deleted'] ?? []), $opts);
            if (isset($opts['day_starts'])) self::saveDayStarts($conn, $itineraryId, (int)$ctx['total_days'], (array)$opts['day_starts']);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            self::$cache = [];
            throw $e;
        }
        self::$cache = [];
        $extra = (array)($out['extra'] ?? []);
        if (!isset($extra['item_id']) && $newIds) $extra['item_id'] = $newIds[0];
        return self::done((string)$out['message'], ['days' => $plan['retimed']] + $extra);
    }

    /** Work out a change and time it without storing anything. */
    private static function check(mysqli $conn, int $itineraryId, callable $build): array
    {
        $ctx = self::context($conn, $itineraryId);
        if ($ctx === null) return self::refused('NOT_FOUND', 'This itinerary was not found.');
        $ctx['budget_from'] = $ctx['route']->requestsMade();   // a loaded itinerary may be used again: this call has its own budget
        $out = $build($ctx);
        if (isset($out['refused'])) return $out['refused'];
        $plan = self::plan($ctx, $out['days'], $out['changed'], (array)($out['opts'] ?? []) + ['focus' => (int)($out['focus'] ?? 0)]);
        if (!$plan['ok']) return $plan;
        return self::done((string)$out['message'], ['days' => $plan['retimed']] + (array)($out['extra'] ?? []));
    }

    /**
     * Time the days a change touches (see the head of this file), on the road.
     *
     * The days are timed on the figures at hand first: the legs stored with the trip, road
     * figures measured earlier, estimates for the rest. The legs the timed days need that are
     * not known yet (their stops in order, the meal places on the way, the hotel or the end
     * point) are then measured on the road in one request, and the days are timed again; a
     * second request follows only for legs the new timing needs that were not asked for yet
     * (a meal moved to another place). Without an answer the estimates stay. The figures a
     * plan is timed with are the ones store() keeps, so a stored plan keeps every rule on its
     * stored figures.
     *
     * @param array $days    day => stops, for every day of the trip
     * @param int[] $changed the days whose stops changed
     * @param array $opts    profile (timing profile instead of the trip's), day_starts (day =>
     *                       start minute, instead of the stored ones), budget (false: no
     *                       budget rule, for a hotel choice), meal_choice (day => slot => choice),
     *                       old_starts (the starting points before the change), best_effort (a
     *                       day that cannot be timed is left as it is instead of refusing), focus
     *                       (the place the change is about, for the sentence), measure (false:
     *                       no request, the figures at hand only)
     * @return array ok, plans (day => ItineraryRouteOptimiser plan), retimed (days); or a refused
     *         result with day
     */
    private static function plan(array $ctx, array $days, array $changed, array $opts = []): array
    {
        $timed = self::timeDays($ctx, $days, $changed, $opts);
        if ($opts['measure'] ?? true) {
            while (self::requestsLeft($ctx) > 0) {
                if (self::measure($ctx, self::unknownLegs($ctx, $days, $timed)) === 0) break;
                $timed = self::timeDays($ctx, $days, $changed, $opts);
            }
        }
        return $timed['result'];
    }

    /**
     * Time the days a change touches on the figures the route service holds; no request.
     *
     * @return array{result:array,plans:array,timed:int[],starts:array} result as plan() returns
     *         it; plans: every day timed so far, the one that failed included; timed: every day
     *         the change touches; starts: where each day starts after the change
     */
    private static function timeDays(array $ctx, array $days, array $changed, array $opts): array
    {
        $profile = $opts['profile'] ?? $ctx['profile'];
        $dayStarts = (array)($opts['day_starts'] ?? $ctx['day_starts']);
        $oldStarts = $opts['old_starts'] ?? self::starts($ctx, self::visits($ctx));
        $newStarts = self::starts($ctx, $days);
        $toTime = [];
        foreach (array_keys($ctx['days']) as $d) {
            if (in_array($d, $changed, true) || !self::samePoint($oldStarts[$d]['point'], $newStarts[$d]['point'])) $toTime[] = $d;
        }
        $plans = [];
        $tried = [];
        $frame = ['timed' => $toTime, 'starts' => $newStarts];
        foreach ($toTime as $d) {
            if (!$days[$d]) {
                $plans[$d] = $tried[$d] = ['stops' => [], 'dropped' => [], 'minutes' => 0, 'km' => 0.0];
                continue;
            }
            if ($ctx['days'][$d]['locked'] || $newStarts[$d]['point'] === null) {
                if (!empty($opts['best_effort'])) continue;
                return ['result' => self::refused('LOCKED', 'Day ' . $d . ' holds a row the planner cannot re-time, so this change cannot be made.', ['day' => $d]), 'plans' => $tried] + $frame;
            }
            $dayProfile = isset($dayStarts[$d]) ? ['start' => (int)$dayStarts[$d]] + $profile : $profile;
            $plan = $tried[$d] = self::timeDay($ctx, $dayProfile, $d, $newStarts[$d], $days[$d], $days, $plans, $opts);
            if ($plan['dropped']) {
                if (!empty($opts['best_effort'])) continue;
                $stop = $plan['dropped'][0];
                $reason = (string)$stop['reason'];
                $name = (string)$stop['place']['name'];
                $message = (int)$stop['place']['place_id'] === (int)($opts['focus'] ?? 0)
                    ? $name . ' does not fit day ' . $d . ': ' . PlannerRules::reasonText($reason) . '.'
                    : 'This change would break a rule on day ' . $d . ': ' . $name . ' (' . PlannerRules::reasonText($reason) . ').';
                return ['result' => self::refused($reason, $message, ['day' => $d]), 'plans' => $tried] + $frame;
            }
            foreach ((array)($opts['meal_choice'][$d] ?? []) as $slot => $choice) {
                if (empty($choice['strict'])) continue;
                $wanted = !empty($choice['place']) ? (int)$choice['place']['place_id'] : 0;
                $had = false;
                foreach ($plan['stops'] as $s) {
                    foreach ((array)$s['meals'] as $meal) {
                        if ($meal['slot'] === $slot && (int)($meal['place']['place_id'] ?? 0) === $wanted) $had = true;
                    }
                }
                if (!$had) {
                    return ['result' => self::refused('MEAL', 'That ' . $slot . ' does not fit day ' . $d . ': it is not open for the whole meal near your stops, or the day no longer needs a ' . $slot . ' there.', ['day' => $d]),
                            'plans' => $tried] + $frame;
                }
            }
            $plans[$d] = $plan;
        }
        return ['result' => ['ok' => true, 'plans' => $plans, 'retimed' => array_keys($plans)], 'plans' => $tried] + $frame;
    }

    /**
     * The legs the timed days need that the route service neither knows nor was asked for:
     * each day's stops in their order to the hotel or the end point first, then the legs via
     * the meal places of the timing and, on the last day, from each stop to the end point.
     *
     * @return array<int,array{0:array,1:array}>
     */
    private static function unknownLegs(array $ctx, array $days, array $timed): array
    {
        $route = $ctx['route'];
        $legs = [];
        $add = static function (?array $from, ?array $to) use (&$legs, $route): void {
            if ($from === null || $to === null || $route->covers($from, $to)) return;
            $legs[$from[0] . ',' . $from[1] . '|' . $to[0] . ',' . $to[1]] = [$from, $to];
        };
        $ends = [];
        foreach ($timed['timed'] as $d) {
            $start = $timed['starts'][$d]['point'];
            if ($start === null || !$days[$d]) continue;
            $hotelPoint = $ctx['days'][$d]['hotel']['point'] ?? null;
            $ends[$d] = $hotelPoint ?? ($d === $ctx['total_days'] ? $ctx['finish'] : null);
            $previous = $start;
            foreach ($days[$d] as $s) { $add($previous, $s['point']); $previous = $s['point']; }
            $add($previous, $ends[$d]);
        }
        foreach ($timed['timed'] as $d) {
            $start = $timed['starts'][$d]['point'];
            $plan = $timed['plans'][$d] ?? null;
            if ($start === null || !$plan || !$plan['stops']) continue;
            $previous = $start;
            foreach ($plan['stops'] as $s) {
                foreach ((array)($s['meals'] ?? []) as $meal) {
                    if ($meal['point'] === null) continue;
                    $add($previous, $meal['point']);
                    $previous = $meal['point'];
                }
                $add($previous, $s['point']);
                $previous = $s['point'];
            }
            $add($previous, $ends[$d] ?? null);
            if ($d === $ctx['total_days'] && ($ctx['days'][$d]['hotel']['point'] ?? null) === null && $ctx['finish'] !== null) {
                foreach ($days[$d] as $s) $add($s['point'], $ctx['finish']);
            }
        }
        return array_values($legs);
    }

    /** Measure legs on the road within what is left of the operation's requests; returns how many became known. */
    private static function measure(array $ctx, array $legs): int
    {
        $left = self::requestsLeft($ctx);
        return $legs && $left > 0 ? $ctx['route']->measureLegs($legs, $left) : 0;
    }

    /** The routing requests the running operation may still make. */
    private static function requestsLeft(array $ctx): int
    {
        return self::MAX_REQUESTS - ($ctx['route']->requestsMade() - (int)($ctx['budget_from'] ?? 0));
    }

    /** Time one day's stops in their order with the trip's rules (the generator's timing). */
    private static function timeDay(array $ctx, array $profile, int $d, array $start, array $stops, array $days, array $plans, array $opts): array
    {
        $hotelPoint = $ctx['days'][$d]['hotel']['point'] ?? null;
        $choice = self::storedMealChoice($ctx, $d);
        foreach ((array)($opts['meal_choice'][$d] ?? []) as $slot => $c) $choice[$slot] = $c;
        return ItineraryRouteOptimiser::timeInOrder($ctx['route'], $profile, $start['point'], $stops, [
            'date' => $ctx['dates'][$d],
            'first_leg_exempt' => $start['exempt'],
            'budget' => ($opts['budget'] ?? true) ? self::budgetOutside($ctx, $d, $days, $plans) + ['reserve_km' => 0.0] : null,
            'end' => $hotelPoint,
            'finish' => $d === $ctx['total_days'] && $hotelPoint === null ? $ctx['finish'] : null,
            'meal_pool' => $ctx['meal_pool'],
            'used' => self::usedOutside($ctx, $d, $days, $plans),
            'meal_choice' => $choice,
        ]);
    }

    /**
     * Store the timed days: visits in their new order and times, meals placed again, note rows
     * and the hotel row after them; then legs and total.
     *
     * @return int[] the ids of new visit rows
     */
    private static function store(mysqli $conn, array $ctx, array $days, array $plan, array $deleted, array $opts): array
    {
        $id = (int)$ctx['itinerary_id'];
        $profile = $ctx['profile'];
        $notesProfile = $opts['notes_profile'] ?? $profile;
        $newIds = [];
        if ($deleted) {
            $remove = $conn->prepare("DELETE FROM itinerary_items WHERE item_id = ? AND itinerary_id = ?");
            foreach ($deleted as $itemId) {
                $itemId = (int)$itemId;
                $remove->bind_param('ii', $itemId, $id);
                $remove->execute();
            }
            $remove->close();
        }
        $moveRow = $conn->prepare("UPDATE itinerary_items SET day_no = ?, sequence_no = ?, start_time = ?, end_time = ?, notes = ? WHERE item_id = ? AND itinerary_id = ?");
        $setPlace = $conn->prepare("UPDATE itinerary_items SET place_id = ?, item_type = ?, item_title = ?, item_latitude = ?, item_longitude = ?, estimated_cost = ? WHERE item_id = ? AND itinerary_id = ?");
        $insertVisit = $conn->prepare("
            INSERT INTO itinerary_items
                (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, item_latitude, item_longitude,
                 start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $insertMeal = !empty($profile['meals']) ? $conn->prepare("
            INSERT INTO itinerary_items
                (itinerary_id, day_no, sequence_no, item_type, meal_slot, place_id, item_title, item_latitude, item_longitude,
                 start_time, end_time, estimated_cost, distance_km, travel_time_min, notes)
            VALUES (?,?,?,'food',?,?,?,?,?,?,?,0.00,?,?,?)
        ") : null;
        $setSequence = $conn->prepare("UPDATE itinerary_items SET sequence_no = ?, start_time = ?, end_time = ? WHERE item_id = ? AND itinerary_id = ?");
        $windowEnd = PlannerRules::sqlTime((int)$profile['end']);

        // A meal keeps its row (and so its id) while its day still has that meal.
        $updateMeal = $insertMeal !== null ? $conn->prepare("
            UPDATE itinerary_items
            SET day_no = ?, sequence_no = ?, place_id = ?, item_title = ?, item_latitude = ?, item_longitude = ?,
                start_time = ?, end_time = ?, estimated_cost = 0.00, distance_km = ?, travel_time_min = ?, notes = ?
            WHERE item_id = ? AND itinerary_id = ?
        ") : null;
        foreach ($plan['plans'] as $d => $dayPlan) {
            $oldMeals = [];
            foreach ($ctx['days'][$d]['meals'] as $m) $oldMeals[(string)$m['meal_slot']][] = (int)$m['item_id'];
            $sequence = 0;
            $lastEnd = null;
            foreach ($dayPlan['stops'] as $stop) {
                foreach ($insertMeal !== null ? (array)$stop['meals'] : [] as $meal) {
                    $sequence++;
                    $slot = (string)$meal['slot'];
                    $mealPlaceId = $meal['place'] !== null ? (int)$meal['place']['place_id'] : null;
                    $mealTitle = mb_substr(ItineraryDayBuilder::mealTitle($meal), 0, 150);
                    $mealLat = $meal['point'][0] ?? null;
                    $mealLng = $meal['point'][1] ?? null;
                    $mealStart = PlannerRules::sqlTime((int)$meal['start']);
                    $mealEnd = PlannerRules::sqlTime((int)$meal['end']);
                    $mealKm = $meal['place'] !== null ? round((float)$meal['km'], 2) : null;
                    $mealMin = $meal['place'] !== null ? (int)$meal['min'] : null;
                    $mealNotes = ItineraryDayBuilder::mealNotes($meal, $profile);
                    $mealRow = isset($oldMeals[$slot]) ? array_shift($oldMeals[$slot]) : null;
                    if ($mealRow !== null) {
                        $updateMeal->bind_param('iiisddssdisii', $d, $sequence, $mealPlaceId, $mealTitle, $mealLat, $mealLng,
                            $mealStart, $mealEnd, $mealKm, $mealMin, $mealNotes, $mealRow, $id);
                        $updateMeal->execute();
                    } else {
                        $insertMeal->bind_param('iiisisddssdis', $id, $d, $sequence, $slot, $mealPlaceId, $mealTitle, $mealLat, $mealLng,
                            $mealStart, $mealEnd, $mealKm, $mealMin, $mealNotes);
                        $insertMeal->execute();
                    }
                }
                $sequence++;
                $place = $stop['place'];
                $startTime = PlannerRules::sqlTime((int)$stop['start']);
                $endTime = PlannerRules::sqlTime((int)$stop['end']);
                $lastEnd = (int)$stop['end'];
                $notes = $stop['reasons'] !== null
                    ? ItineraryDayBuilder::notes(['place' => $place, 'tier' => $stop['tier'], 'reasons' => $stop['reasons'], 'food_meal' => $stop['food_meal']], $notesProfile)
                    : self::retagFoodMeal((string)($stop['row']['notes'] ?? ''), $stop['food_meal']);
                $type = PlannerRules::itemType($place);
                $placeId = (int)$place['place_id'];
                $title = mb_substr((string)$place['name'], 0, 150);
                $lat = (float)$place['latitude'];
                $lng = (float)$place['longitude'];
                $cost = PlannerRules::placeCost($place);
                if ($stop['item_id'] !== null) {
                    $itemId = (int)$stop['item_id'];
                    if ($stop['set_place']) {
                        $setPlace->bind_param('issdddii', $placeId, $type, $title, $lat, $lng, $cost, $itemId, $id);
                        $setPlace->execute();
                    }
                    $moveRow->bind_param('iisssii', $d, $sequence, $startTime, $endTime, $notes, $itemId, $id);
                    $moveRow->execute();
                } else {
                    $km = round((float)$stop['km'], 2);
                    $minutes = (int)$stop['min'];
                    $insertVisit->bind_param('iiisisddssddis', $id, $d, $sequence, $type, $placeId, $title, $lat, $lng,
                        $startTime, $endTime, $cost, $km, $minutes, $notes);
                    $insertVisit->execute();
                    $newIds[] = (int)$insertVisit->insert_id;
                }
            }

            // Meals the day no longer has.
            foreach (array_merge([], ...array_values($oldMeals)) as $mealRow) {
                $drop = $conn->prepare("DELETE FROM itinerary_items WHERE item_id = ? AND itinerary_id = ?");
                $drop->bind_param('ii', $mealRow, $id);
                $drop->execute();
                $drop->close();
            }

            // Note rows after the visits: the "Free day" note only on a day without stops.
            $hasFreeNote = false;
            foreach ($ctx['days'][$d]['notes'] as $note) {
                $noteId = (int)$note['item_id'];
                if (self::isFreeDayNote($note)) {
                    if ($dayPlan['stops'] || $hasFreeNote) {
                        $drop = $conn->prepare("DELETE FROM itinerary_items WHERE item_id = ? AND itinerary_id = ?");
                        $drop->bind_param('ii', $noteId, $id);
                        $drop->execute();
                        $drop->close();
                        continue;
                    }
                    $hasFreeNote = true;
                }
                $sequence++;
                $noteStart = $note['start_time'];
                $noteEnd = $note['end_time'];
                $setSequence->bind_param('issii', $sequence, $noteStart, $noteEnd, $noteId, $id);
                $setSequence->execute();
            }
            if (!$dayPlan['stops'] && !$hasFreeNote) {
                [$noteTitle, $noteText] = ItineraryGenerator::freeDayNote((string)($opts['why_empty'] ?? self::EMPTIED));
                $sequence++;
                $type = 'note';
                $noteText = mb_strlen($noteText) <= 255 ? $noteText : mb_substr($noteText, 0, 252) . '...';
                $insertNote = $conn->prepare("
                    INSERT INTO itinerary_items (itinerary_id, day_no, sequence_no, item_type, place_id, item_title, start_time, end_time, estimated_cost, notes)
                    VALUES (?,?,?,?,NULL,?,?,?,0.00,?)
                ");
                $dayStart = PlannerRules::sqlTime((int)(($opts['day_starts'] ?? $ctx['day_starts'])[$d] ?? $profile['start']));
                $insertNote->bind_param('iiisssss', $id, $d, $sequence, $type, $noteTitle, $dayStart, $windowEnd, $noteText);
                $insertNote->execute();
                $insertNote->close();
            }
            $hotel = $ctx['days'][$d]['hotel'];
            if ($hotel !== null) {
                $sequence++;
                $checkIn = ItineraryHotelService::checkInMinute($lastEnd);
                $hotelStart = PlannerRules::sqlTime($checkIn);
                $hotelEnd = PlannerRules::sqlTime($checkIn + 30);
                $hotelId = (int)$hotel['item_id'];
                $setSequence->bind_param('issii', $sequence, $hotelStart, $hotelEnd, $hotelId, $id);
                $setSequence->execute();
            }
        }
        $moveRow->close();
        $setPlace->close();
        $insertVisit->close();
        if ($insertMeal !== null) $insertMeal->close();
        if ($updateMeal !== null) $updateMeal->close();
        $setSequence->close();

        self::resequence($conn, $id);
        self::routes($conn, $id, $ctx['route']);
        self::total($conn, $id);
        return $newIds;
    }

    // =====================================================================================
    // The loaded itinerary
    // =====================================================================================

    /**
     * Everything a change is judged with: the itinerary, its preference and planning profile
     * (as the generator builds it), the candidate and meal places, and its rows by day.
     */
    /**
     * @param RouteService|null $route the route service of the running operation, when it has one
     * @param bool $seed take the stored legs as figures at hand; false when rows were changed
     *                   without their legs (ItineraryHotelService::saveNights()), so a stored
     *                   leg may start at a point that no longer comes before it
     */
    private static function context(mysqli $conn, int $itineraryId, bool $cached = true, ?RouteService $route = null, bool $seed = true): ?array
    {
        if ($cached && isset(self::$cache[$itineraryId])) return self::$cache[$itineraryId];
        $stmt = $conn->prepare("SELECT * FROM itineraries WHERE itinerary_id = ? LIMIT 1");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta) return null;

        $travellerId = (int)$meta['traveller_id'];
        $pref = PlannerRules::loadPreference($conn, (int)$meta['preference_id']) ?? PlannerRules::normalizePreference([]);
        $origin = self::point($meta['origin_lat'] ?? null, $meta['origin_lng'] ?? null);
        $ratings = PlannerCandidates::travellerRatings($conn, $travellerId, PlannerRules::LEARN_DROP_AT);
        $input = $pref + ['learned' => PlannerRules::learnedPoints($ratings['categories'])];
        if ($origin !== null) $input += ['origin_lat' => $origin[0], 'origin_lng' => $origin[1]];
        $profile = PlannerRules::profile($input);
        $profile['meals'] = PlannerRules::hasColumn($conn, 'itinerary_items', 'meal_slot');
        $mealPool = $profile['meals'] ? PlannerCandidates::mealPlaces($conn, $pref, $profile, $travellerId) : [];

        $mealSelect = $profile['meals'] ? 'meal_slot' : 'NULL AS meal_slot';
        $stmt = $conn->prepare("SELECT item_id, day_no, sequence_no, item_type, {$mealSelect}, place_id, hotel_id, item_title, item_latitude, item_longitude,
                                       start_time, end_time, estimated_cost, distance_km, travel_time_min, notes
                                FROM itinerary_items WHERE itinerary_id = ? ORDER BY day_no, sequence_no, item_id");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $placeIds = array_values(array_unique(array_filter(array_map(static fn(array $r): int => (int)$r['place_id'], $rows))));
        $places = [];
        foreach (PlannerCandidates::byIds($conn, $placeIds) as $p) $places[(int)$p['place_id']] = $p;

        $totalDays = max(1, (int)$meta['total_days']);
        $musts = array_fill_keys((array)$pref['must_places'], true);
        $days = [];
        $dates = [];
        for ($d = 1; $d <= $totalDays; $d++) {
            $days[$d] = ['visits' => [], 'meals' => [], 'notes' => [], 'hotel' => null, 'locked' => false, 'km' => 0.0];
            $dates[$d] = (new DateTimeImmutable((string)$meta['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
        }
        foreach ($rows as $row) {
            $d = (int)$row['day_no'];
            if (!isset($days[$d])) continue;   // a row outside the trip's days is left alone
            $days[$d]['km'] += (float)($row['distance_km'] ?? 0);
            $type = strtolower((string)$row['item_type']);
            if ($row['meal_slot'] !== null) { $days[$d]['meals'][] = $row; continue; }
            if ($type === 'note') { $days[$d]['notes'][] = $row; continue; }
            if ($type === 'hotel') {
                $days[$d]['hotel'] = $row + ['point' => self::point($row['item_latitude'], $row['item_longitude'])];
                continue;
            }
            $placeId = (int)$row['place_id'];
            if ($type === 'transport' || !isset($places[$placeId])) {
                // A row the planner cannot time (a transport row, or a place no longer active): the day stays as it is.
                $days[$d]['locked'] = true;
                $days[$d]['others'][] = $row;
                $fallback = ['place_id' => $placeId, 'name' => (string)$row['item_title'], 'category' => $type, 'state' => '', 'district' => '',
                             'latitude' => (float)$row['item_latitude'], 'longitude' => (float)$row['item_longitude'], 'entrance_fee' => $row['estimated_cost']];
                if ($type !== 'transport') {
                    $days[$d]['visits'][] = self::stopOf($fallback, (int)$row['item_id'], 1, null, false, $row);
                }
                continue;
            }
            $days[$d]['visits'][] = self::stopOf($places[$placeId], (int)$row['item_id'], 1, null, isset($musts[$placeId]), $row);
        }

        $ctx = [
            'conn' => $conn,
            'itinerary_id' => $itineraryId,
            'meta' => $meta,
            'traveller_id' => $travellerId,
            'pref' => $pref,
            'profile_input' => $input,
            'profile' => $profile,
            'route' => $route ?? new RouteService((string)$profile['transport']),
            'budget_from' => 0,   // requests are counted from here (see requestsLeft())
            'pool' => PlannerCandidates::editPool($conn, $pref, $profile, $travellerId),
            'meal_pool' => $mealPool,
            'meal_ids' => array_fill_keys(array_map(static fn(array $p): int => (int)$p['place_id'], $mealPool), true),
            'places' => $places,
            'origin' => $origin,
            'finish' => self::point($meta['end_lat'] ?? null, $meta['end_lng'] ?? null),
            'total_days' => $totalDays,
            'dates' => $dates,
            'days' => $days,
            'day_starts' => array_filter(self::dayStarts($conn, $itineraryId), static fn(int $d): bool => $d >= 1 && $d <= $totalDays, ARRAY_FILTER_USE_KEY),
        ];
        // The legs stored with the trip are the figures at hand: nothing is asked for them. A
        // change measures only the legs it needs that are not among them (plan()).
        if ($route === null) $ctx['route']->setTimeout(self::ROUTE_TIMEOUT);
        $seeds = [];
        $previous = $origin;
        foreach ($rows as $row) {
            $here = self::point($row['item_latitude'], $row['item_longitude']);
            if ($here === null && isset($places[(int)$row['place_id']])) {
                $here = self::point($places[(int)$row['place_id']]['latitude'], $places[(int)$row['place_id']]['longitude']);
            }
            if ($here === null) continue;
            if ($previous !== null && $row['distance_km'] !== null && $row['travel_time_min'] !== null) {
                $seeds[] = [$previous, $here, (float)$row['distance_km'], (int)$row['travel_time_min']];
            }
            $previous = $here;
        }
        if ($seed) $ctx['route']->seed($seeds);
        return self::$cache[$itineraryId] = $ctx;
    }

    /** The timing profile of one day: $profile (default the trip's) starting at the day's stored start. */
    private static function dayProfile(array $ctx, int $d, ?array $profile = null): array
    {
        $profile ??= $ctx['profile'];
        if (isset($ctx['day_starts'][$d])) $profile['start'] = (int)$ctx['day_starts'][$d];
        return $profile;
    }

    /** Whether the per-day table of migration 20261117_itinerary_days.sql exists. */
    private static function hasDayTable(mysqli $conn): bool
    {
        return PlannerRules::hasColumn($conn, 'itinerary_days', 'start_time');
    }

    /** Store the start of every day of the trip: day => minute; a day left out has none. */
    private static function saveDayStarts(mysqli $conn, int $itineraryId, int $totalDays, array $starts): void
    {
        $stmt = $conn->prepare("
            INSERT INTO itinerary_days (itinerary_id, day_no, start_time) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE start_time = VALUES(start_time)
        ");
        for ($d = 1; $d <= $totalDays; $d++) {
            $time = isset($starts[$d]) ? PlannerRules::sqlTime((int)$starts[$d]) : null;
            $stmt->bind_param('iis', $itineraryId, $d, $time);
            $stmt->execute();
        }
        $stmt->close();
        // Rows of days the trip no longer has.
        $drop = $conn->prepare("DELETE FROM itinerary_days WHERE itinerary_id = ? AND day_no > ?");
        $drop->bind_param('ii', $itineraryId, $totalDays);
        $drop->execute();
        $drop->close();
    }

    /** The visits of one stored day: place_id => name. */
    private static function dayTitles(mysqli $conn, int $itineraryId, int $dayNo): array
    {
        $meal = PlannerRules::hasColumn($conn, 'itinerary_items', 'meal_slot') ? ' AND meal_slot IS NULL' : '';
        $stmt = $conn->prepare("SELECT place_id, item_title FROM itinerary_items
                                WHERE itinerary_id = ? AND day_no = ? AND place_id IS NOT NULL AND item_type IN ('attraction','food','festival'){$meal}
                                ORDER BY sequence_no, item_id");
        $stmt->bind_param('ii', $itineraryId, $dayNo);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $out[(int)$row['place_id']] = (string)$row['item_title'];
        $stmt->close();
        return $out;
    }

    /** A stop as the planner's timing reads it, with the row it belongs to (null: a new row). */
    private static function stopOf(array $place, ?int $itemId, int $tier, ?array $reasons, bool $must, ?array $row = null): array
    {
        return [
            'place' => $place,
            'point' => [(float)$place['latitude'], (float)$place['longitude']],
            'must' => $must,
            'item_id' => $itemId,
            'row' => $row,
            // Set for a new place in the stop: the notes are written again from these.
            'set_place' => $reasons !== null,
            'tier' => $tier,
            'reasons' => $reasons,
        ];
    }

    /** day => the visits in their stored order. */
    private static function visits(array $ctx): array
    {
        return array_map(static fn(array $day): array => $day['visits'], $ctx['days']);
    }

    /** [kind, day, index] of an item: kind visit, meal, note or hotel; null when it is not in the trip. */
    private static function findItem(array $ctx, int $itemId): ?array
    {
        foreach ($ctx['days'] as $d => $day) {
            foreach ($day['visits'] as $i => $v) if ((int)$v['item_id'] === $itemId) return ['visit', $d, $i];
            foreach ($day['meals'] as $i => $m) if ((int)$m['item_id'] === $itemId) return ['meal', $d, $i];
            foreach ($day['notes'] as $i => $n) if ((int)$n['item_id'] === $itemId) return ['note', $d, $i];
            if ($day['hotel'] !== null && (int)$day['hotel']['item_id'] === $itemId) return ['hotel', $d, 0];
        }
        return null;
    }

    /**
     * Where each day starts: day 1 at the trip's starting point; a later day at the hotel of
     * the night before, else at the last stop of the latest day with stops.
     *
     * @return array<int,array{point:?array,exempt:bool}>
     */
    private static function starts(array $ctx, array $days): array
    {
        $point = $ctx['origin'];
        $out = [];
        foreach (array_keys($ctx['days']) as $d) {
            $out[$d] = ['point' => $point, 'exempt' => $d === 1];
            $hotelPoint = $ctx['days'][$d]['hotel']['point'] ?? null;
            if ($hotelPoint !== null) $point = $hotelPoint;
            elseif ($days[$d]) $point = $days[$d][count($days[$d]) - 1]['point'];
        }
        return $out;
    }

    /** The items and km of every day but one, for the budget rule (hotel rows included). */
    private static function budgetOutside(array $ctx, int $exceptDay, array $days, array $plans): array
    {
        $items = [];
        $km = 0.0;
        foreach ($ctx['days'] as $d => $day) {
            if ($day['hotel'] !== null) $items[] = ['item_type' => 'hotel', 'estimated_cost' => (float)$day['hotel']['estimated_cost']];
            if ($d === $exceptDay) continue;
            foreach ($days[$d] as $s) $items[] = ['item_type' => PlannerRules::itemType($s['place']), 'estimated_cost' => PlannerRules::placeCost($s['place'])];
            $km += isset($plans[$d]) ? (float)$plans[$d]['km'] : (float)$day['km'];
        }
        return ['items' => $items, 'km' => $km];
    }

    /** The places of every day but one: visits and meal places (no meal may use them). */
    private static function usedOutside(array $ctx, int $exceptDay, array $days, array $plans): array
    {
        $used = [];
        foreach ($ctx['days'] as $d => $day) {
            if ($d === $exceptDay) continue;
            foreach ($days[$d] as $s) $used[(int)$s['place']['place_id']] = true;
            if (isset($plans[$d])) {
                foreach ($plans[$d]['stops'] as $s) {
                    foreach ((array)$s['meals'] as $meal) if ($meal['place'] !== null) $used[(int)$meal['place']['place_id']] = true;
                }
            } else {
                foreach ($day['meals'] as $m) if ((int)$m['place_id'] > 0) $used[(int)$m['place_id']] = true;
            }
        }
        return $used;
    }

    /** Every place of the trip as stored: visits, meals and other rows. */
    private static function usedPlaces(array $ctx, ?int $exceptItem = null): array
    {
        $used = [];
        foreach ($ctx['days'] as $day) {
            foreach ($day['visits'] as $v) if ((int)$v['item_id'] !== $exceptItem) $used[(int)$v['place']['place_id']] = true;
            foreach ($day['meals'] as $m) if ((int)$m['item_id'] !== $exceptItem && (int)$m['place_id'] > 0) $used[(int)$m['place_id']] = true;
        }
        return $used;
    }

    /** The meals of a day the traveller chose, kept while they fit (not strict). */
    private static function storedMealChoice(array $ctx, int $d): array
    {
        $choice = [];
        foreach ($ctx['days'][$d]['meals'] as $m) {
            if (!str_contains((string)$m['notes'], ItineraryDayBuilder::MEAL_CHOSEN)) continue;
            $place = (int)$m['place_id'] > 0 ? ($ctx['places'][(int)$m['place_id']] ?? null) : null;
            if ((int)$m['place_id'] > 0 && $place === null) continue;
            $choice[(string)$m['meal_slot']] = ['place' => $place, 'strict' => false];
        }
        return $choice;
    }

    /** Why a place cannot come into the trip (null: it may): missing, avoided, already used, or a rule of the place itself. */
    private static function placeRefusal(array $ctx, ?array $place, int $placeId, ?int $exceptItem): ?array
    {
        if ($place === null) return self::refused('UNAVAILABLE', 'This place is not an active place with map coordinates.');
        $name = (string)$place['name'];
        if (in_array($placeId, (array)$ctx['pref']['avoid_places'], true)) return self::refused('AVOID', $name . ' is on your list of places to avoid.');
        if (isset(self::usedPlaces($ctx, $exceptItem)[$placeId])) return self::refused('DUPLICATE', $name . ' is already in this itinerary.');
        $reason = PlannerRules::placeReason($place, $ctx['profile']);
        if ($reason !== null) return self::refused($reason, $name . ' cannot be used: ' . PlannerRules::reasonText($reason) . '.');
        return null;
    }

    private static function loadPlace(array $ctx, int $placeId): ?array
    {
        if ($placeId <= 0) return null;
        return $ctx['places'][$placeId] ?? (PlannerCandidates::byIds($ctx['conn'], [$placeId])[0] ?? null);
    }

    private static function isMust(array $ctx, int $placeId): bool
    {
        return in_array($placeId, (array)$ctx['pref']['must_places'], true);
    }

    /**
     * The day builder's state just before position $index of a day (the stops before it timed
     * as stored), and the day as the builder reads it. The stops after $index keep their share
     * of the budget.
     */
    private static function stateBefore(array $ctx, int $dayNo, array $stops, int $index, array $used): array
    {
        $days = self::visits($ctx);
        $start = self::starts($ctx, $days)[$dayNo];
        $budget = self::budgetOutside($ctx, $dayNo, $days, []);
        foreach (array_slice($stops, $index + 1) as $s) {
            if ($s !== null) $budget['items'][] = ['item_type' => PlannerRules::itemType($s['place']), 'estimated_cost' => PlannerRules::placeCost($s['place'])];
        }
        $hotelPoint = $ctx['days'][$dayNo]['hotel']['point'] ?? null;
        $dayCtx = [
            'day_no' => $dayNo,
            'date' => $ctx['dates'][$dayNo],
            'rain' => null,
            'start' => $start['point'],
            'first_leg_exempt' => $start['exempt'],
            'used' => $used,
            'trip_items' => $budget['items'],
            'trip_km' => $budget['km'],
            'reserve_km' => 0.0,
            'previous_theme' => self::previousTheme($days, $dayNo),
            'musts' => [],
            'finish' => $dayNo === $ctx['total_days'] && $hotelPoint === null ? $ctx['finish'] : null,
        ];
        $profile = self::dayProfile($ctx, $dayNo);
        $state = ItineraryDayBuilder::startState($dayCtx, $profile);
        $prefix = array_values(array_filter(array_slice($stops, 0, $index)));
        if ($prefix && $start['point'] !== null) {
            $timed = self::timeDay($ctx, $profile, $dayNo, $start, $prefix, $days, [], ['budget' => false]);
            foreach ($timed['stops'] as $s) $state = ItineraryDayBuilder::advance($state, $s, $profile);
        }
        return [$state, $dayCtx];
    }

    /** The planner's tier and reasons for a place at a position, for the notes of the stop. */
    private static function scoreAt(array $ctx, int $dayNo, array $stops, int $index, array $place): array
    {
        $fallback = ['tier' => in_array(strtolower((string)$place['category']), (array)$ctx['profile']['interests'], true) ? 1 : 2, 'reasons' => []];
        $start = self::starts($ctx, self::visits($ctx))[$dayNo];
        if ($start['point'] === null || $ctx['days'][$dayNo]['locked']) return $fallback;
        [$state, $dayCtx] = self::stateBefore($ctx, $dayNo, $stops, $index, self::usedOutside($ctx, $dayNo, self::visits($ctx), []));
        $builder = new ItineraryDayBuilder(self::dayProfile($ctx, $dayNo), $ctx['route'], $ctx['meal_pool']);
        $ignored = null;
        $ranked = $builder->ranked([$place], $dayCtx, $state, 1, $ignored, false, true);
        if (!$ranked) return $fallback;
        return ['tier' => (int)$ranked[0]['tier'], 'reasons' => (array)$ranked[0]['reasons']];
    }

    /** Meal options for a meal row: food places by least detour, then a break without a place. */
    private static function mealOptions(array $ctx, int $dayNo, int $index, int $limit, int $offset, array $exclude): array
    {
        if ($ctx['days'][$dayNo]['locked']) return self::refused('LOCKED', 'Day ' . $dayNo . ' holds a row the planner cannot re-time, so its meals cannot be changed here.');
        $row = $ctx['days'][$dayNo]['meals'][$index];
        $slot = (string)$row['meal_slot'];
        $current = (int)($row['place_id'] ?? 0);
        $used = self::usedPlaces($ctx, (int)$row['item_id']) + $exclude;
        // The visits around the meal: a meal place lies within the meal radius of one of them.
        $near = [];
        $before = null;
        foreach ($ctx['days'][$dayNo]['visits'] as $v) {
            if ((int)$v['row']['sequence_no'] < (int)$row['sequence_no']) $before = $v['point'];
            elseif (!$near) $near[] = $v['point'];
        }
        if ($before !== null) $near[] = $before;
        $radius = (float)$ctx['profile']['meal_radius_km'];
        $days = self::visits($ctx);
        $ctx['budget_from'] = $ctx['route']->requestsMade();
        // Every food place near the meal on the figures at hand; the least detours of them
        // (as many as the page shows, and a few more) measured on the road together.
        $screened = [];
        foreach ($ctx['meal_pool'] as $place) {
            $id = (int)$place['place_id'];
            if ($id === $current || isset($used[$id])) continue;
            $close = false;
            foreach ($near as $p) {
                if (RouteService::haversineKm((float)$p[0], (float)$p[1], (float)$place['latitude'], (float)$place['longitude']) <= $radius) $close = true;
            }
            if (!$close) continue;
            $opts = ['meal_choice' => [$dayNo => [$slot => ['place' => $place, 'strict' => true]]]];
            $timed = self::timeDays($ctx, $days, [$dayNo], $opts);
            if (!$timed['result']['ok']) continue;
            $screened[] = [(float)self::plannedMeal($timed['result']['plans'][$dayNo], $slot)['detour'], $id, $place, $opts, $timed];
        }
        usort($screened, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $shown = $offset + $limit + 1 + self::SUGGEST_SPARE;
        $legs = [];
        foreach (array_slice($screened, 0, $shown) as [, , , , $timed]) array_push($legs, ...self::unknownLegs($ctx, $days, $timed));
        $measured = self::measure($ctx, $legs);
        $options = [];
        foreach ($screened as $k => [, $id, $place, $opts, $timed]) {
            if ($measured > 0 && $k < $shown) {
                $timed = self::timeDays($ctx, $days, [$dayNo], $opts);
                if (!$timed['result']['ok']) continue;
            }
            $plan = $timed['result'];
            $meal = self::plannedMeal($plan['plans'][$dayNo], $slot);
            $options[] = ['place_id' => $id, 'name' => (string)$place['name'], 'category' => 'food', 'item_type' => 'food',
                'state' => (string)$place['state'], 'district' => (string)$place['district'], 'address' => (string)($place['address'] ?? ''),
                'opening_hours' => (string)($place['opening_hours'] ?? ''), 'cost' => 0.0, 'rating' => (float)max((float)($place['avg_rating'] ?? 0), (float)($place['rating'] ?? 0)),
                'start' => self::hm((int)$meal['start']), 'end' => self::hm((int)$meal['end']), 'score' => null,
                'detour_km' => round((float)$meal['detour'], 2),
                'reasons' => [ucfirst($slot) . ' ' . number_format((float)$meal['detour'], 1) . ' km off your route, open for the whole meal']];
        }
        usort($options, static fn(array $a, array $b): int => [$a['detour_km'], $a['place_id']] <=> [$b['detour_km'], $b['place_id']]);
        if ($current > 0) {
            $plan = self::plan($ctx, $days, [$dayNo], ['meal_choice' => [$dayNo => [$slot => ['place' => null, 'strict' => true]]]]);
            if ($plan['ok']) {
                $meal = self::plannedMeal($plan['plans'][$dayNo], $slot);
                $options[] = ['place_id' => 0, 'name' => ucfirst($slot) . ' break', 'category' => 'food', 'item_type' => 'food', 'state' => '', 'district' => '',
                    'address' => '', 'opening_hours' => '', 'cost' => 0.0, 'rating' => 0.0, 'start' => self::hm((int)$meal['start']), 'end' => self::hm((int)$meal['end']),
                    'score' => null, 'detour_km' => 0.0, 'reasons' => ['Time kept free for ' . $slot . ' without a set place']];
            }
        }
        $page = array_slice($options, $offset, $limit);
        return self::done($page ? count($page) . ' option(s) fit this meal.' : 'No other choice fits this ' . $slot . ' under the rules of your trip.', [
            'kind' => 'meal', 'slot' => $slot, 'item_id' => (int)$row['item_id'], 'day_no' => $dayNo, 'offset' => $offset,
            'more' => count($options) > $offset + $limit, 'options' => $page,
        ]);
    }

    private static function plannedMeal(array $plan, string $slot): array
    {
        foreach ($plan['stops'] as $s) {
            foreach ((array)$s['meals'] as $meal) if ($meal['slot'] === $slot) return $meal;
        }
        return ['start' => 0, 'end' => 0, 'detour' => 0.0];
    }

    /** One replacement option as suggestReplacements() returns it. */
    private static function option(array $place, array $timed, float $score, array $reasons): array
    {
        return [
            'place_id' => (int)$place['place_id'],
            'name' => (string)$place['name'],
            'category' => (string)$place['category'],
            'item_type' => PlannerRules::itemType($place),
            'state' => (string)$place['state'],
            'district' => (string)$place['district'],
            'address' => (string)($place['address'] ?? ''),
            'opening_hours' => (string)($place['opening_hours'] ?? ''),
            'cost' => PlannerRules::placeCost($place),
            'rating' => (float)max((float)($place['avg_rating'] ?? 0), (float)($place['rating'] ?? 0)),
            'start' => self::hm((int)$timed['start']),
            'end' => self::hm((int)$timed['end']),
            'score' => round($score, 2),
            'detour_km' => null,
            'reasons' => array_values($reasons),
        ];
    }

    /** The main category of the latest day before $dayNo that has stops. */
    private static function previousTheme(array $days, int $dayNo): ?string
    {
        for ($d = $dayNo - 1; $d >= 1; $d--) {
            if (empty($days[$d])) continue;
            $counts = [];
            foreach ($days[$d] as $s) {
                $c = strtolower((string)$s['place']['category']);
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
            arsort($counts);
            return array_key_first($counts);
        }
        return null;
    }

    private static function isFreeDayNote(array $note): bool
    {
        return str_starts_with((string)$note['item_title'], 'Free day');
    }

    /** The stored notes of a stop with "also your lunch / dinner" as the new timing has it. */
    private static function retagFoodMeal(string $notes, ?string $foodMeal): string
    {
        $notes = preg_replace('/; also your (?:lunch|dinner)/', '', $notes) ?? $notes;
        if ($foodMeal !== null && str_contains($notes, 'Reason:')) $notes .= '; also your ' . $foodMeal;
        return mb_strlen($notes) <= 255 ? $notes : mb_substr($notes, 0, 252) . '...';
    }

    private static function samePoint(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        return abs($a[0] - $b[0]) < 1e-7 && abs($a[1] - $b[1]) < 1e-7;
    }

    /** @return array{0:float,1:float}|null */
    private static function point($lat, $lng): ?array
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '' || !RouteService::validCoord((float)$lat, (float)$lng)) return null;
        return [(float)$lat, (float)$lng];
    }

    private static function hm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private static function done(string $message, array $extra = []): array
    {
        return array_merge(['days' => []], $extra, ['ok' => true, 'status' => 'done', 'code' => null, 'message' => $message]);
    }

    private static function refused(string $code, string $message, array $extra = []): array
    {
        return array_merge(['days' => []], $extra, ['ok' => false, 'status' => 'refused', 'code' => $code, 'message' => $message]);
    }
}
