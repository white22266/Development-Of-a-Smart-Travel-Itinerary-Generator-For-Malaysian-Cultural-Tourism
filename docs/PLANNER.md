# How a plan is made

For a developer. Every number below is read from the code, and the constant that holds it is
named so the number can be found again. If a constant and this page disagree, the constant is
right: fix the page. Open data sources and the place sync are in `docs/OPEN_DATA.md`.

Itineraries are made by rules, never by an AI model. The AI features only explain, suggest, or
turn an edit request into a call of the edit service.

## The pieces

| File | Job |
|---|---|
| `services/PlannerRules.php` | Every rule once: loads and normalises a preference, builds the profile, the one feasibility check `check()` with reason codes, the budget rule, learning from ratings |
| `services/PlannerCandidates.php` | The one query for usable places, the traveller's ratings, the meal pool, the pool for edits |
| `services/ItineraryDayBuilder.php` | Fills one day: scores candidates, relaxes soft preferences, places meals, times a day (`fit()`) |
| `services/ItineraryRouteOptimiser.php` | Finds the order of a day's stops with the least travelling that still keeps every rule |
| `services/ItineraryGenerator.php` | The single entry point `generate()`; one transaction; stores the rows |
| `services/ItineraryEditService.php` | The one place that changes a stored itinerary |

Entry points: `itinerary/generate_itinerary.php` calls `ItineraryGenerator::generate()`.
`api/itinerary_edit.php`, `api/itinerary_optimise.php`, `api/ai_itinerary_editor.php`,
`api/ai_hotel_assistant.php`, `itinerary/itinerary_review.php`, `itinerary/review_replace.php`,
`itinerary/review_confirm_nightly.php` and `itinerary/itinerary_view.php` call
`ItineraryEditService`.

## Flow of `generate()`

1. `PlannerRules::loadPreference()` reads the saved preference with its interest weights and its
   must-see and avoid places. `PlannerRules::profile()` turns it into the planning profile. The
   traveller's own ratings add a small adjustment per category (see Learning).
2. `PlannerCandidates::load()` loads the active places that have coordinates, inside the chosen
   states when states were chosen. A festival is a candidate only when both its dates are known
   and in order (`VALID_FESTIVAL_SQL`). Places on the avoid list, and places the traveller rated
   `LEARN_DROP_AT` or lower, are removed. The place rules that do not depend on the time slot
   (`PlannerRules::placeReason()`) narrow the pool once.
3. Must-see places get their days first (see Must-see and avoid).
4. Day by day, `ItineraryDayBuilder::build()` fills the day and
   `ItineraryRouteOptimiser::orderDay()` orders it, using estimated travel figures.
5. When a routing server answers, the whole trip is measured once on the road and every day is
   ordered and timed again. A stop that no order can keep is dropped, and one feasible
   replacement is tried.
6. Everything is stored in one transaction and the total is written by
   `ItineraryEditService::total()`. A failure saves nothing.

## Hard rules

A hard rule is never relaxed. `PlannerRules::check()` is the only place that decides whether a
place can be visited in a slot. It returns the visit's start and end, or a reason code (the list
is `PlannerRules::REASONS`).

