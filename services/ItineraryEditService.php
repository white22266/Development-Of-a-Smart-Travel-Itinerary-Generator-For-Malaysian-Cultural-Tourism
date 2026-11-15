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
// Which days are re-timed: the days whose stops change, and every day whose starting point or
// end moves because of it. A day starts at the hotel of the night before when one is chosen,
// else at the base of that night (itinerary_days, planned by TripLayout); a day followed by a
// night ends there (the base rule: back by the end of the day window, the leg counted, dinner
// near it). An itinerary planned before bases starts a day at the last stop of the day before.
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
require_once __DIR__ . '/TripLayout.php';

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
    /** A day planned again: rounds of settling it on the road figures at most, and candidates tried for a stop that gave way. */
    private const SETTLE_ROUNDS = 12;
    private const SETTLE_CANDIDATES = 3;
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
            if ($toDay !== $fromDay) $stop = self::varietyNote($ctx, $stop, $days[$toDay]);
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
     * again; the must-see places of the day are kept. The new day is planned on the figures at
     * hand, measured on the road and settled on those figures (settleDay()): a stop that no
     * longer fits gives way to the next candidate, so the day ends feasible whenever one
     * exists. The present day stays as it is only when the new day would lose a must-see
     * place, have no stop, or make another day break a rule (the refusal names the rule and
     * carries 'worse').
     *
     * @param array $options more and less (wishes, PlannerRules::wishTokens(): a category to see
     *                       more of becomes an interest loved, one to see less of is liked "a
     *                       little"; "type:<type>" and "group:<group>" of PlaceTypeClassifier:
     *                       a type to see more of counts as an interest loved, one to see less
     *                       of loses points and is visited at most once that day), forecast
     *                       (Y-m-d => chance of rain for the whole day, or 24 chances by the
     *                       hour) or rain (the same for this day)
     * @return array result + stops (the number of visits the day now has); a refusal + day, worse
     */
    public static function regenerateDay(mysqli $conn, int $itineraryId, int $dayNo, array $options = []): array
    {
        return self::change($conn, $itineraryId, static fn(array $ctx): array => self::buildRegenerate($ctx, $dayNo, $options));
    }

    /**
     * Save the hotel of each night (night N = after day N; a night left out has none). A hotel
     * is then where that night is spent: the day before it ends there (under the base rule: back
     * by the end of the day window, the leg counted, dinner near it) and the day after it starts
     * there; both are timed again. A night whose hotel would make either day break a rule is not
     * saved; the others are.
     *
     * The budget: when the hotels put the stored total (total(), with the days timed again) over
     * the budget, nothing is saved and the answer is HOTEL_BUDGET with the amount (total,
     * budget, over_by), unless $overBudgetConfirmed: then the hotels are saved and the answer
     * states the amount over (over_by). Choosing no hotel at all is never refused for it.
     *
     * @param array<int,array> $hotelByNight night => a hotel as HotelRecommendationService returns it
     * @return array result + nights (saved), refused_nights (night => [code, message]), total,
     *         budget, over_by
     */
    public static function setHotels(mysqli $conn, int $itineraryId, array $hotelByNight, bool $overBudgetConfirmed = false): array
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
                $oldEnds = self::ends($before);
                $saved = ItineraryHotelService::saveNights($conn, $itineraryId, $before['total_days'], $hotelByNight);
                $ctx = self::context($conn, $itineraryId, false, $route, false);   // the legs of $before stay at hand
                $plan = self::plan($ctx, self::visits($ctx), [], ['old_starts' => $oldStarts, 'old_ends' => $oldEnds, 'budget' => false]);
                if (!$plan['ok']) {
                    $conn->rollback();
                    // The night whose hotel the failing day ends at, else the one it starts from.
                    $failed = (int)$plan['day'];
                    $night = isset($hotelByNight[$failed]) && $failed < $before['total_days'] ? $failed : $failed - 1;
                    if ($night < 1 || !isset($hotelByNight[$night])) {
                        return self::refused((string)$plan['code'], (string)$plan['message']);
                    }
                    $refusedNights[$night] = [(string)$plan['code'], 'The hotel for night ' . $night . ' was not saved: ' . lcfirst((string)$plan['message'])];
                    unset($hotelByNight[$night]);
                    continue;
                }
                self::store($conn, $ctx, self::visits($ctx), $plan, [], []);
                // The budget, on the total as stored (inside this transaction).
                $money = self::budgetState($conn, $itineraryId);
                if ($hotelByNight && $money['over_by'] > 0 && !$overBudgetConfirmed) {
                    $conn->rollback();
                    self::$cache = [];
                    return self::refused('HOTEL_BUDGET', 'With ' . (count($hotelByNight) === 1 ? 'this hotel' : 'these hotels') . ' the trip would cost RM '
                        . number_format($money['total'], 2) . ', RM ' . number_format($money['over_by'], 2) . ' over your budget of RM '
                        . number_format($money['budget'], 2) . '. Nothing was saved; confirm to save ' . (count($hotelByNight) === 1 ? 'it' : 'them') . ' anyway.',
                        $money + ['nights' => 0, 'refused_nights' => $refusedNights]);
                }
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
        $message = $nights . ' hotel night(s) saved' . ($retimed ? '; day(s) ' . implode(', ', $retimed) . ' timed again to and from the hotel.' : '.');
        if ($refusedNights) $message .= ' ' . implode(' ', array_column($refusedNights, 1));
        $money = self::budgetState($conn, $itineraryId);
        if ($money['over_by'] > 0) $message .= ' The trip is now RM ' . number_format($money['over_by'], 2) . ' over your budget of RM ' . number_format($money['budget'], 2) . '.';
        return self::done($message, ['days' => $retimed, 'nights' => $nights, 'refused_nights' => $refusedNights] + $money);
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
     * The forecast chance of rain of the trip's dates inside the forecast horizon (today and the
     * 15 days after it): by the hour (24 chances) where WeatherService::rainHours() has them, as
     * the generator asks it, once for each base of the trip with the dates of its days (a day
     * without a base: at the starting point); a date without hourly values has its chance for
     * the day (rainChances(), once at the starting point, without one the first stop), which
     * counts for every hour. A date without a figure is left out; nothing is asked when no trip
     * date lies inside the horizon. Never throws.
     *
     * @return array<string,int|int[]> Y-m-d => 24 chances by the hour, or one chance for the day
     */
    public static function forecast(mysqli $conn, int $itineraryId): array
    {
        try {
            $hourly = self::hourlyForecast($conn, $itineraryId);
        } catch (Throwable $e) {
            $hourly = [];
        }
        $daily = self::dailyForecast($conn, $itineraryId, array_keys($hourly));
        return $hourly + $daily;
    }

    /**
     * The highest chance of rain inside each day's window (its stored start, else the start of
     * the preference's window, to the end of the window), for the days of the trip that have a
     * forecast: day => percent. A chance for the whole day counts for every hour.
     *
     * @param array|null $forecast as forecast() returns it; null: forecast()
     * @return array<int,int>
     */
    public static function rainInWindow(mysqli $conn, int $itineraryId, ?array $forecast = null): array
    {
        $stmt = $conn->prepare("SELECT start_date, total_days, preference_id FROM itineraries WHERE itinerary_id = ? LIMIT 1");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta || empty($meta['start_date'])) return [];
        $forecast ??= self::forecast($conn, $itineraryId);
        if (!$forecast) return [];
        $profile = PlannerRules::profile(PlannerRules::loadPreference($conn, (int)$meta['preference_id']) ?? PlannerRules::normalizePreference([]));
        $starts = self::dayStarts($conn, $itineraryId);
        $out = [];
        for ($d = 1; $d <= max(1, (int)$meta['total_days']); $d++) {
            $date = (new DateTimeImmutable((string)$meta['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
            if (!isset($forecast[$date])) continue;
            $rain = self::rainOfDay($forecast[$date]);
            $at = ItineraryDayBuilder::rainAt($rain, (int)($starts[$d] ?? $profile['start']), (int)$profile['end']);
            if ($at !== null) $out[$d] = (int)$at['max'];
        }
        return $out;
    }

    /** A forecast value (one chance for the day, or 24 by the hour) as the day builder reads it: rain or rain_hours. */
    private static function rainOfDay($value): array
    {
        if (is_array($value) && count($value) === 24) {
            return ['rain' => max(array_map('intval', $value)), 'rain_hours' => array_map(static fn($h): int => max(0, min(100, (int)$h)), array_values($value))];
        }
        return ['rain' => is_numeric($value) ? max(0, min(100, (int)$value)) : null, 'rain_hours' => null];
    }

    /** The chance of rain by the hour of the trip's dates inside the horizon: once for each base (forecast()). */
    private static function hourlyForecast(mysqli $conn, int $itineraryId): array
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
        $bases = self::dayBases($conn, $itineraryId);
        $origin = self::point($meta['origin_lat'] ?? null, $meta['origin_lng'] ?? null);
        $byPoint = [];
        for ($d = 1; $d <= max(1, (int)$meta['total_days']); $d++) {
            $date = (new DateTimeImmutable((string)$meta['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d');
            if ($date < $today || $date > $horizon) continue;
            $point = $bases[$d]['point'] ?? $origin;
            if ($point === null) continue;
            $key = round($point[0], 5) . ',' . round($point[1], 5);
            $byPoint[$key]['point'] = $point;
            $byPoint[$key]['dates'][] = $date;
        }
        $out = [];
        foreach ($byPoint as $group) {
            foreach (WeatherService::rainHours($group['point'][0], $group['point'][1], $group['dates']) as $date => $hours) {
                if (is_array($hours) && count($hours) === 24) $out[$date] = array_map('intval', array_values($hours));
            }
        }
        return $out;
    }

    /**
     * The chance of rain for the day of the trip's dates inside the horizon that are not in
     * $skip: once for the trip, at its starting point (without one: the first stop).
     *
     * @return array<string,int>
     */
    private static function dailyForecast(mysqli $conn, int $itineraryId, array $skip = []): array
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
            if ($date >= $today && $date <= $horizon && !in_array($date, $skip, true)) $dates[] = $date;
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
     * Plan again every day on which some hour inside the day's window has a chance of rain of
     * $threshold percent or more (rainInWindow()), with the forecast by the hour (the day
     * builder then judges each stop by the hours of its visit: indoor places preferred, and from
     * 80 percent tried first). The other days stay as they are. Each day is planned in a
     * transaction of its own: a day that cannot be planned again keeps its stops, and the
     * others are still done.
     *
     * @param array|null $forecast Y-m-d => chance of rain for the day, or 24 chances by the hour; null: forecast()
     * @return array result + rainy (day => highest chance in its window), changes (day => status,
     *         code, message, rain, worse (why a kept day was kept, see settleDay()), removed
     *         (names), added (names))
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

        $rainy = array_filter(self::rainInWindow($conn, $itineraryId, $forecast), static fn(int $chance): bool => $chance >= $threshold);
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
            $changes[$d] = ['status' => $r['status'], 'code' => $r['code'], 'message' => $r['message'], 'rain' => $chance, 'worse' => $r['worse'] ?? null,
                            'removed' => $removed, 'added' => $added];
            if ($r['ok']) {
                $retimed = array_merge($retimed, (array)$r['days']);
                $sentences[] = 'Day ' . $d . ' (' . $chance . '% chance of rain): '
                    . ($removed || $added
                        ? ($removed ? implode(', ', $removed) . ' out' : '') . ($removed && $added ? '; ' : '') . ($added ? implode(', ', $added) . ' in' : '') . '.'
                        : 'the same places fit best, times planned again.');
            } else {
                $firstRefusal ??= $r;
                $sentences[] = 'Day ' . $d . ' (' . $chance . '% chance of rain) was kept: ' . lcfirst((string)preg_replace('/^Day \d+ was kept as it is: /', '', (string)$r['message']));
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
     * own; a day the optimiser left without stops gets the "Free day" note. The message names
     * what was replaced, what could not be, a day kept as it was and the amount over the budget
     * (optimiseSentence()).
     *
     * @return array result + report (ItineraryRouteOptimiser::optimise())
     */
    public static function optimise(mysqli $conn, int $itineraryId): array
    {
        self::$cache = [];
        $conn->begin_transaction();
        try {
            $report = ItineraryRouteOptimiser::optimise($conn, $itineraryId);
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            self::$cache = [];
            throw $e;
        }
        self::$cache = [];
        return self::done(self::optimiseSentence($report, [self::class, 'optimiseText']), ['days' => array_keys($report['days']), 'report' => $report]);
    }

    /** One English sentence of OPTIMISE_TEXT with its variables. */
    public static function optimiseText(string $key, array $vars = []): string
    {
        $text = self::OPTIMISE_TEXT[$key] ?? $key;
        foreach ($vars as $name => $value) $text = str_replace('{' . $name . '}', (string)$value, $text);
        return $text;
    }

    /** The English sentences of optimiseSentence(); the pages use the keys optm.* of lang/. */
    private const OPTIMISE_TEXT = [
        'optm.done_one' => 'Day {days} is now in a shorter order{saved}.',
        'optm.done_many' => 'Days {days} are now in a shorter order{saved}.',
        'optm.saved' => ', with {n} min less travelling',
        'optm.best' => 'Every day is already in its best order.',
        'optm.times' => 'Times are up to date.',
        'optm.replaced' => 'Replaced, because no order keeps them within the trip\'s rules: {list}.',
        'optm.replaced_item' => 'day {day}: {old} by {new}',
        'optm.not_replaced' => 'Removed without a replacement, because no order and no other place fits: {list}.',
        'optm.removed_item' => 'day {day}: {old}',
        'optm.kept_day' => 'Day {day} was kept as it was: no order keeps its must-see place {name}.',
        'optm.over_budget' => 'The trip now comes to RM {total}, RM {over} over your budget.',
        'optm.list_sep' => ', ',
        'optm.list_and' => ' and ',
    ];

    /**
     * What the optimiser did, in one paragraph: the days in a shorter order, the stops replaced
     * (old by new), those removed without a replacement, the days kept as they were and the
     * amount over the budget.
     *
     * @param callable(string,array):string $t a translator: key of OPTIMISE_TEXT, variables
     */
    public static function optimiseSentence(array $report, callable $t): string
    {
        $days = (array)($report['reordered_days'] ?? []);
        $parts = [];
        if ($days) {
            $list = count($days) === 1 ? (string)$days[0] : implode($t('optm.list_sep', []), array_slice($days, 0, -1)) . $t('optm.list_and', []) . end($days);
            $saved = (int)($report['saved_min'] ?? 0) > 0 ? $t('optm.saved', ['n' => (int)$report['saved_min']]) : '';
            $parts[] = $t(count($days) === 1 ? 'optm.done_one' : 'optm.done_many', ['days' => $list, 'saved' => $saved]);
        } else {
            $parts[] = $t('optm.best', []);
        }
        $replaced = array_map(static fn(array $r): string => $t('optm.replaced_item', ['day' => $r['day'], 'old' => $r['old'], 'new' => $r['new']]), (array)($report['replaced'] ?? []));
        if ($replaced) $parts[] = $t('optm.replaced', ['list' => implode('; ', $replaced)]);
        $lost = array_map(static fn(array $r): string => $t('optm.removed_item', ['day' => $r['day'], 'old' => $r['old']]), (array)($report['not_replaced'] ?? []));
        if ($lost) $parts[] = $t('optm.not_replaced', ['list' => implode('; ', $lost)]);
        foreach ((array)($report['kept_days'] ?? []) as $d => $name) $parts[] = $t('optm.kept_day', ['day' => $d, 'name' => $name]);
        if ((float)($report['over_by'] ?? 0) > 0) {
            $parts[] = $t('optm.over_budget', ['total' => number_format((float)$report['total'], 2), 'over' => number_format((float)$report['over_by'], 2)]);
        }
        $parts[] = $t('optm.times', []);
        return implode(' ', $parts);
    }

    /** Places the day search offers for each day, measured with the trip, in case a stop gives way (reorderTrip()). */
    private const OPTIMISE_CANDIDATES = 3;

    /**
     * Reorder and re-time every day of an itinerary with the least travelling that keeps every
     * rule (ItineraryRouteOptimiser::optimise(), the "Optimise route" button). Call it inside the
     * caller's transaction.
     *
     *   1. For every day, the day search with the stored base and group (as regenerateDay() runs
     *      it) names the places it would take (OPTIMISE_CANDIDATES): they stand by for a stop that
     *      gives way, and are measured with the trip.
     *   2. One request: a table of the trip's points (visits, hotels and bases, start and end
     *      point, then while there is room the candidates and the meal places), or for a long trip
     *      (more than PlannerRules::LONG_TRIP_POINTS points of its own) one route request along
     *      the legs of each day's best order, the legs to its end and the candidates' legs; the
     *      rest stays on the figures at hand.
     *   3. Every day is ordered on those figures from where it starts to its night (orderDay()).
     *      A must-see place is never dropped for a better order: when no order keeps it, the
     *      stored order is timed again, and when that fails too the day stays as it was. A stop
     *      no order keeps gives way to the candidates, each tried in every order and taken only
     *      inside the budget rule and the traveller's limit on places of worship.
     *   4. The days are stored as an edit stores them (meals placed again, the dinner near the
     *      base, the hotel time); a day left without stops gets the "Free day" note. A day that
     *      holds a row the planner cannot time, or that was kept, is left as it was (its legs
     *      too). When the new distances put the trip over the budget nothing is deleted: the
     *      report says by how much (over_by).
     *
     * @return array{days:array,reordered_days:int[],saved_min:int,removed:string[],replaced:array,
     *               not_replaced:array,kept_days:array,total:float,budget:float,over_by:float,long_trip:bool}
     *         days: day => stops, before_min, after_min, reordered, problems (stops that gave
     *         way), replaced, not_replaced (counts); replaced: [day, old, new, reason]; not_replaced:
     *         [day, old, reason]; kept_days: day => the must-see place no order keeps
     */
    public static function reorderTrip(mysqli $conn, int $itineraryId): array
    {
        $report = ['days' => [], 'reordered_days' => [], 'saved_min' => 0, 'removed' => [], 'replaced' => [], 'not_replaced' => [],
                   'kept_days' => [], 'total' => 0.0, 'budget' => 0.0, 'over_by' => 0.0, 'long_trip' => false];
        self::$cache = [];
        $ctx = self::context($conn, $itineraryId, false);
        if ($ctx === null) return $report;
        $route = $ctx['route'];
        $route->setTimeout(ItineraryRouteOptimiser::ROUTE_TIMEOUT);
        $days = self::visits($ctx);
        $starts = self::starts($ctx, $days);
        $usable = static fn(int $d): bool => $days[$d] && !$ctx['days'][$d]['locked'] && $starts[$d]['point'] !== null;

        // 1. The day search's places for every day, before anything is measured.
        $standby = [];
        foreach (array_keys($ctx['days']) as $d) {
            if ($usable($d)) $standby[$d] = self::standbyPlaces($ctx, $days, $d);
        }

        // 2. One request for the trip.
        $pointsByDay = [];
        $mealPoints = [];
        $own = [];
        foreach ($ctx['days'] as $d => $day) {
            $pointsByDay[$d] = array_column($days[$d], 'point');
            foreach ($day['meals'] as $m) if (($p = self::point($m['item_latitude'], $m['item_longitude'])) !== null) $mealPoints[] = $p;
            $night = self::nightPoint($ctx, $d);
            if ($night !== null) $pointsByDay[$d][] = $night;
        }
        if ($ctx['origin'] !== null) $pointsByDay[1][] = $ctx['origin'];
        if ($ctx['finish'] !== null) $pointsByDay[$ctx['total_days']][] = $ctx['finish'];
        foreach ($pointsByDay as $points) foreach ($points as $p) $own[round((float)$p[0], 5) . ',' . round((float)$p[1], 5)] = true;
        $candidatePoints = [];
        foreach ($standby as [, $list]) foreach ($list as $c) $candidatePoints[] = $c['point'];
        $report['long_trip'] = count($own) > PlannerRules::LONG_TRIP_POINTS;
        if ($report['long_trip']) {
            // A long trip asks for no large table: one route request along the legs that matter.
            $legs = [];
            $candidateLegs = [];
            foreach (array_keys($ctx['days']) as $d) {
                if (!$usable($d)) continue;
                $start = $starts[$d]['point'];
                $frame = self::timingFrame($ctx, $d, $days, ['budget' => false]);
                $end = $frame['end'] ?? $frame['finish'];
                $best = ItineraryRouteOptimiser::orderDay($route, self::dayProfile($ctx, $d), $start, $days[$d], $frame);
                $from = $start;
                foreach (array_merge($best['stops'], $best['dropped']) as $s) { $legs[] = [$from, $s['point']]; $from = $s['point']; }
                if ($end !== null) {
                    $legs[] = [$from, $end];
                    foreach ($days[$d] as $s) $legs[] = [$s['point'], $end];
                }
                foreach ($standby[$d][1] ?? [] as $c) {
                    $candidateLegs[] = [$start, $c['point']];
                    foreach ($days[$d] as $s) { $candidateLegs[] = [$s['point'], $c['point']]; $candidateLegs[] = [$c['point'], $s['point']]; }
                    if ($end !== null) $candidateLegs[] = [$c['point'], $end];
                }
            }
            $route->measureLegs(array_merge($legs, $candidateLegs), 1);
        } else {
            $route->preloadTrip($pointsByDay, array_merge($candidatePoints, $mealPoints));
        }

        // 3. Every day ordered on those figures; a stop that gives way is replaced where a place fits.
        $deleted = [];
        $plans = [];        // day => its new order (for the budget and the meal places of the days after it)
        $kept = [];         // days left as they were
        $newStops = [];     // day => true: a new place came in (its rain notes are written again)
        foreach (array_keys($ctx['days']) as $d) {
            if (!$usable($d)) continue;
            $start = self::starts($ctx, $days)[$d];
            $profile = self::dayProfile($ctx, $d);
            $frame = self::timingFrame($ctx, $d, $days, ['budget' => false], $plans);
            $before = self::travelMinutes($route, $start['point'], array_column($days[$d], 'point'), $frame['end'] ?? $frame['finish']);
            $ordered = ItineraryRouteOptimiser::orderDay($route, $profile, $start['point'], $days[$d], $frame);
            $mustLost = array_values(array_filter($ordered['dropped'], static fn(array $s): bool => !empty($s['must'])));
            if ($mustLost) {
                // No order keeps the must-see place: the stored order, timed again, or the day as it was.
                $inOrder = ItineraryRouteOptimiser::timeInOrder($route, $profile, $start['point'], $days[$d], $frame + ['first_leg_exempt' => $start['exempt']]);
                if ($inOrder['dropped']) {
                    $kept[$d] = true;
                    $report['kept_days'][$d] = (string)$mustLost[0]['place']['name'];
                    $report['days'][$d] = ['stops' => count($days[$d]), 'before_min' => $before, 'after_min' => $before, 'reordered' => false,
                                           'problems' => 0, 'replaced' => 0, 'not_replaced' => 0];
                    continue;
                }
                $ordered = $inOrder;
            }
            $stops = $ordered['stops'];
            $wanted = count($days[$d]);
            $fills = [];
            if ($ordered['dropped'] && isset($standby[$d])) {
                [$dayCtx, $list] = $standby[$d];
                $budgetFrame = self::timingFrame($ctx, $d, $days, ['budget' => true], $plans);
                $builder = new ItineraryDayBuilder($profile, $route, $ctx['meal_pool']);
                $taken = self::usedOutside($ctx, $d, $days, $plans);
                foreach ($days[$d] as $s) $taken[(int)$s['place']['place_id']] = true;
                foreach ($list as $c) {
                    if (count($stops) >= $wanted) break;
                    $id = (int)$c['place']['place_id'];
                    if (isset($taken[$id]) || PlannerRules::twinIn($profile, $id, $taken) !== null) continue;
                    if (self::placeRefusal($ctx, $c['place'], $id, null) !== null) continue;
                    $c = $builder->relevel($c, $dayCtx, $stops);
                    if (self::worshipRefusal($ctx, array_merge($stops, [$c]), $d) !== null) continue;
                    // In every order, inside the budget rule.
                    $try = ItineraryRouteOptimiser::orderDay($route, $profile, $start['point'], array_merge($stops, [$c]), $budgetFrame);
                    if ($try['dropped']) continue;
                    $stops = $try['stops'];
                    $ordered = ['dropped' => $ordered['dropped']] + $try;
                    $fills[] = $c;
                    $taken[$id] = true;
                }
            }
            foreach ($ordered['dropped'] as $k => $stop) {
                $deleted[] = (int)$stop['item_id'];
                $reason = PlannerRules::reasonText((string)$stop['reason']);
                $report['removed'][] = 'Day ' . $d . ': ' . $stop['row']['item_title'] . ' (' . $reason . ')';
                if (isset($fills[$k])) {
                    $report['replaced'][] = ['day' => $d, 'old' => (string)$stop['row']['item_title'], 'new' => (string)$fills[$k]['place']['name'], 'reason' => (string)$stop['reason']];
                } else {
                    $report['not_replaced'][] = ['day' => $d, 'old' => (string)$stop['row']['item_title'], 'reason' => (string)$stop['reason']];
                }
            }
            $oldIds = array_map(static fn(array $s): int => (int)$s['item_id'], $days[$d]);
            $newIds = array_map(static fn(array $s): int => (int)($s['item_id'] ?? 0), $stops);
            $reordered = $newIds !== $oldIds;
            $report['days'][$d] = ['stops' => count($stops), 'before_min' => $before, 'after_min' => (int)$ordered['minutes'], 'reordered' => $reordered,
                                   'problems' => count($ordered['dropped']), 'replaced' => min(count($fills), count($ordered['dropped'])),
                                   'not_replaced' => max(0, count($ordered['dropped']) - count($fills))];
            if ($reordered) {
                $report['reordered_days'][] = $d;
                $report['saved_min'] += max(0, $before - (int)$ordered['minutes']);
            }
            // The stops in their new order: a stored stop keeps its row, a new place gets one.
            $days[$d] = array_map(static function (array $s): array {
                if (!empty($s['item_id'])) return array_intersect_key($s, array_flip(['place', 'point', 'must', 'item_id', 'row', 'set_place', 'tier', 'reasons']));
                return self::stopOf($s['place'], null, (int)($s['tier'] ?? 1), (array)($s['reasons'] ?? []), false);
            }, $stops);
            if ($fills) $newStops[$d] = true;
            $plans[$d] = $ordered;
        }
        $timed = array_values(array_filter(array_keys($ctx['days']), static fn(int $d): bool => !isset($kept[$d])));
        // Rain notes of a new stop are written for its visit as timed (no forecast is asked here).
        $rainDay = array_map(static fn(): array => ['rain' => null, 'rain_hours' => null], $newStops);
        $plan = self::plan($ctx, $days, $timed, ['budget' => false, 'best_effort' => true, 'measure' => false, 'rain_day' => $rainDay]);
        if (empty($plan['ok'])) {
            // Only a rule of the whole change can refuse a best-effort timing: nothing is stored then.
            self::$cache = [];
            $nothing = ['days' => [], 'reordered_days' => [], 'saved_min' => 0, 'removed' => [], 'replaced' => [], 'not_replaced' => [], 'kept_days' => []];
            return self::budgetState($conn, $itineraryId) + $nothing + $report;
        }
        // A day that is not timed again keeps its rows and its stored legs.
        $legs = [];
        foreach (array_keys($ctx['days']) as $d) {
            if (isset($plan['plans'][$d])) continue;
            foreach (array_merge($ctx['days'][$d]['visits'], $ctx['days'][$d]['meals'], $ctx['days'][$d]['notes']) as $row) {
                $r = $row['row'] ?? $row;
                if (isset($r['item_id'])) $legs[(int)$r['item_id']] = [$r['distance_km'], $r['travel_time_min']];
            }
            if ($ctx['days'][$d]['hotel'] !== null) $legs[(int)$ctx['days'][$d]['hotel']['item_id']] = [$ctx['days'][$d]['hotel']['distance_km'], $ctx['days'][$d]['hotel']['travel_time_min']];
        }
        self::store($conn, $ctx, $days, $plan, $deleted, ['why_empty' => 'no order keeps its stops within the rules', 'rain_day' => $rainDay]);
        if ($legs) {
            $update = $conn->prepare("UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ? AND itinerary_id = ?");
            foreach ($legs as $itemId => [$km, $min]) {
                $km = $km !== null ? (float)$km : null;
                $min = $min !== null ? (int)$min : null;
                $update->bind_param('diii', $km, $min, $itemId, $itineraryId);
                $update->execute();
            }
            $update->close();
            self::total($conn, $itineraryId);
        }
        $report = self::budgetState($conn, $itineraryId) + $report;
        self::$cache = [];
        return $report;
    }

    /**
     * The places the day search would take for a day (reorderTrip()): the search with the stored
     * base and group, the other days' places used, the day's must-see places kept, on the figures
     * at hand; its stops and its next places (spares) that are not stops of the day already, best
     * first, at most OPTIMISE_CANDIDATES.
     *
     * @return array{0:array,1:array} the day as the builder reads it, and the places (stops of the search)
     */
    private static function standbyPlaces(array $ctx, array $days, int $d): array
    {
        $profile = self::dayProfile($ctx, $d);
        $start = self::starts($ctx, $days)[$d];
        $budget = self::budgetOutside($ctx, $d, $days, []);
        $musts = array_values(array_filter($days[$d], static fn(array $s): bool => $s['must']));
        $dayCtx = [
            'day_no' => $d,
            'date' => $ctx['dates'][$d],
            'rain' => null,
            'rain_hours' => null,
            'start' => $start['point'],
            'first_leg_exempt' => $start['exempt'],
            'used' => self::usedOutside($ctx, $d, $days, []),
            'trip_items' => $budget['items'],
            'trip_km' => $budget['km'],
            'reserve_km' => 0.0,
            'previous_theme' => self::previousTheme($days, $d),
            'musts' => array_map(static fn(array $s): array => $s['place'], $musts),
        ] + self::dayEnd($ctx, $d);
        $mustIds = PlannerRules::withTwins($profile, array_map(static fn(array $s): int => (int)$s['place']['place_id'], $musts));
        $pool = array_values(array_filter($ctx['pool'], static fn(array $p): bool => !isset($mustIds[(int)$p['place_id']])));
        $built = (new ItineraryDayBuilder($profile, $ctx['route'], $ctx['meal_pool']))->search($pool, $dayCtx);
        $here = [];
        foreach ($days[$d] as $s) $here[(int)$s['place']['place_id']] = true;
        $out = [];
        foreach (array_merge($built['stops'], $built['spares']) as $c) {
            $id = (int)$c['place']['place_id'];
            if (!empty($c['must']) || isset($here[$id]) || isset($out[$id]) || PlannerRules::twinIn($profile, $id, $here) !== null) continue;
            $out[$id] = $c;
            if (count($out) >= self::OPTIMISE_CANDIDATES) break;
        }
        return [$dayCtx, array_values($out)];
    }

    /** Minutes of travelling from $start through the points to $end. */
    private static function travelMinutes(RouteService $route, array $start, array $points, ?array $end): int
    {
        $minutes = 0;
        $from = $start;
        foreach (array_merge($points, $end !== null ? [$end] : []) as $to) {
            $minutes += (int)$route->getSegment($from[0], $from[1], $to[0], $to[1])['travel_time_min'];
            $from = $to;
        }
        return $minutes;
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
            SELECT tp.transport_type, i.origin_lat, i.origin_lng, i.total_days, i.start_date, i.preference_id
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
            SELECT ii.item_id, ii.day_no, ii.item_type, ii.start_time, ii.end_time,
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
        $itemRows = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        // The night after a day without a hotel row is spent at its base: the next day's first leg starts there.
        $nights = self::nightBases(self::dayBases($conn, $itineraryId), $itemRows, max(1, (int)$meta['total_days']));
        foreach ($itemRows as $item) {
            $here = null;
            if ($item['latitude'] !== null && $item['longitude'] !== null && RouteService::validCoord((float)$item['latitude'], (float)$item['longitude'])) {
                $here = [(float)$item['latitude'], (float)$item['longitude']];
            }
            $days[(int)$item['day_no']][] = [(int)$item['item_id'], $here];
        }
        $itemsStmt->close();
        // Peak hours: each leg is stored with the factor of the hour it leaves (as the day was timed).
        $transport = (string)($meta['transport_type'] ?? 'car');
        $dayStart = (int)PlannerRules::profile(PlannerRules::loadPreference($conn, (int)$meta['preference_id']) ?? PlannerRules::normalizePreference([]))['start'];
        $departs = self::departures($itemRows, self::dayStarts($conn, $itineraryId), $dayStart);
        $dateOf = static fn(int $d): string => $meta['start_date'] !== null
            ? (new DateTimeImmutable((string)$meta['start_date']))->modify('+' . ($d - 1) . ' days')->format('Y-m-d') : '';

        $previous = null;
        if ($meta['origin_lat'] !== null && $meta['origin_lng'] !== null && RouteService::validCoord((float)$meta['origin_lat'], (float)$meta['origin_lng'])) {
            $previous = [(float)$meta['origin_lat'], (float)$meta['origin_lng']];
        }

        $chain = $previous !== null ? [$previous] : [];
        $lastDay = null;
        foreach ($days as $dayNo => $items) {
            if ($lastDay !== null) for ($night = $lastDay; $night < $dayNo; $night++) if (isset($nights[$night])) $chain[] = $nights[$night];
            $lastDay = $dayNo;
            foreach ($items as [, $here]) {
                if ($here !== null) $chain[] = $here;
            }
        }
        if ($measure) $route->preloadChain($chain);

        $update = $conn->prepare("UPDATE itinerary_items SET distance_km = ?, travel_time_min = ? WHERE item_id = ?");
        $lastDay = null;
        foreach ($days as $dayNo => $items) {
            if ($lastDay !== null) for ($night = $lastDay; $night < $dayNo; $night++) if (isset($nights[$night])) $previous = $nights[$night];
            $lastDay = $dayNo;
            foreach ($items as [$itemId, $here]) {
                $distanceKm = null;
                $travelMin = null;
                if ($previous !== null && $here !== null) {
                    $segment = $route->getSegment($previous[0], $previous[1], $here[0], $here[1]);
                    $distanceKm = (float)$segment['distance_km'];
                    $travelMin = PlannerRules::peakMinutes((int)$segment['travel_time_min'], $dateOf((int)$dayNo), $departs[$itemId] ?? $dayStart, $previous, $transport);
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
        $meta = self::costMeta($conn, $itineraryId);
        if ($meta === null) return;
        $itemsStmt = $conn->prepare("SELECT item_type, estimated_cost, distance_km FROM itinerary_items WHERE itinerary_id = ?");
        $itemsStmt->bind_param('i', $itineraryId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();
        $totalDistanceKm = array_sum(array_map(static fn($item) => (float)($item['distance_km'] ?? 0), $items));
        $total = self::costOf($meta, $items, $totalDistanceKm);

        $update = $conn->prepare("UPDATE itineraries SET total_estimated_cost = ? WHERE itinerary_id = ?");
        $update->bind_param('di', $total, $itineraryId);
        $update->execute();
        $update->close();
    }

    /** What total() prices a trip with: its days, transport, budget, tier and party; null when it does not exist. */
    private static function costMeta(mysqli $conn, int $itineraryId): ?array
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
        return $meta ?: null;
    }

    /** The total of these items (item_type, estimated_cost) and km, exactly as total() stores it. */
    private static function costOf(array $meta, array $items, float $totalDistanceKm): float
    {
        $transportType = (string)($meta['transport_type'] ?? 'car');
        $totalDays = (int)($meta['total_days'] ?? 1);
        $budget = (float)($meta['budget'] ?? 0);
        $travellerType = (string)($meta['traveller_type'] ?? 'solo');
        $partySize = CostEstimationService::resolvePartySize($travellerType, isset($meta['party_size']) ? (int)$meta['party_size'] : null);
        $tierDefaults = CostEstimationService::budgetTierDefaults(strtolower((string)($meta['budget_tier'] ?? 'normal')), $budget, $totalDays, $partySize);
        $costService = new CostEstimationService($transportType, $totalDays, $budget, $travellerType, $partySize);
        $breakdown = $costService->calculate($items, $totalDistanceKm, $tierDefaults['hotel'], 3, $tierDefaults['meal']);
        return (float)($breakdown['total_cost'] ?? 0);
    }

    /**
     * The stored total against the budget: total, budget (0: none), over_by (0 when within).
     *
     * @return array{total:float,budget:float,over_by:float}
     */
    public static function budgetState(mysqli $conn, int $itineraryId): array
    {
        $stmt = $conn->prepare("SELECT i.total_estimated_cost, tp.budget FROM itineraries i LEFT JOIN traveller_preferences tp ON tp.preference_id = i.preference_id WHERE i.itinerary_id = ? LIMIT 1");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $total = round((float)($row['total_estimated_cost'] ?? 0), 2);
        $budget = round((float)($row['budget'] ?? 0), 2);
        return ['total' => $total, 'budget' => $budget, 'over_by' => $budget > 0 && $total > $budget + 0.005 ? round($total - $budget, 2) : 0.0];
    }

    /**
     * Whether the trip stays inside the budget with each of these hotels for the given nights
     * (the other nights keep their stored hotel, or the one in $others), for marking hotel
     * suggestions. The total is computed as total() stores it: the items with the hotel rows of
     * those nights in place of the stored ones, and the stored km with the legs to and from the
     * hotel estimated (the leg from the day's last row with a place to the hotel, and from the
     * hotel to the next day's first). No routing request is made.
     *
     * @param int[]             $nights night numbers the hotel would cover (night N = after day N)
     * @param array<int,array>  $hotels hotels as HotelRecommendationService returns them
     * @param array<int,array>  $others night => hotel chosen on the page but not saved yet
     * @return array<int,array{total:float,budget:float,over_by:float,within:bool}> by the keys of $hotels
     */
    public static function hotelBudget(mysqli $conn, int $itineraryId, array $nights, array $hotels, array $others = []): array
    {
        $meta = self::costMeta($conn, $itineraryId);
        if ($meta === null) return [];
        $totalDays = max(1, (int)$meta['total_days']);
        $stmt = $conn->prepare("SELECT ii.day_no, ii.item_type, ii.estimated_cost, ii.distance_km,
                                       COALESCE(ii.item_latitude, cp.latitude) AS lat, COALESCE(ii.item_longitude, cp.longitude) AS lng
                                FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
                                WHERE ii.itinerary_id = ? ORDER BY ii.day_no, ii.sequence_no, ii.item_id");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $nights = array_values(array_filter(array_map('intval', $nights), static fn(int $n): bool => $n >= 1 && $n < $totalDays));
        $route = new RouteService((string)($meta['transport_type'] ?? 'car'));
        $budget = (float)($meta['budget'] ?? 0);
        $out = [];
        foreach ($hotels as $key => $hotel) {
            $chosen = [];
            foreach ($others as $n => $h) if (is_array($h) && (int)$n >= 1 && (int)$n < $totalDays) $chosen[(int)$n] = $h;
            foreach ($nights as $n) $chosen[$n] = $hotel;
            $items = [];
            $km = 0.0;
            $lastPoint = [];   // day => the point of its last row with a place, not the hotel
            $firstRow = [];    // day => [point, stored km] of its first row with a place
            foreach ($rows as $r) {
                $d = (int)$r['day_no'];
                $type = strtolower((string)$r['item_type']);
                $point = self::point($r['lat'], $r['lng']);
                if ($type === 'hotel' && isset($chosen[$d])) continue;   // replaced below
                $items[] = ['item_type' => $type, 'estimated_cost' => (float)$r['estimated_cost']];
                $km += (float)($r['distance_km'] ?? 0);
                if ($point === null || $type === 'hotel') continue;
                $lastPoint[$d] = $point;
                $firstRow[$d] ??= [$point, (float)($r['distance_km'] ?? 0)];
            }
            foreach ($chosen as $n => $h) {
                $items[] = ['item_type' => 'hotel', 'estimated_cost' => max(0.0, (float)($h['price_per_night'] ?? 0))];
                $at = self::point($h['latitude'] ?? null, $h['longitude'] ?? null);
                if ($at === null) continue;
                if (isset($lastPoint[$n])) $km += (float)$route->getSegment($lastPoint[$n][0], $lastPoint[$n][1], $at[0], $at[1])['distance_km'];
                if (isset($firstRow[$n + 1])) {
                    [$p, $stored] = $firstRow[$n + 1];
                    $km += (float)$route->getSegment($at[0], $at[1], $p[0], $p[1])['distance_km'] - $stored;
                }
            }
            $total = round(self::costOf($meta, $items, max(0.0, $km)), 2);
            $over = $budget > 0 && $total > $budget + 0.005 ? round($total - $budget, 2) : 0.0;
            $out[$key] = ['total' => $total, 'budget' => round($budget, 2), 'over_by' => $over, 'within' => $over <= 0];
        }
        return $out;
    }

    // =====================================================================================
    // Versions of a plan (ItineraryGenerator::createVersion(), migration
    // 20261122_itinerary_versions.sql): itineraries with version_of set are not trips of their
    // own until one is chosen. The caller checks that the itinerary is the traveller's.
    // =====================================================================================

    /** Whether the database can store versions (the columns of migration 20261122_itinerary_versions.sql). */
    public static function hasVersions(mysqli $conn): bool
    {
        return PlannerRules::hasColumn($conn, 'itineraries', 'version_of');
    }

    /**
     * The versions of an itinerary, oldest first: itinerary_id, plan_style, created_at.
     *
     * @return array<int,array{itinerary_id:int,plan_style:string,created_at:string}>
     */
    public static function versionList(mysqli $conn, int $itineraryId): array
    {
        if (!self::hasVersions($conn)) return [];
        $stmt = $conn->prepare('SELECT itinerary_id, plan_style, created_at FROM itineraries WHERE version_of = ? ORDER BY itinerary_id');
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $out = array_map(static fn(array $r): array => ['itinerary_id' => (int)$r['itinerary_id'], 'plan_style' => (string)$r['plan_style'], 'created_at' => (string)$r['created_at']],
            $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        return $out;
    }

    /**
     * Make one version the itinerary under review: it is no version any more, and the itinerary
     * it was a version of goes with its other versions. $versionId equal to $itineraryId keeps
     * the first plan and removes the versions. One transaction.
     *
     * @return array result + itinerary_id (the one that remains)
     */
    public static function chooseVersion(mysqli $conn, int $itineraryId, int $versionId): array
    {
        if (!self::hasVersions($conn)) return self::refused('UNAVAILABLE', 'Versions of a plan cannot be stored on this database yet.');
        if ($versionId === $itineraryId) {
            self::dropVersions($conn, $itineraryId);
            return self::done('The first plan is kept; its other versions were removed.', ['itinerary_id' => $itineraryId]);
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT itinerary_id FROM itineraries WHERE itinerary_id = ? AND version_of = ? LIMIT 1 FOR UPDATE');
            $stmt->bind_param('ii', $versionId, $itineraryId);
            $stmt->execute();
            $found = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$found) { $conn->rollback(); return self::refused('NOT_FOUND', 'This version was not found.'); }
            $stmt = $conn->prepare('UPDATE itineraries SET version_of = NULL WHERE itinerary_id = ?');
            $stmt->bind_param('i', $versionId);
            $stmt->execute();
            $stmt->close();
            // The other versions, then the first plan.
            foreach (['DELETE FROM itineraries WHERE version_of = ?', 'DELETE FROM itineraries WHERE itinerary_id = ?'] as $sql) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $itineraryId);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        self::$cache = [];
        return self::done('This version is now your itinerary; the other versions were removed.', ['itinerary_id' => $versionId]);
    }

    /** Remove every version of an itinerary that was not chosen (the review was confirmed, or the itinerary deleted). */
    public static function dropVersions(mysqli $conn, int $itineraryId): int
    {
        if (!self::hasVersions($conn)) return 0;
        $stmt = $conn->prepare('DELETE FROM itineraries WHERE version_of = ?');
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return max(0, (int)$n);
    }

    /** Remove a traveller's versions older than $hours that were never chosen (run when the traveller generates). */
    public static function pruneVersions(mysqli $conn, int $travellerId, int $hours = 24): int
    {
        if (!self::hasVersions($conn)) return 0;
        $stmt = $conn->prepare('DELETE FROM itineraries WHERE traveller_id = ? AND version_of IS NOT NULL AND created_at < (NOW() - INTERVAL ? HOUR)');
        $stmt->bind_param('ii', $travellerId, $hours);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return max(0, (int)$n);
    }

    /**
     * What the comparison of versions shows of one itinerary: its style, per day the stops,
     * the first start and the last end (visits and meals) and the places by name, the total
     * travelling minutes (every stored leg), the stored total against the budget, and how many
     * stops match the traveller's interests (and how many of them an interest loved).
     *
     * @return array{itinerary_id:int,style:?string,days:array,stops:int,travel_min:int,total:float,budget:float,
     *               over_by:float,interest_stops:int,loved_stops:int}|null
     */
    public static function versionSummary(mysqli $conn, int $itineraryId): ?array
    {
        $stmt = $conn->prepare('SELECT total_days, preference_id' . (self::hasVersions($conn) ? ', plan_style' : ', NULL AS plan_style') . ' FROM itineraries WHERE itinerary_id = ? LIMIT 1');
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $meta = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$meta) return null;
        $pref = PlannerRules::loadPreference($conn, (int)$meta['preference_id']) ?? PlannerRules::normalizePreference([]);
        $interests = array_fill_keys((array)$pref['interests'], true);
        $weights = (array)$pref['interest_weights'];
        $meal = PlannerRules::hasColumn($conn, 'itinerary_items', 'meal_slot') ? 'ii.meal_slot' : 'NULL AS meal_slot';
        $stmt = $conn->prepare("SELECT ii.day_no, ii.item_type, {$meal}, ii.place_id, ii.item_title, ii.start_time, ii.end_time, ii.travel_time_min,
                                       LOWER(COALESCE(cp.category, '')) AS category
                                FROM itinerary_items ii LEFT JOIN cultural_places cp ON cp.place_id = ii.place_id
                                WHERE ii.itinerary_id = ? ORDER BY ii.day_no, ii.sequence_no, ii.item_id");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $days = [];
        for ($d = 1; $d <= max(1, (int)$meta['total_days']); $d++) $days[$d] = ['stops' => 0, 'first' => null, 'last' => null, 'places' => []];
        $travel = 0;
        $matching = 0;
        $loved = 0;
        $stops = 0;
        foreach ($rows as $r) {
            $d = (int)$r['day_no'];
            if (!isset($days[$d])) continue;
            $type = strtolower((string)$r['item_type']);
            $travel += (int)($r['travel_time_min'] ?? 0);
            $isMeal = $r['meal_slot'] !== null;
            $isVisit = !$isMeal && in_array($type, ['attraction', 'food', 'festival'], true) && (int)$r['place_id'] > 0;
            if ($isVisit || $isMeal) {
                $start = PlannerRules::minutesOf($r['start_time']);
                $end = PlannerRules::minutesOf($r['end_time']);
                if ($start !== null) $days[$d]['first'] = $days[$d]['first'] === null ? $start : min($days[$d]['first'], $start);
                if ($end !== null) $days[$d]['last'] = $days[$d]['last'] === null ? $end : max($days[$d]['last'], $end);
            }
            if (!$isVisit) continue;
            $days[$d]['stops']++;
            $days[$d]['places'][] = (string)$r['item_title'];
            $stops++;
            if (isset($interests[(string)$r['category']])) {
                $matching++;
                if ((int)($weights[(string)$r['category']] ?? 2) === 3) $loved++;
            }
        }
        $money = self::budgetState($conn, $itineraryId);
        return ['itinerary_id' => $itineraryId, 'style' => $meta['plan_style'] !== null ? (string)$meta['plan_style'] : null, 'days' => $days,
                'stops' => $stops, 'travel_min' => $travel, 'interest_stops' => $matching, 'loved_stops' => $loved] + $money;
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
        // A place type or group (type:<type>, group:<group>): more counts as an interest loved,
        // less loses points and is visited at most once that day (ItineraryDayBuilder).
        $pref = $ctx['pref'];
        $interests = (array)$pref['interests'];
        $weights = (array)$pref['interest_weights'];
        $moreTypes = $lessTypes = [];
        foreach ((array)($options['more'] ?? []) as $category) {
            $category = strtolower(trim((string)$category));
            if (str_starts_with($category, 'type:') || str_starts_with($category, 'group:')) { $moreTypes[] = $category; continue; }
            if (!in_array($category, PlannerRules::VALID_INTERESTS, true)) continue;
            if (!in_array($category, $interests, true)) $interests[] = $category;
            $weights[$category] = 3;
        }
        foreach ((array)($options['less'] ?? []) as $category) {
            $category = strtolower(trim((string)$category));
            if (str_starts_with($category, 'type:') || str_starts_with($category, 'group:')) { $lessTypes[] = $category; continue; }
            if (in_array($category, $interests, true)) $weights[$category] = 1;
        }
        $profile = PlannerRules::profile(['interests' => $interests, 'interest_weights' => $weights,
            'more_types' => $moreTypes, 'less_types' => $lessTypes] + $ctx['profile_input']);
        $profile['meals'] = $ctx['profile']['meals'];
        $profile = self::dayProfile($ctx, $dayNo, $profile);

        $days = self::visits($ctx);
        $musts = array_values(array_filter($days[$dayNo], static fn(array $s): bool => $s['must']));
        $mustIds = array_fill_keys(array_map(static fn(array $s): int => (int)$s['place']['place_id'], $musts), true);
        $start = self::starts($ctx, $days)[$dayNo];
        $date = $ctx['dates'][$dayNo];
        // Rain: one chance for the day (every hour), or 24 by the hour.
        $rain = self::rainOfDay($options['rain'] ?? ($options['forecast'][$date] ?? null));
        $budget = self::budgetOutside($ctx, $dayNo, $days, []);
        $dayCtx = [
            'day_no' => $dayNo,
            'date' => $date,
            'rain' => $rain['rain'],
            'rain_hours' => $rain['rain_hours'],
            'start' => $start['point'],
            'first_leg_exempt' => $start['exempt'],
            'used' => self::usedOutside($ctx, $dayNo, $days, []),
            'trip_items' => $budget['items'],
            'trip_km' => $budget['km'],
            'reserve_km' => 0.0,
            'previous_theme' => self::previousTheme($days, $dayNo),
            'musts' => array_map(static fn(array $s): array => $s['place'], $musts),
        ] + self::dayEnd($ctx, $dayNo);
        // The must-see places of the day win over their twins (one landmark under two names).
        $mustIds = PlannerRules::withTwins($profile, array_keys($mustIds));
        $pool = array_values(array_filter($ctx['pool'], static fn(array $p): bool => !isset($mustIds[(int)$p['place_id']])));
        $builder = new ItineraryDayBuilder($profile, $ctx['route'], $ctx['meal_pool']);
        // The day search with the stored base and group; its next places stand by for settleDay().
        $built = $builder->search($pool, $dayCtx);
        // A must-see place the builder found no slot for on the figures at hand is still tried
        // in every order once the day is measured (settleDay()); only then may it be lost.
        $stops = $built['stops'];
        foreach ($musts as $m) {
            if (!isset($built['unplaced'][(int)$m['place']['place_id']])) continue;
            $stops[] = ['place' => $m['place'], 'point' => $m['point'], 'must' => true, 'tier' => 1, 'reasons' => ['on your must-see list']];
        }
        // change() times the settled day once more on exactly these figures: no request there.
        $opts = ['notes_profile' => $profile, 'measure' => false, 'rain_day' => [$dayNo => ['rain' => $dayCtx['rain'], 'rain_hours' => $dayCtx['rain_hours']]]];
        $settled = self::settleDay($ctx, $days, $dayNo, $stops, $built['spares'], $dayCtx, $profile, $opts);
        if (isset($settled['refused'])) return $settled;
        $newStops = $settled['stops'];
        $keptRows = array_filter(array_map(static fn(array $s): int => (int)($s['item_id'] ?? 0), $newStops));
        $deleted = array_values(array_filter(array_map(static fn(array $s): int => (int)$s['item_id'], $days[$dayNo]),
            static fn(int $id): bool => !in_array($id, $keptRows, true)));
        $days[$dayNo] = $newStops;
        return ['days' => $days, 'changed' => [$dayNo], 'deleted' => $deleted, 'opts' => $opts,
                'message' => 'Day ' . $dayNo . ' was planned again with ' . count($newStops) . ' stop(s).',
                'extra' => ['stops' => count($newStops)]];
    }

    /**
     * Settle a day planned again on the figures at hand, so that the day change() stores keeps
     * every rule on the figures it is stored with:
     *   1. the new day is measured on the road once (its stops in the planned order, the meal
     *      places of that timing, every other pair of its points and the legs to the next day),
     *   2. it is ordered again on those figures, trying every order (orderDay()); a stop no
     *      order can keep gives way,
     *   3. the day search's next places (spares, SETTLE_CANDIDATES, measured together in one
     *      more request while one is left) take the place of the stops that gave way,
     *   4. the legs the final timing needs that are still unknown (meal places) are measured
     *      while a request is left, and the day is settled again on them.
     * Every step is checked with the edit service's own timing (timeDays()) on the figures at
     * hand. The present day stays as it is, with a refusal that names the rule, when the new day
     * would lose a must-see place the present day holds, would have no stop at all, or would
     * make the next day break a rule; the refusal then carries 'worse' (must, empty, next_day).
     *
     * @param array $stops  the search's stops (place, point, must, tier, reasons)
     * @param array $spares the search's next places, best first (ItineraryDayBuilder::search())
     * @return array{stops:array}|array{refused:array} stops: the day in order, as stopOf() makes them
     */
    private static function settleDay(array $ctx, array $days, int $dayNo, array $stops, array $spares, array $dayCtx, array $profile, array $opts): array
    {
        $route = $ctx['route'];
        $start = $dayCtx['start'];
        $end = self::nightPoint($ctx, $dayNo) ?? ($dayNo === $ctx['total_days'] ? $ctx['finish'] : null);
        $rowsByPlace = [];
        foreach ($days[$dayNo] as $s) $rowsByPlace[(int)$s['place']['place_id']] = (int)$s['item_id'];
        $asRows = static function (array $ordered) use ($rowsByPlace): array {
            return array_map(static fn(array $s): array => self::stopOf($s['place'], $rowsByPlace[(int)$s['place']['place_id']] ?? null,
                (int)($s['tier'] ?? 1), (array)($s['reasons'] ?? []), !empty($s['must'])), array_values($ordered));
        };
        $nextDay = $dayNo < $ctx['total_days'] && self::nightPoint($ctx, $dayNo) === null ? $dayNo + 1 : null;
        $nextFirst = $nextDay !== null ? ($days[$nextDay][0]['point'] ?? null) : null;
        // Every leg an order of these stops can use: from the start, between two stops, to the
        // end of the day and to the next day's first stop.
        $pairs = static function (array $points) use ($start, $end, $nextFirst): array {
            $legs = [];
            foreach ($points as $p) {
                $legs[] = [$start, $p];
                foreach ($points as $q) if ($q !== $p) $legs[] = [$p, $q];
                if ($end !== null) $legs[] = [$p, $end];
                if ($nextFirst !== null) $legs[] = [$p, $nextFirst];
            }
            return $legs;
        };
        $mustLost = static function (array $stop, string $reason) use ($dayNo): array {
            return ['refused' => self::refused($reason, 'Day ' . $dayNo . ' was kept as it is: planned again it would lose the must-see place '
                . $stop['place']['name'] . ' (' . PlannerRules::reasonText($reason) . ').', ['day' => $dayNo, 'worse' => 'must'])];
        };
        if ($start === null) return ['refused' => self::refused('LOCKED', 'Day ' . $dayNo . ' has no starting point, so it cannot be planned again.')];

        // 1. The day as planned, timed on the figures at hand, then measured in one request.
        $frame = self::timingFrame($ctx, $dayNo, $days, $opts);
        $ordered = ItineraryRouteOptimiser::orderDay($route, $profile, $start, $stops, $frame);
        $trial = $days;
        $trial[$dayNo] = $asRows($ordered['stops'] ?: $stops);
        $legs = self::unknownLegs($ctx, $trial, self::timeDays($ctx, $trial, [$dayNo], $opts));
        array_push($legs, ...$pairs(array_column($stops, 'point')));
        self::measure($ctx, $legs, 1);

        $wanted = count($stops);
        $given = [];            // place_id => true: stops that gave way and candidates that failed
        $candidatesAsked = false;
        $kept = [];
        for ($round = 0; $round < self::SETTLE_ROUNDS; $round++) {
            // 2. Every order on the figures at hand; a stop no order keeps gives way.
            $ordered = ItineraryRouteOptimiser::orderDay($route, $profile, $start, $stops, $frame);
            foreach ($ordered['dropped'] as $s) {
                if (!empty($s['must'])) return $mustLost($s, (string)$s['reason']);
                $given[(int)$s['place']['place_id']] = true;
            }
            $kept = $ordered['stops'];
            $trial = $days;
            $trial[$dayNo] = $asRows($kept);
            $timed = self::timeDays($ctx, $trial, [$dayNo], $opts);
            if (!$timed['result']['ok']) {
                $failed = (int)($timed['result']['day'] ?? 0);
                $drop = $timed['plans'][$dayNo]['dropped'][0] ?? null;
                if ($failed !== $dayNo || $drop === null) {
                    return ['refused' => self::refused((string)$timed['result']['code'], 'Day ' . $dayNo . ' was kept as it is: planned again, it would make another day break a rule. '
                        . $timed['result']['message'], ['day' => $failed, 'worse' => 'next_day'])];
                }
                // The edit timing and the order disagree about this stop (a meal kept from the
                // present day): it gives way too.
                if (!empty($drop['must'])) return $mustLost($drop, (string)$drop['reason']);
                $given[(int)$drop['place']['place_id']] = true;
                $stops = array_values(array_filter($kept, static fn(array $s): bool => (int)$s['place']['place_id'] !== (int)$drop['place']['place_id']));
                continue;
            }

            // 3. Stops that gave way: the search's next places, as generation repairs a day.
            if (count($kept) < $wanted) {
                $taken = (array)$dayCtx['used'] + $given;
                foreach ($kept as $s) $taken[(int)$s['place']['place_id']] = true;
                $candidates = array_slice(array_values(array_filter($spares, static fn(array $c): bool =>
                    !isset($taken[(int)$c['place']['place_id']]) && PlannerRules::twinIn($profile, (int)$c['place']['place_id'], $taken) === null)), 0, self::SETTLE_CANDIDATES);
                if ($candidates && !$candidatesAsked && self::requestsLeft($ctx) > 0) {
                    $candidatesAsked = true;
                    $legs = [];
                    foreach ($candidates as $c) array_push($legs, ...$pairs(array_merge(array_column($kept, 'point'), [$c['point']])));
                    self::measure($ctx, $legs, 1);
                }
                $filled = false;
                $builder = new ItineraryDayBuilder($profile, $route, $ctx['meal_pool']);
                foreach ($candidates as $c) {
                    // Its notes as this day sees it (category variety after the kept stops).
                    $c = $builder->relevel($c, $dayCtx, $kept);
                    $try = ItineraryRouteOptimiser::orderDay($route, $profile, $start, array_merge($kept, [$c]), $frame);
                    if (!$try['dropped']) {
                        $tryDays = $days;
                        $tryDays[$dayNo] = $asRows($try['stops']);
                        if (self::timeDays($ctx, $tryDays, [$dayNo], $opts)['result']['ok']) {
                            $stops = $try['stops'];
                            $filled = true;
                            break;
                        }
                    }
                    $given[(int)$c['place']['place_id']] = true;
                }
                if ($filled) continue;
                $wanted = count($kept);   // no candidate fits: the day keeps the stops it has
            }

            // 4. Legs of the final timing still unknown (meal places): measured while a request is left.
            if (self::measure($ctx, self::unknownLegs($ctx, $trial, $timed)) > 0) {
                $stops = $kept;
                continue;
            }
            return $kept ? ['stops' => $asRows($kept)] : self::emptyDay($dayNo, $given);
        }
        // Out of rounds: the last day timed above is kept only when it still fits.
        $trial[$dayNo] = $asRows($kept);
        if ($kept && self::timeDays($ctx, $trial, [$dayNo], $opts)['result']['ok']) return ['stops' => $trial[$dayNo]];
        return $kept ? ['refused' => self::refused('NO_CHANGE', 'Day ' . $dayNo . ' was kept as it is: no new plan for it settled on the road figures.', ['day' => $dayNo, 'worse' => 'empty'])]
                     : self::emptyDay($dayNo, $given);
    }

    /** The refusal for a day that, planned again, would have no stop. */
    private static function emptyDay(int $dayNo, array $given): array
    {
        $why = $given ? 'no stop of the new day fits on the road figures' : 'no place fits the day';
        return ['refused' => self::refused('NO_CHANGE', 'Day ' . $dayNo . ' was kept as it is: planned again it would have no stop (' . $why . ').',
            ['day' => $dayNo, 'worse' => 'empty'])];
    }

    /** What a day is ordered and timed with by this service (timeDay()), for orderDay(). */
    private static function timingFrame(array $ctx, int $d, array $days, array $opts, array $plans = []): array
    {
        $start = self::starts($ctx, $days)[$d];
        $night = self::nightPoint($ctx, $d);
        $choice = self::storedMealChoice($ctx, $d);
        foreach ((array)($opts['meal_choice'][$d] ?? []) as $slot => $c) $choice[$slot] = $c;
        return [
            'date' => $ctx['dates'][$d],
            'first_leg_exempt' => $start['exempt'],
            'budget' => ($opts['budget'] ?? true) ? self::budgetOutside($ctx, $d, $days, $plans) + ['reserve_km' => 0.0] : null,
            'end' => $night,
            'night' => self::hasNight($ctx, $d),
            'finish' => $d === $ctx['total_days'] && $night === null ? $ctx['finish'] : null,
            'meal_pool' => $ctx['meal_pool'],
            'used' => self::usedOutside($ctx, $d, $days, $plans),
            'meal_choice' => $choice,
        ];
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
        $oldEnds = $opts['old_ends'] ?? self::ends($ctx);
        $newEnds = self::ends($ctx);
        $toTime = [];
        foreach (array_keys($ctx['days']) as $d) {
            if (in_array($d, $changed, true) || !self::samePoint($oldStarts[$d]['point'], $newStarts[$d]['point'])
                || !self::samePoint($oldEnds[$d] ?? null, $newEnds[$d] ?? null)) $toTime[] = $d;
        }
        $plans = [];
        $tried = [];
        $frame = ['timed' => $toTime, 'starts' => $newStarts];
        foreach ($toTime as $d) {
            if (!$days[$d]) {
                $plans[$d] = $tried[$d] = ['stops' => [], 'dropped' => [], 'minutes' => 0, 'km' => 0.0];
                continue;
            }
            // The traveller's limit on places of worship, on the days whose stops changed.
            $worship = in_array($d, $changed, true) ? self::worshipRefusal($ctx, $days[$d], $d) : null;
            if ($worship !== null) return ['result' => $worship, 'plans' => $tried] + $frame;
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
                foreach (self::plannedMeals($plan) as $meal) {
                    if ($meal['slot'] === $slot && (int)($meal['place']['place_id'] ?? 0) === $wanted) $had = true;
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
            $ends[$d] = self::nightPoint($ctx, $d) ?? ($d === $ctx['total_days'] ? $ctx['finish'] : null);
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
            foreach ((array)($plan['close']['meals'] ?? []) as $meal) {
                if ($meal['point'] === null) continue;
                $add($previous, $meal['point']);
                $previous = $meal['point'];
            }
            $add($previous, $ends[$d] ?? null);
            if (self::hasNight($ctx, $d)) {
                foreach ($days[$d] as $s) $add($s['point'], $ends[$d]);
            }
            if ($d === $ctx['total_days'] && ($ctx['days'][$d]['hotel']['point'] ?? null) === null && $ctx['finish'] !== null) {
                foreach ($days[$d] as $s) $add($s['point'], $ctx['finish']);
            }
        }
        return array_values($legs);
    }

    /** Measure legs on the road within what is left of the operation's requests (at most $cap of them); returns how many became known. */
    private static function measure(array $ctx, array $legs, int $cap = PHP_INT_MAX): int
    {
        $left = min($cap, self::requestsLeft($ctx));
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
        $frame = self::timingFrame($ctx, $d, $days, $opts, $plans);
        $frame['first_leg_exempt'] = $start['exempt'];
        return ItineraryRouteOptimiser::timeInOrder($ctx['route'], $profile, $start['point'], $stops, $frame);
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
            $lastPoint = null;
            $storeMeal = static function (array $meal) use (&$sequence, &$oldMeals, $updateMeal, $insertMeal, $profile, $d, $id): void {
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
            };
            foreach ($dayPlan['stops'] as $stop) {
                foreach ($insertMeal !== null ? (array)$stop['meals'] : [] as $meal) $storeMeal($meal);
                $sequence++;
                $place = $stop['place'];
                $startTime = PlannerRules::sqlTime((int)$stop['start']);
                $endTime = PlannerRules::sqlTime((int)$stop['end']);
                $lastEnd = (int)$stop['end'];
                $lastPoint = $stop['point'];
                // A day planned again in the rain: its rain notes name the hours of the visit as timed now.
                $reasons = $stop['reasons'] !== null && isset($opts['rain_day'][$d])
                    ? ItineraryDayBuilder::rainNotesAt((array)$stop['reasons'], $place, $opts['rain_day'][$d], (int)$stop['start'], (int)$stop['end'])
                    : $stop['reasons'];
                $notes = $reasons !== null
                    ? ItineraryDayBuilder::notes(['place' => $place, 'tier' => $stop['tier'], 'reasons' => $reasons, 'food_meal' => $stop['food_meal']], $notesProfile)
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

            // The dinner near the base: at a food place right after the last visit; a dinner
            // break at the base after the hotel row (the traveller checks in, then eats).
            $close = $dayPlan['close'] ?? null;
            $breakAtBase = [];
            foreach ($insertMeal !== null ? (array)($close['meals'] ?? []) : [] as $meal) {
                if ($meal['place'] !== null) {
                    $storeMeal($meal);
                    $lastEnd = (int)$meal['end'];
                    $lastPoint = $meal['point'];
                } else {
                    $breakAtBase[] = $meal;
                }
            }
            if ($ctx['days'][$d]['hotel'] === null) {
                foreach ($breakAtBase as $meal) $storeMeal($meal);
                $breakAtBase = [];
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
                $noteText = mb_strlen($noteText) <= 1000 ? $noteText : mb_substr($noteText, 0, 997) . '...';
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
                // The hotel row begins when the traveller arrives there (the end of the day as
                // timed: ItineraryDayBuilder::fit() with the base), else on the leg from the last row.
                if ($close !== null) {
                    $checkIn = ItineraryHotelService::checkInMinute((int)$close['arrive']);
                } else {
                    $leg = $lastPoint !== null && $hotel['point'] !== null && $lastEnd !== null
                        ? PlannerRules::peakMinutes((int)$ctx['route']->getSegment($lastPoint[0], $lastPoint[1], $hotel['point'][0], $hotel['point'][1])['travel_time_min'],
                            (string)$ctx['dates'][$d], $lastEnd, $lastPoint, (string)$profile['transport']) : 0;
                    $checkIn = ItineraryHotelService::checkInMinute($lastEnd, $leg);
                }
                $hotelStart = PlannerRules::sqlTime($checkIn);
                $hotelEnd = PlannerRules::sqlTime($checkIn + 30);
                $hotelId = (int)$hotel['item_id'];
                $setSequence->bind_param('issii', $sequence, $hotelStart, $hotelEnd, $hotelId, $id);
                $setSequence->execute();
                foreach ($breakAtBase as $meal) $storeMeal($meal);
            }

            // Meals the day no longer has.
            foreach (array_merge([], ...array_values($oldMeals)) as $mealRow) {
                $drop = $conn->prepare("DELETE FROM itinerary_items WHERE item_id = ? AND itinerary_id = ?");
                $drop->bind_param('ii', $mealRow, $id);
                $drop->execute();
                $drop->close();
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
        // The style the itinerary was planned in (a chosen version): every change keeps it.
        $input = $pref + ['learned' => PlannerRules::learnedPoints($ratings['categories']), 'style' => $meta['plan_style'] ?? null];
        if ($origin !== null) $input += ['origin_lat' => $origin[0], 'origin_lng' => $origin[1]];
        $profile = PlannerRules::profile($input);
        $profile['meals'] = PlannerRules::hasColumn($conn, 'itinerary_items', 'meal_slot');

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
        // The trip's twin groups (one landmark under two names), worked out once from its
        // candidates and its stored places. They go into the profile input too, so a profile
        // made again from it (regenerateDay()) keeps them.
        $loaded = PlannerCandidates::load($conn, $pref);
        $input['twins'] = PlannerCandidates::twinGroups($loaded, array_values($places));
        $profile['twins'] = $input['twins'];
        $mealPool = $profile['meals'] ? PlannerCandidates::mealPlaces($conn, $pref, $profile, $travellerId) : [];

        $totalDays = max(1, (int)$meta['total_days']);
        $bases = array_filter(self::dayBases($conn, $itineraryId), static fn(int $d): bool => $d >= 1 && $d <= $totalDays, ARRAY_FILTER_USE_KEY);
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
            'pool' => PlannerCandidates::editPool($conn, $pref, $profile, $travellerId, $loaded),
            'meal_pool' => $mealPool,
            'meal_ids' => array_fill_keys(array_map(static fn(array $p): int => (int)$p['place_id'], $mealPool), true),
            'places' => $places,
            'origin' => $origin,
            'finish' => self::point($meta['end_lat'] ?? null, $meta['end_lng'] ?? null),
            'total_days' => $totalDays,
            'dates' => $dates,
            'days' => $days,
            'day_starts' => array_filter(self::dayStarts($conn, $itineraryId), static fn(int $d): bool => $d >= 1 && $d <= $totalDays, ARRAY_FILTER_USE_KEY),
            'bases' => $bases,
            // The base rule holds for itineraries planned with bases (migration 20261119_trip_bases.sql).
            'base_rule' => $bases !== [],
        ];
        // The legs stored with the trip are the figures at hand: nothing is asked for them. A
        // change measures only the legs it needs that are not among them (plan()).
        if ($route === null) $ctx['route']->setTimeout(self::ROUTE_TIMEOUT);
        // Public transport: every ride takes the walks between its points and their stops.
        PlannerRules::applyTransitAccess($ctx['route'], $profile, array_merge($loaded, array_values($places)));
        // The holidays the admin keeps (office hours, peak hours) for the years of the trip.
        PlannerRules::loadCalendar($conn, $dates);
        // A stored leg carries its peak-hour factor (PlannerRules::peakMinutes()); the figure at
        // hand is the leg without it (unpeak(), exact), so a re-timing applies it again at the
        // hour the leg then leaves.
        $departs = self::departures($rows, $ctx['day_starts'], (int)$profile['start']);
        $seeds = [];
        $previous = $origin;
        $nights = self::nightBases($bases, $rows, $totalDays);
        $dayOf = null;
        foreach ($rows as $row) {
            $d = (int)$row['day_no'];
            if ($dayOf !== null && $d !== $dayOf) {
                for ($night = $dayOf; $night < $d; $night++) if (isset($nights[$night])) $previous = $nights[$night];
            }
            $dayOf = $d;
            $here = self::point($row['item_latitude'], $row['item_longitude']);
            if ($here === null && isset($places[(int)$row['place_id']])) {
                $here = self::point($places[(int)$row['place_id']]['latitude'], $places[(int)$row['place_id']]['longitude']);
            }
            if ($here === null) continue;
            if ($previous !== null && $row['distance_km'] !== null && $row['travel_time_min'] !== null) {
                $minutes = PlannerRules::unpeak((int)$row['travel_time_min'], $dates[$d] ?? '', $departs[(int)$row['item_id']] ?? (int)$profile['start'], $previous, (string)$profile['transport']);
                $seeds[] = [$previous, $here, (float)$row['distance_km'], $minutes];
            }
            $previous = $here;
        }
        if ($seed) $ctx['route']->seed($seeds);
        return self::$cache[$itineraryId] = $ctx;
    }

    /**
     * The minute each row's leg leaves, for peak hours (PlannerRules::peakMinutes()): the end of
     * the visit, meal or hotel row before it on its day, or the day's start for its first row,
     * exactly as ItineraryDayBuilder::fit() times the leg. Note rows do not count.
     *
     * @param array $rows itinerary rows (item_id, day_no, item_type, end_time) in stored order
     * @return array<int,int> item_id => minutes after midnight
     */
    private static function departures(array $rows, array $dayStarts, int $defaultStart): array
    {
        $out = [];
        $day = null;
        $prevEnd = null;
        foreach ($rows as $row) {
            $d = (int)$row['day_no'];
            if ($d !== $day) { $day = $d; $prevEnd = null; }
            if (strtolower((string)$row['item_type']) === 'note') continue;
            $out[(int)$row['item_id']] = $prevEnd ?? (int)($dayStarts[$d] ?? $defaultStart);
            $end = PlannerRules::minutesOf($row['end_time'] ?? null);
            if ($end !== null) $prevEnd = $end;
        }
        return $out;
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
     * the night before, else at the base of that night, else (an itinerary without bases) at
     * the last stop of the latest day with stops. The first leg is not counted on day 1 and on
     * the day the trip moves to another area (its base differs from the base of the day before).
     *
     * @return array<int,array{point:?array,exempt:bool}>
     */
    private static function starts(array $ctx, array $days): array
    {
        $point = $ctx['origin'];
        $out = [];
        foreach (array_keys($ctx['days']) as $d) {
            $moved = isset($ctx['bases'][$d - 1], $ctx['bases'][$d]) && !self::samePoint($ctx['bases'][$d - 1]['point'], $ctx['bases'][$d]['point']);
            $out[$d] = ['point' => $point, 'exempt' => $d === 1 || $moved];
            $night = self::nightPoint($ctx, $d);
            if ($night !== null) $point = $night;
            elseif ($days[$d]) $point = $days[$d][count($days[$d]) - 1]['point'];
        }
        return $out;
    }

    /**
     * Where the night after a day is spent: its hotel, else its base; null after the last day
     * and, in an itinerary without bases, when no hotel was chosen.
     */
    private static function nightPoint(array $ctx, int $d): ?array
    {
        if ($d >= (int)$ctx['total_days']) return null;
        return $ctx['days'][$d]['hotel']['point'] ?? ($ctx['bases'][$d]['point'] ?? null);
    }

    /** Whether a day ends at its night under the base rule (BASE): planned with bases and followed by a night. */
    private static function hasNight(array $ctx, int $d): bool
    {
        return !empty($ctx['base_rule']) && self::nightPoint($ctx, $d) !== null;
    }

    /** The end point of every day as the base rule reads it: day => point or null. */
    private static function ends(array $ctx): array
    {
        $out = [];
        foreach (array_keys($ctx['days']) as $d) $out[$d] = self::nightPoint($ctx, $d);
        return $out;
    }

    /**
     * The stored base of each day (itinerary_days): day => name, point, sector ([from, to]
     * bearings or null), area_km and note (the day note of migration 20261120_day_notes.sql: the
     * public holiday of the day, or null). Empty without the columns or for an itinerary planned
     * before bases.
     */
    public static function dayBases(mysqli $conn, int $itineraryId): array
    {
        if (!PlannerRules::hasColumn($conn, 'itinerary_days', 'base_lat')) return [];
        $note = PlannerRules::hasColumn($conn, 'itinerary_days', 'day_note') ? 'day_note' : 'NULL AS day_note';
        $stmt = $conn->prepare("SELECT day_no, base_name, base_lat, base_lng, group_from, group_to, area_km, {$note} FROM itinerary_days
                                WHERE itinerary_id = ? AND base_lat IS NOT NULL AND base_lng IS NOT NULL ORDER BY day_no");
        $stmt->bind_param('i', $itineraryId);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $point = self::point($row['base_lat'], $row['base_lng']);
            if ($point === null) continue;
            $out[(int)$row['day_no']] = [
                'name' => (string)$row['base_name'],
                'point' => $point,
                'sector' => $row['group_from'] !== null && $row['group_to'] !== null ? [(int)$row['group_from'], (int)$row['group_to']] : null,
                'area_km' => $row['area_km'] !== null ? (float)$row['area_km'] : null,
                'note' => $row['day_note'] !== null && trim((string)$row['day_note']) !== '' ? (string)$row['day_note'] : null,
            ];
        }
        $stmt->close();
        return $out;
    }

    /** night => the base point of each night that has no hotel row (where the next day's first leg starts). */
    private static function nightBases(array $bases, array $rows, int $totalDays): array
    {
        $hotels = [];
        foreach ($rows as $row) if (strtolower((string)$row['item_type']) === 'hotel') $hotels[(int)$row['day_no']] = true;
        $out = [];
        foreach ($bases as $d => $base) if ($d < $totalDays && !isset($hotels[$d])) $out[$d] = $base['point'];
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
                foreach (self::plannedMeals($plans[$d]) as $meal) if ($meal['place'] !== null) $used[(int)$meal['place']['place_id']] = true;
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

    /** Why a place cannot come into the trip (null: it may): missing, avoided (or the same landmark as an avoided place), already used (or its twin is), or a rule of the place itself. */
    private static function placeRefusal(array $ctx, ?array $place, int $placeId, ?int $exceptItem): ?array
    {
        if ($place === null) return self::refused('UNAVAILABLE', 'This place is not an active place with map coordinates.');
        $name = (string)$place['name'];
        if (in_array($placeId, (array)$ctx['pref']['avoid_places'], true)) return self::refused('AVOID', $name . ' is on your list of places to avoid.');
        $avoided = PlannerRules::twinIn($ctx['profile'], $placeId, array_flip((array)$ctx['pref']['avoid_places']));
        if ($avoided !== null) {
            return self::refused('AVOID', $name . ' is the same landmark as ' . PlannerRules::twinName($ctx['profile'], $placeId, $avoided) . ', which is on your list of places to avoid.');
        }
        $used = self::usedPlaces($ctx, $exceptItem);
        if (isset($used[$placeId])) return self::refused('DUPLICATE', $name . ' is already in this itinerary.');
        $twin = PlannerRules::twinIn($ctx['profile'], $placeId, $used);
        if ($twin !== null) {
            return self::refused('SAME_LANDMARK', $name . ' is the same landmark as ' . PlannerRules::twinName($ctx['profile'], $placeId, $twin) . ', which is already in this itinerary.');
        }
        $reason = PlannerRules::placeReason($place, $ctx['profile']);
        if ($reason !== null) return self::refused($reason, $name . ' cannot be used: ' . PlannerRules::reasonText($reason) . '.');
        // Not for a must-see place: one no public transport stop reaches (for a traveller who
        // uses it), and a place of worship for a traveller who wants none.
        if (!self::isMust($ctx, $placeId)) {
            $reason = PlannerRules::transitReason($place, $ctx['profile']) ?? PlannerRules::worshipReason($place, $ctx['profile']);
            if ($reason !== null) return self::refused($reason, $name . ' cannot be used: ' . PlannerRules::reasonText($reason) . '.');
        }
        return null;
    }

    /**
     * WORSHIP when a change puts more places of worship that are not must-see places into a day
     * than it had, and the day then holds more than the traveller's limit (worship_limit); null
     * otherwise. A must-see place is always kept.
     */
    private static function worshipRefusal(array $ctx, array $stops, int $d): ?array
    {
        $cap = $ctx['profile']['worship_cap'] ?? null;
        if ($cap === null) return null;
        $count = static fn(array $list, bool $freeOnly): int => count(array_filter($list, static fn(array $s): bool =>
            PlannerRules::isWorship($s['place']) && (!$freeOnly || empty($s['must']))));
        $before = $count($ctx['days'][$d]['visits'] ?? [], true);
        if ($count($stops, true) <= $before || $count($stops, false) <= (int)$cap) return null;
        return self::refused('WORSHIP', 'Day ' . $d . ' would have more places of worship than you asked for (' . ((int)$cap === 0 ? 'none' : 'at most ' . (int)$cap . ' a day') . ').', ['day' => $d]);
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
        ] + self::dayEnd($ctx, $dayNo);
        $profile = self::dayProfile($ctx, $dayNo);
        $state = ItineraryDayBuilder::startState($dayCtx, $profile);
        $prefix = array_values(array_filter(array_slice($stops, 0, $index)));
        if ($prefix && $start['point'] !== null) {
            $timed = self::timeDay($ctx, $profile, $dayNo, $start, $prefix, $days, [], ['budget' => false]);
            foreach ($timed['stops'] as $s) $state = ItineraryDayBuilder::advance($state, $s, $profile);
        }
        return [$state, $dayCtx];
    }

    /**
     * Where a day must end, as the day builder reads it: finish (the night's hotel or base under
     * the base rule, else the trip's end point on the last day), finish_code, night; and the
     * day's base and group (the places of its sector around the base, TripLayout) for the
     * search.
     */
    private static function dayEnd(array $ctx, int $d): array
    {
        $night = self::hasNight($ctx, $d);
        $nightPoint = self::nightPoint($ctx, $d);
        $finish = $night ? $nightPoint : ($d === $ctx['total_days'] && $nightPoint === null ? $ctx['finish'] : null);
        return [
            'finish' => $finish,
            'finish_code' => $night ? 'BASE' : 'FINISH',
            'night' => $night,
            'base' => $ctx['bases'][$d]['point'] ?? null,
            'group' => self::groupOf($ctx, $d),
        ];
    }

    /**
     * The stored group of a day: the places of the edit pool within its area around its base
     * and, when the day has a sector, inside that sector (bearings from, to: clockwise). Empty
     * for a day without a base: then every place counts as its own.
     *
     * @return array<int,true> place_id => true
     */
    private static function groupOf(array $ctx, int $d): array
    {
        $base = $ctx['bases'][$d] ?? null;
        if ($base === null) return [];
        $radius = (float)($base['area_km'] ?? 0);
        $out = [];
        foreach ($ctx['pool'] as $place) {
            $point = [(float)$place['latitude'], (float)$place['longitude']];
            if ($radius > 0 && RouteService::haversineKm($base['point'][0], $base['point'][1], $point[0], $point[1]) > $radius) continue;
            if ($base['sector'] !== null) {
                [$from, $to] = $base['sector'];
                $b = TripLayout::bearing($base['point'], $point);
                $inside = $from <= $to ? ($b >= $from && $b <= $to) : ($b >= $from || $b <= $to);
                if (!$inside) continue;
            }
            $out[(int)$place['place_id']] = true;
        }
        return $out;
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
        $reasons = (array)$ranked[0]['reasons'];
        // Category variety is judged on the whole day, also the stops after the new one: when
        // they already hold the category's limit, the new stop says that variety was relaxed.
        $category = strtolower((string)$place['category']);
        $same = 0;
        foreach ($stops as $i => $s) {
            if ($i !== $index && is_array($s) && isset($s['place']) && strtolower((string)$s['place']['category']) === $category) $same++;
        }
        if (!$ctx['profile']['food_only'] && $same >= PlannerRules::categoryLimit($ctx['profile'], $category)
            && !array_intersect($reasons, ItineraryDayBuilder::RELAXED)) {
            array_unshift($reasons, ItineraryDayBuilder::RELAXED[1]);
        }
        // Type variety the same way: the day's other stops already hold the type's limit (or two places of worship).
        $others = array_values(array_filter($stops, static fn($s, $i): bool => $i !== $index && is_array($s) && isset($s['place']), ARRAY_FILTER_USE_BOTH));
        if (self::breaksTypeVariety($ctx, $place, $others) && !in_array(ItineraryDayBuilder::RELAXED[2], $reasons, true)) {
            array_unshift($reasons, ItineraryDayBuilder::RELAXED[2]);
        }
        return ['tier' => (int)$ranked[0]['tier'], 'reasons' => $reasons];
    }

    /** Whether a place breaks type variety in a day whose other stops are $others (PlannerRules::breaksTypeVariety()). */
    private static function breaksTypeVariety(array $ctx, array $place, array $others): bool
    {
        $types = $groups = [];
        foreach ($others as $s) {
            $type = PlannerRules::typeOf($s['place']);
            if ($type === null) continue;
            $types[$type] = ($types[$type] ?? 0) + 1;
            $group = PlaceTypeClassifier::groupOf($type);
            $groups[$group] = ($groups[$group] ?? 0) + 1;
        }
        return PlannerRules::breaksTypeVariety($place, $types, $groups, $ctx['profile']);
    }

    /**
     * A stop moved into a day whose other stops already hold its category's limit says that
     * category variety was relaxed (its notes stay true).
     */
    private static function varietyNote(array $ctx, array $stop, array $others): array
    {
        // Place type variety (two of a type, two places of worship) first, then category variety.
        if (self::breaksTypeVariety($ctx, $stop['place'], $others)) {
            $note = ItineraryDayBuilder::RELAXED[2];
            if ($stop['reasons'] !== null) {
                if (!in_array($note, (array)$stop['reasons'], true)) array_unshift($stop['reasons'], $note);
            } elseif (isset($stop['row']['notes']) && !str_contains((string)$stop['row']['notes'], $note)) {
                $notes = (string)$stop['row']['notes'];
                $at = strpos($notes, 'Reason: ');
                $stop['row']['notes'] = $at === false ? $notes . ' | Reason: ' . $note : substr($notes, 0, $at + 8) . $note . '; ' . substr($notes, $at + 8);
            }
        }
        $category = strtolower((string)$stop['place']['category']);
        $same = count(array_filter($others, static fn(array $s): bool => strtolower((string)$s['place']['category']) === $category));
        if ($ctx['profile']['food_only'] || $same < PlannerRules::categoryLimit($ctx['profile'], $category)) return $stop;
        $note = ItineraryDayBuilder::RELAXED[1];
        if ($stop['reasons'] !== null) {
            if (!array_intersect((array)$stop['reasons'], ItineraryDayBuilder::RELAXED)) array_unshift($stop['reasons'], $note);
        } elseif (isset($stop['row']['notes']) && !str_contains((string)$stop['row']['notes'], 'relaxed to fill the day')) {
            $notes = (string)$stop['row']['notes'];
            $at = strpos($notes, 'Reason: ');
            $stop['row']['notes'] = $at === false ? $notes . ' | Reason: ' . $note : substr($notes, 0, $at + 8) . $note . '; ' . substr($notes, $at + 8);
        }
        return $stop;
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
        $night = self::nightPoint($ctx, $dayNo);
        if (!$near || ($night !== null && $slot === 'dinner' && self::hasNight($ctx, $dayNo))) {
            if ($night !== null) $near[] = $night;
        }
        $radius = (float)$ctx['profile']['meal_radius_km'];
        $days = self::visits($ctx);
        $ctx['budget_from'] = $ctx['route']->requestsMade();
        // Every food place near the meal on the figures at hand; the least detours of them
        // (as many as the page shows, and a few more) measured on the road together.
        $screened = [];
        foreach ($ctx['meal_pool'] as $place) {
            $id = (int)$place['place_id'];
            if ($id === $current || isset($used[$id]) || PlannerRules::twinIn($ctx['profile'], $id, $used) !== null) continue;
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
                'opening_hours' => OpeningHours::shown($place), 'cost' => 0.0, 'rating' => (float)max((float)($place['avg_rating'] ?? 0), (float)($place['rating'] ?? 0)),
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
        foreach (self::plannedMeals($plan) as $meal) if ($meal['slot'] === $slot) return $meal;
        return ['start' => 0, 'end' => 0, 'detour' => 0.0];
    }

    /** Every meal of a timed day: those before its stops and the dinner near the base. */
    private static function plannedMeals(array $plan): array
    {
        $meals = [];
        foreach ($plan['stops'] as $s) foreach ((array)($s['meals'] ?? []) as $meal) $meals[] = $meal;
        foreach ((array)($plan['close']['meals'] ?? []) as $meal) $meals[] = $meal;
        return $meals;
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
            'opening_hours' => OpeningHours::shown($place),
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
        return mb_strlen($notes) <= 1000 ? $notes : mb_substr($notes, 0, 997) . '...';
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