| Rule | Value | Constant |
|---|---|---|
| Stops per day | relaxed 3, normal 4, packed 5; the traveller's "stops per day" replaces it, at most 8 | `PACE_TARGET`, `normalizePreference()` |
| Rest between two visits | relaxed 90, normal 60, packed 30 minutes; +15 for a careful traveller, +10 for a group | `PACE_REST_MIN`, `REST_EXTRA_CAREFUL`, `REST_EXTRA_GROUP` |
| Day window by preferred visit time | morning 08:00-19:00, afternoon 12:00-21:30, evening 15:00-22:30, any 08:30-21:00 | `VISIT_WINDOW` |
| Latest end for a careful traveller (family, children, elderly, low walking) | 20:00; an explicit end time lifts it | `CAREFUL_LATEST_END` |
| Explicit start and/or end time | each replaces its side of the window; if only one side is given and the window is under 240 min, the other side moves to make it 480 min, kept within 06:00-23:00 | `SHORT_WINDOW_MIN`, `WIDENED_WINDOW_MIN`, `EARLIEST_START`, `LATEST_END` |
| Recommended km per day | walking 3/5/7, public transport 15/25/35, car and motorcycle 30/45/60 (relaxed/normal/packed); times 0.75 for low walking, wheelchair or elderly | `DAILY_DISTANCE_KM`, `CAREFUL_DISTANCE_FACTOR` |
| Hard km per day | max(3 x recommended, recommended + 15) | `profile()['hard_km']` |
| Longest single leg | walking 8, public transport 60, car and motorcycle 150 km | `LEG_LIMIT_KM` |
| Leg from the starting point on day 1 | not counted by the distance limits | `leg_counts` in `check()` |
| Visit length | 30 to 240 min, 90 when unknown; at most 120 for a family, 105 for elderly or low walking | `VISIT_MIN`, `VISIT_MAX`, `VISIT_DEFAULT`, `VISIT_MAX_FAMILY`, `VISIT_MAX_CAREFUL` |
| Opening hours | the whole visit inside the opening hours of that weekday; reasons `CLOSED`, `OPEN_HOURS`, `DAY_END` | `OpeningHours::earliestStart()` |
| Waiting for opening | at most 60 min; time spent on a meal is not waiting | `MAX_WAIT_MIN` |
| Avoid heat | an outdoor visit must be over by 11:00 or begin after 16:00 | `HEAT_FROM` (660), `HEAT_UNTIL` (960) |
| Elderly | no outdoor visit longer than 150 min | `ELDERLY_LONG_VISIT_MIN` |
| Step-free | for the wheelchair, avoid-stairs and step-free tags, a place whose name, description or category matches the pattern is refused | `STEP_FREE_BLOCKED` |
| Dietary | a food place for halal needs `halal_status` = 1; for vegetarian its name or description must say vegetarian or vegan | `placeReason()` |
| Festival | visited only on a day inside its dates | `festivalDates()`, `FESTIVAL_DAY` |
| States | when states were chosen, a place must be in one of them | `STATE` |
| Peninsular or East Malaysia | with no state chosen, a place must be in the same region as the starting point (Sabah, Sarawak and Labuan are east; for a point without a state, longitude 108 and above is east) | `EAST_STATES`, `EAST_FROM_LNG` |
| End point | on the last day each stop must leave time and distance to reach it | reason `FINISH` |
| Budget | see below | `BUDGET_MARGIN` |
| No duplicates | a place appears once in a trip | reason `DUPLICATE` |

How the accessibility tags are decided: the preference form stores tick boxes in the
`accessibility_tags` column. Without a stored value, the tags are taken from the free text in
`accessibility_needs` by keywords (`AccessibilityNeedsAnalysisService::storedTags()` and
`keywordTags()`). A child in the party gives the family rules; a senior gives the rules of the
elderly tag. There is no surveyed access data: access is decided from these tags and from
keywords in a place's name and description.

### Budget rule

`PlannerRules::budgetAllows()`. The essentials are meals (three a day, `MEALS_PER_DAY`), the
hotel and transport, priced from the budget tier (`CostEstimationService::budgetTierDefaults()`).
Paid places may take what the budget leaves after the essentials plus a margin of
`BUDGET_MARGIN` (10 percent) on them. When the essentials alone are over the budget, only free
places are added. A budget of 0 means no limit. The rule only grows stricter as items are
added, so a plan that keeps it keeps it in any order of its stops. `projectedTotal()` computes
the total exactly as `ItineraryEditService::total()` stores it.

## Soft preferences and scoring

`ItineraryDayBuilder::score()` scores the candidates, and they are tried best first; one that
breaks `check()` is passed over for the next. The score is deterministic. Its terms:

- Interest match by weight 1, 2, 3 (a little, like, love) = 15, 25, 35 points
  (`INTEREST_POINTS`). An interest with no weight counts as 2.
- Preferred district: +12.
- Learned adjustment (below).
- Suitability for the traveller type (up to +10), fit with the preferred visit time (+8 or +6).
- A free place +10; the place's rating up to +8.
- Variety: +10 for a category not yet used that day; -12 for repeating a category beyond its
  limit; -15 for repeating the previous day's main theme. The limit per category and day is
  the interest weight (`PlannerRules::categoryLimit()`, 2 for a category with no weight). A trip
  of food only is exempt.
- Nearness: up to +14 for a short leg; a leg longer than the daily distance divided by the
  target costs up to 25 points.
- Waiting: minus the waiting minutes divided by 6.

### Relaxation levels

When no candidate is left for the next stop, soft preferences are relaxed one level at a time
(`ItineraryDayBuilder::RELAXED`). Hard rules are never relaxed. A stop chosen at a relaxed level
says so in its notes.

| Level | Relaxed |
|---|---|
| 0 | nothing |
| 1 | category variety |
| 2 | and preferred districts |
| 3 | and interest match |
| 4 | and the recommended daily distance (never the hard limit) |

A day may end up short of its target. A day for which nothing is feasible is stored with one
note row titled `ItineraryGenerator::FREE_DAY_TITLE`; generation still succeeds.

### Learning from the traveller's ratings

`PlannerRules::learnedPoints()`: per category, (average rating - 3) / 2 x `LEARN_MAX_POINTS`
(4.0), scaled by the number of ratings up to `LEARN_FULL_AT` (5). It lies within plus or minus 4
points, less than half of the 10 points between two interest weights, and it never touches
`check()`. A place rated `LEARN_DROP_AT` (2) or lower is not suggested again. Ratings are read
from `ratings_reviews` by `PlannerCandidates::travellerRatings()`.

### Weather

The chance of rain is asked once per trip for the starting point
(`WeatherService::rainChances()`), and only for dates inside the forecast horizon. It is not
asked when `ROUTING_ESTIMATES_ONLY` is set. Without a forecast nothing changes. Weather is a soft
preference only.

| Chance of rain | Effect | Constant |
|---|---|---|
| 60 percent or more | outdoor places lose 15 points, indoor places gain 8 | `RAIN_PREFER`, `RAIN_OUTDOOR_POINTS`, `RAIN_INDOOR_POINTS` |
| 80 percent or more | outdoor places are tried only when no indoor place of the same relaxation level is feasible | `RAIN_INDOOR_FIRST` |

A place counts as outdoor when `is_outdoor` = 1 or its category is nature
(`PlannerRules::isOutdoor()`). Must-see places are not changed by the weather.

## Meals

`PlannerRules::MEAL_WINDOWS`: lunch may start 11:30-14:00, dinner 18:00-20:30. A meal lasts
`MEAL_MIN` of the pace: relaxed 75, normal 60, packed 45 minutes. A day gets a meal when one of
its visits ends at or before the latest start of the window and a later visit ends after it.
The meal is taken between two visits, at a food place within `MEAL_RADIUS_KM` road km of the
stop before or after it (walking 1, public transport 2, car and motorcycle 5), choosing the
least detour, or as a break without a place. A food-place visit that starts inside a meal
window is that meal. The meal stands in for the rest between the two visits.

Meals are stored as `itinerary_items` rows with `item_type` = food and `meal_slot` = lunch or
dinner, at cost 0.00, because the cost model already reserves three meals a day. Meal places
come only from `cultural_places` with category food; the table `food_places` is not used.

## Must-see and avoid

- Avoid: a place on the traveller's avoid list is never a candidate, and an edit that brings it
  in is refused with `AVOID`.
- Must-see: `ItineraryGenerator` gives each must-see place a day where it can be visited under
  every hard rule, probing the day window every `MUST_PROBE_STEP_MIN` (60) minutes. Places near
  each other go on the same day, and all are counted in the budget before any other stop. The
  day builder tries them before any other stop with every soft preference relaxed and every hard
  rule kept, and keeps room in the day for the ones still to come. A must-see place that cannot
  be scheduled is stored as a note row ("Must-see place not scheduled") with the reason.

## End point

An optional end point (name and coordinates) from the starting-point page
(`itinerary/select_preference.php`) is stored with the itinerary. On the last day the order
finishes towards it, and every stop must leave time before the end of the day and distance
within the hard daily limit to reach it; otherwise the reason is `FINISH`.

## Notes

Each stop's `notes` holds a line starting `Reason:` with the reasons the planner had (interest
match, relaxation level, rating, waiting and so on). Note rows (`item_type` = note) carry the
free-day note and the must-see notes; they have no place, no coordinates and no cost, and their
text is cut to 255 characters.

## What the optimiser does

`ItineraryRouteOptimiser::orderDay()` compares every order of a day's stops up to
`MAX_EXACT_STOPS` (8); beyond that it checks up to `ORDERS_TO_CHECK` (720) good orders from
`RouteService`. Only orders that keep every rule of `PlannerRules` are used, each timed with the
day's lunch and dinner. Among them the one with the least travelling wins; among orders that
cannot keep every stop, the one keeping the most must-see places wins. A stop no order can keep
is dropped with its reason code. A time that breaks a rule is never stored and never clamped.
`optimise()` does the same for a saved itinerary (the "Optimise route" button); a day then ends
at its hotel when one was chosen.

## Edit operations

`ItineraryEditService` is the one place that changes a stored itinerary. Each operation runs in
one transaction, is judged by `PlannerRules`, and re-times the days it touches with the
generator's own timing. A change that would break a hard rule is refused and leaves every row as
it was. A day may have a stored start time (table `itinerary_days`, migration
`20261117_itinerary_days.sql`) that every re-timing of that day keeps. Every operation returns
`ok`, `status` (`done` or `refused`), `code`, `message` and the re-timed `days`.

| Operation | Action in `api/itinerary_edit.php` |
|---|---|
| `suggestReplacements()` (`SUGGEST_LIMIT` = 3 options by default, deterministic, `offset` for more) | `suggest` |
| `replaceStop()`, `canReplace()` | `replace` |
| `removeStop()` | `remove` |
| `addStop()`, `canAdd()` | `add` |
| `moveStop()` | `move` |
| `regenerateDay()` (more of one category, less of another, optional rain) | `regenerate_day` |
| `setDayStart()`, `retimeTrip()` | `set_day_start` |
| `optimise()` | `optimise` |
| `replanRainyDays()` (days at `RAIN_PREFER` percent or more) | `replan_rain` |
| `setHotels()` (a night whose hotel would break the next day is not saved) | the hotel pages and the hotel assistant |

Refusal codes that the edit service itself returns (the words are in `PlannerRules::REASONS`; the
rule codes of `check()` can be returned too):

| Code | Meaning |
|---|---|
| `NOT_FOUND` | the itinerary, stop or day does not exist |
| `NOT_EDITABLE` | the row cannot be changed this way (for example a meal cannot be removed) |
| `LOCKED` | the day holds a row the planner cannot re-time |
| `LAST_STOP` | the last visit of a day cannot be removed unless an empty day is allowed |
| `DAY_FULL` | the day already has its planned number of stops |
| `NO_CHANGE` | nothing would change |
| `AVOID` | the place is on the avoid list |
| `DUPLICATE` | the place is already in the trip |
| `UNAVAILABLE` | not an active place with coordinates, or day starts cannot be stored yet (migration missing) |
| `EARLY_START` | a day cannot start before 06:00 (`PlannerRules::EARLIEST_START`) |
| `DAY_END` | the chosen day start leaves no time before the end of the day |
| `MEAL` | the chosen meal does not fit |
| `DIETARY` | the chosen meal place is not among the food places the trip may use |

The AI assistant (`services/ItineraryEditIntentService.php`) reads an edit request in English,
Malay or Chinese with the local Ollama model and falls back to regular expressions. It only
produces a call of one of the operations above; the rules still decide.

## What is an estimate

Travel figures are estimates while places are chosen: straight line times
`RouteService::ROAD_FACTOR` (1.3) at the average speeds of `SPEED_MAP` (car 60, motorcycle 55,
public transport 30, walking 5 km/h). The stored itinerary uses road figures from OSRM or
Valhalla when they answer. With `ROUTING_ESTIMATES_ONLY=1`, or when the server does not answer,
the estimates are stored (`method` is `estimate`). Hotel prices are estimates, and entrance fees
and visit lengths of imported places are typical values. `docs/OPEN_DATA.md` has the details.

## The planner check: `tools/check_planner.php`

It saves fixed preferences for a test traveller (`TEST_EMAIL`), generates an itinerary from each
exactly as the generate page does, reads the stored result back and judges it by rules written
out again inside the tool. It never calls the planner's rule functions to decide what is
right, so it stays a fair judge. It refuses to run on a database whose name does not end in
`_ctest` (exit code 2).

```
DB_NAME=travel_itinerary_db_ctest /c/xampp/php/php.exe tools/check_planner.php
```

Options: `--scenario=<name>` runs one scenario, `--keep` keeps the generated itineraries,
`--json` gives machine-readable output, `--road` allows real routing (the public servers).
Without `--road` it sets `ROUTING_ESTIMATES_ONLY=1` and makes no request. Exit code 0 means no
violation, 1 at least one violation. A warning does not fail the run.

The scenarios are the entries of the `SCENARIOS` constant, plus `<base>__edits` for each name in
`EDIT_BASES`: the base is generated, then changed through `ItineraryEditService` (replace,
remove, move, add, plan a day again, a day start of its own, a hotel, a rainy day, optimise), the
checks run again, and changes that must be refused are tried.

Finding codes. A violation fails the run; a warning is only reported.

| Code | Meaning |
|---|---|
| `GENERATE`, `SETUP` | generation failed or stored nothing; the scenario's setup failed |
| `DAYS`, `STOPS` | a day without a visit or a stop outside the trip's days; more stops than the target (violation) or fewer (warning) |
| `DUPLICATE` | a place twice in a trip |
| `START`, `HARD_END`, `TIME_ORDER` | a visit before the day start or after the latest end; missing or reversed times; a gap shorter than the travel time |
| `REST` | the rest between visits is shorter than planned (warning) |
| `WAIT`, `DURATION`, `OPEN_HOURS` | waiting over 60 min; a visit shorter than 30 min or longer than allowed; a visit outside opening hours |
| `ACCESS` | the heat, step-free or elderly rule broken (a visit that only runs into the heat window is a warning) |
| `DIETARY`, `FESTIVAL`, `REGION` | the dietary, festival date, state or region rule broken |
| `LEGS`, `DISTANCE` | a stored distance or time that does not fit the straight line or the speed; a leg or a day over its limit (a day over the recommended km is a warning) |
| `CATEGORY` | too many visits of one category without a note that variety was relaxed |
| `MEAL` | a meal row or a missing meal is wrong (window, length, item type, cost, reason, place, radius) |
| `WEATHER`, `RAIN` | an outdoor stop on a day at 80 percent rain or more (warning); after planning a rainy day again, an outdoor stop stayed while indoor places were left |
| `AVOID`, `RATED`, `MUST` | an avoided or low-rated place in the trip; a must-see place neither planned nor named in a note |
| `LEARN`, `WEIGHTS`, `WINDOW` | warnings: ratings not reflected, interest weights not reflected, a widened window not used |
| `FINISH` | the end point is not stored, or cannot be reached in time or distance |
| `BUDGET` | the stored total differs from the total recomputed from the items, or paid attractions exceed their share; a total over the budget is a warning |
| `EDIT`, `REFUSE`, `DAY_START`, `OPTIMISE` | an edit result is malformed or broke the stop's position (a refused edit is a warning); a change that had to be refused was made or changed rows; a stored day start was lost; the optimiser step reported a note (warning) |

To extend it: add an entry to `SCENARIOS` (a `pref` and a `trip`, with optional `weights`, `must`, `avoid` and `ratings` keys,
the `trip` keys `end_name`, `end_lat` and `end_lng` for an end point, and `forecast` for a rain
override; copy an existing
entry), and add its name to `EDIT_BASES` for an edit run. When a rule changes in
`PlannerRules`, change the matching constant at the top of the tool and the check in
`checkItinerary()` in the same commit. A new finding is one `$add('CODE', 'violation'|'warning',
...)` call there.
