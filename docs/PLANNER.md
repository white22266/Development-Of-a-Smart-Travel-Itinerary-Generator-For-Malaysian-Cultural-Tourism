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
| `services/TripLayout.php` | Lays a trip out: where each night is spent (bases), which area each day tours, the group of places each day draws from first |
| `services/ItineraryDayBuilder.php` | Finds the best day (`search()`), scores and values candidates, places meals, times a day and its end (`fit()`) |
| `services/ItineraryRouteOptimiser.php` | Finds the order of a day's stops with the least travelling that still keeps every rule; `optimise()` reorders a stored trip |
| `services/ItineraryGenerator.php` | The single entry point `generate()`; one transaction; stores the rows |
| `services/ItineraryEditService.php` | The one place that changes a stored itinerary |

Entry points: `itinerary/generate_itinerary.php` calls `ItineraryGenerator::generate()`.
`api/itinerary_edit.php`, `api/itinerary_optimise.php`, `api/ai_itinerary_editor.php`,
`api/ai_hotel_assistant.php`, `itinerary/itinerary_review.php`, `itinerary/review_replace.php`,
`itinerary/review_confirm_nightly.php` and `itinerary/itinerary_view.php` call
`ItineraryEditService`. `api/itinerary_versions.php` calls `ItineraryGenerator::createVersion()`
(a version of a plan in another style, see Versions of a plan) and
`ItineraryEditService::chooseVersion()`.

## Flow of `generate()`

1. `PlannerRules::loadPreference()` reads the saved preference with its interest weights and its
   must-see and avoid places. `PlannerRules::profile()` turns it into the planning profile. The
   traveller's own ratings add a small adjustment per category (see Learning).
2. `PlannerCandidates::load()` loads the active places that have coordinates, inside the chosen
   states when states were chosen. A festival is a candidate only when both its dates are known
   and in order (`VALID_FESTIVAL_SQL`). Places on the avoid list, and places the traveller rated
   `LEARN_DROP_AT` or lower, are removed. The place rules that do not depend on the time slot
   (`PlannerRules::placeReason()`) narrow the pool once, and so do the rules of a place's kind
   (`PlannerCandidates::usable()`): for a traveller by public transport a place no stop reaches
   (`TRANSIT`), for a traveller who wants no place of worship a place of worship (`WORSHIP`).
   Neither applies to a must-see place.
3. Must-see places: the days each can be visited on, and the budget (see Must-see and avoid).
4. `TripLayout::plan()` lays the trip out (see Trip layout): the base of every night, the area of
   every day and the group of places each day draws from first. A must-see place goes to the day
   of its group.
5. Day by day, `ItineraryDayBuilder::search()` finds the best day (see The day search) and
   `ItineraryRouteOptimiser::orderDay()` orders it, using estimated travel figures. Day d + 1
   starts at the base of night d; a day followed by a night ends at its base (see The base rule).
6. When a routing server answers, the whole trip is measured once on the road (one request, see
   How road figures are asked for; the bases are among its points) and every day is ordered and timed again. A stop that no order can keep is dropped, and the
   search's next places (its spares) take its place (`REPLACE_CANDIDATES`, 3 per dropped stop, measured together in one route request;
   a candidate that does not fit in any order gives way to the next).
7. Everything is stored in one transaction, the base and group of each day in `itinerary_days`,
   and the total is written by `ItineraryEditService::total()`. A failure saves nothing.

## Trip layout

`services/TripLayout.php`, before any day is planned. Deterministic and offline (estimates).

- **Stay at the starting point.** The option `stay_at_start` (the tick box "I am staying at the
  starting point" on `itinerary/select_preference.php`, stored in `itineraries.stay_at_start`)
  makes the starting point the base of every night; its area is every candidate within the area
  radius of it.
- **Areas.** Otherwise the candidates and must-see places are grouped into areas that can each
  be toured from one base. The radius (straight line) is the recommended daily distance over
  2 x 1.3 (a return trip to the edge of the area is one recommended day; 17.3 km by car at a
  normal pace), at least `MIN_RADIUS_KM` (1.5). Best place first, a place joins the first area
  whose first place lies within the radius, else it starts one; the base of an area is the most
  central of its 12 best places (least value-weighted distance to the others), named "district,
  state" and nothing more; places the bases leave uncovered form the next round's areas
  (`AREA_ROUNDS`, 3). Every place then belongs to the nearest base within the radius. A place is
  weighed as the day search would value it (`ItineraryDayBuilder::layoutValue()`): outside the
  preferred districts less the price of relax level 3 (20), outside the interests less that of level 4 (30).
- **Which areas, how many days.** The area of a must-see place is always used, and so is the
  best area of every state the traveller chose (what it offers over the whole trip, less the way
  there from the start), while there are days. Then each further day goes where it adds most
  value: one more day in an area already used (the value of its next "target" best places), or a
  new area (the value of its best places less `TRAVEL_PRICE` per minute the move adds to the
  route). The places are those of the pool, so for a traveller by public transport a place no
  stop reaches does not count (step 2 of the flow). So an area gets days in proportion to what it offers this traveller, at least one;
  there are never more areas than days; a move longer than `TRANSFER_MAX_SHARE` (half) of the day
  window is not made unless the area must be used.
- **Order.** The areas go in the order with the least travelling from the starting point (to the
  end point when there is one); every order is compared up to `MAX_EXACT` (7) areas, and an order
  that puts a must-see place outside its possible dates comes last.
- **Day groups.** An area of k days splits its places into k groups by bearing around its base:
  k arcs of about the same value. Each group goes to a date of the area so that as much of it as
  possible is open that day (weekday hours, festival dates) and, from `RAIN_PREFER` (60) percent
  rain, wet dates get the groups with more indoor value (`RAIN_GROUP_SHARE`). A must-see place
  decides the date of its group (a date it can be visited on).

The base, the group's bearings (`group_from`, `group_to`) and the area radius (`area_km`) of
each day are stored in `itinerary_days` (migration `20261119_trip_bases.sql`); the last day keeps
the base of its area, though no night follows it.

## The base rule

- Day 1 starts at the starting point; day d + 1 starts at the hotel of night d when one was
  chosen, else at the base of night d.
- A day followed by a night ends at that night's hotel or base: every stop must leave time to be
  back by the end of the day window and distance to get back inside the hard daily limit (the
  leg back counts), reason `BASE`; the last day ends at the end point as before (`FINISH`).
- The first leg of day 1, and the first leg of the day the trip moves to another area (its base
  differs from the base of the day before), is not counted by the distance limits; its time
  counts.
- A day followed by a night has a dinner: on the way when the traveller is still out across the
  dinner window (as before), otherwise near the base: at a suitable food place within the meal
  radius of the base, open for the whole meal and with time to be back, with the least detour
  on the way back, or as a dinner break at the base. Waiting there for the window is rest. Only a
  day window that cannot hold the dinner (18:00 plus the meal length after its end) has none.
  A dinner near the base is not touring: the visits and the way back (the arrival at the dinner
  place or at the base) stay inside the day window, but the dinner may begin after the return
  and end after the window; it still begins by 20:30. A dinner on the way, between visits, stays
  inside the window.
  `ItineraryDayBuilder::fit()` with the base as the place times this end of the day
  (`closeDay()`); a day whose end does not fit gives up its last stop.
- A hotel row begins when the traveller arrives there after the last stop (and the dinner at a
  place, when there is one): `ItineraryHotelService::checkInMinute()`, no longer at a fixed 20:00.
- Itineraries planned before bases (no base stored) keep the old behaviour: a day starts where
  the day before ended or at the hotel, and a hotel is only a cost.

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
| Opening hours | the whole visit inside the opening hours of that weekday; a place without usable hours has assumed hours (see Rules about time); reasons `CLOSED`, `OPEN_HOURS`, `DAY_END` | `PlannerRules::hoursOn()`, `OpeningHours::placeRanges()` |
| Office hours | a text read as office hours is closed on the rest days of the place's state (Friday and Saturday in Kedah, Kelantan and Terengganu, else Saturday and Sunday) and on public holidays there | `OpeningHours::placeRanges()`, `PlannerCalendar::dayInfo()` |
| Friday prayers | a mosque (`place_type` mosque, or hours that say "outside prayer times") takes no visit that overlaps 12:15-14:45 on a Friday; reason `PRAYER` | `PlannerRules::prayerBreak()` |
| Five-minute times | every visit and meal starts on a multiple of five minutes (rounded up) | `PlannerRules::TIME_STEP` |
| Peak hours | the minutes of every leg (the way back included) are its measured or estimated minutes times `PlannerCalendar::peakFactor()` for the date, the minute it leaves and its point of departure; the stored time is the adjusted one | `PlannerRules::peakMinutes()` |
| Hours written as words | a text without clock times is read as approximate hours: office hours Mon-Fri 09:00-17:00; lunch hours 11:00-15:00; meal hours 11:00-15:00 and 18:00-22:00; morning (or daytime) to evening 08:00-20:00; evening or night without morning or daytime 17:00-23:59; daytime 08:00-18:00; morning 06:00-12:00; business or shop hours 10:00-22:00 (first match wins). "Open area", "Exterior viewing", "Verify before visit" and other texts give no usable hours: the hours are assumed | `OpeningHours::DESCRIBED` |
| Waiting for opening | at most 60 min; time spent on a meal is not waiting | `MAX_WAIT_MIN` |
| Avoid heat | an outdoor visit must be over by 11:00 or begin after 16:00 | `HEAT_FROM` (660), `HEAT_UNTIL` (960) |
| Elderly | no outdoor visit longer than 150 min | `ELDERLY_LONG_VISIT_MIN` |
| Step-free | for the wheelchair, avoid-stairs and step-free tags, a place whose name, description or category matches the pattern is refused | `STEP_FREE_BLOCKED` |
| Dietary | a food place for halal needs `halal_status` = 1; for vegetarian its name or description must say vegetarian or vegan | `placeReason()` |
| Festival | visited only on a day inside its dates | `festivalDates()`, `FESTIVAL_DAY` |
| States | when states were chosen, a place must be in one of them | `STATE` |
| Peninsular or East Malaysia | with no state chosen, a place must be in the same region as the starting point (Sabah, Sarawak and Labuan are east; for a point without a state, longitude 108 and above is east) | `EAST_STATES`, `EAST_FROM_LNG` |
| End point | on the last day each stop must leave time and distance to reach it | reason `FINISH` |
| Base of the night | on a day followed by a night each stop must leave time and distance to get back to its hotel or base; the day ends there with a dinner | reason `BASE`, `closeDay()` |
| Moving to another area | the first leg of that day is not counted by the distance limits | `first_leg_exempt` |
| Budget | see below | `BUDGET_MARGIN` |
| No duplicates | a place appears once in a trip | reason `DUPLICATE` |
| One landmark, one row | two rows of the same landmark never share a trip (below) | reason `SAME_LANDMARK`, `PlannerRules::SAME_LANDMARK` |
| Public transport reach | for a traveller by public transport a place more than 1500 m (straight line) from its nearest stop, or with none within about 5 km, is no candidate and no meal place; a must-see place is still planned, with the note that the last stretch needs a taxi or e-hailing; a place never measured (`transit_checked_at` NULL) counts as before | reason `TRANSIT`, `TRANSIT_NEAR_M` (500), `TRANSIT_WALK_M` (1500), `PlannerRules::transitReach()` |
| Rides by public transport | a ride (more than 1 km of road) takes the walk from its point of departure to the stop, the wait (`RouteService::TRANSIT_ACCESS_MIN`, 10), the ride and the walk from the stop: a walk is the straight-line metres x 1.3 at 5 km/h; a point that is no measured place (start, base, hotel) 300 m; the last stretch to a place no stop reaches by taxi, 5 min plus the metres (5 km without a stop) x 1.3 at 30 km/h | `PlannerRules::transitAccessMinutes()`, `applyTransitAccess()`, `RouteService::setTransitAccess()` |
| Places of worship | the traveller's choice "Places of worship" (`traveller_preferences.worship_limit`, migration `20261121_worship_limit.sql`): at most one a day, or none; must-see places are still planned and count towards the limit | reason `WORSHIP`, `PlannerRules::worshipReason()`, `breaksWorshipCap()` |

How the accessibility tags are decided: the preference form stores tick boxes in the
`accessibility_tags` column. Without a stored value, the tags are taken from the free text in
`accessibility_needs` by keywords (`AccessibilityNeedsAnalysisService::storedTags()` and
`keywordTags()`). A child in the party gives the family rules; a senior gives the rules of the
elderly tag. There is no surveyed access data: access is decided from these tags and from
keywords in a place's name and description.

### One landmark under two names

The place data holds some landmarks twice, as two active rows with different names ("Sultan
Abdul Samad Building" and "Bangunan Sultan Abdul Samad"). Two rows are the same landmark when
they lie within 150 m in a straight line and `PlaceNameMatcher` scores their names 0.9 or more
(clearly the same name), or within 60 m and 0.5 or more (probably the same name)
(`PlannerRules::SAME_LANDMARK`, `isSameLandmark()`). The numbers come from the data: every true
twin within 150 m scores 0.9 or more except three: two within 60 m (0.55 and 0.62) and "Plaza
Tugu Negara" (140 m, 0.57, 0.9 when weighed against Kuala Lumpur names only); the different
neighbours within 150 m score at most 0.48, and the different places scoring
0.9 or more ("Penang Hill" and "Penang Hill Mosque") lie further apart. The matcher weighs words
against the names it is given, so the groups are worked out once per trip from the loaded
candidates (`PlannerCandidates::twinGroups()`, `PlannerRules::sameLandmarks()`; twins of twins
are one group) and travel in the profile (`twins`). `check()` then refuses a place whose twin
is used (`SAME_LANDMARK`), the meal choice skips a food place whose twin is in the trip, a
must-see place keeps its twins out of the pool (it wins), and so does a place to avoid. An edit
that brings in a twin is refused with `SAME_LANDMARK` and a sentence naming the place already in
the trip; the twin of an avoided place is refused with `AVOID`. Rows already stored together in
an older plan are not removed by a re-timing. `PlannerRules::sameLandmarkPairs()` lists the
pairs, for cleaning up the place data.

### Budget rule

`PlannerRules::budgetAllows()`. The essentials are meals (three a day, `MEALS_PER_DAY`), the
hotel and transport, priced from the budget tier (`CostEstimationService::budgetTierDefaults()`).
Paid places may take what the budget leaves after the essentials plus a margin of
`BUDGET_MARGIN` (10 percent) on them. When the essentials alone are over the budget, only free
places are added. A budget of 0 means no limit. The rule only grows stricter as items are
added, so a plan that keeps it keeps it in any order of its stops. `projectedTotal()` computes
the total exactly as `ItineraryEditService::total()` stores it. The budget style of a version
(below) gives the paid places only `STYLE_BUDGET_PAID_SHARE` (half) of what the rule leaves them
(profile key `paid_share`; 1 without a style), which is stricter still.

A hotel is a choice of the traveller, not of the planner, so the rule above does not decide it:
`ItineraryEditService::setHotels()` times the days with the hotels, and when the stored total is
then over the budget it saves nothing and answers `HOTEL_BUDGET` with `total`, `budget` and
`over_by`, unless the call carries the confirmation (`$overBudgetConfirmed`): then the hotels are
saved and the answer states the amount over (the pages show "Over Budget"). Choosing no hotel is
never refused for it. `ItineraryEditService::hotelBudget()` marks hotel suggestions with the same
total: the stored items with the hotel rows of the nights it would cover in place of the stored
ones, and the stored km with the legs to and from the hotel estimated (no request). The review
page (`itinerary/review_hotels.php`, for the night and for the run of nights at one base, with the
hotels the page chose for other nights) and the chat's hotel assistant (every night) list the
hotels inside the budget first. The review page asks the confirmation in a dialog that states the
amount; in the chat, confirming the same hotel a second time after the answer named the amount is
the confirmation.

## The day search

`ItineraryDayBuilder::search()` replaces the old greedy choice. It looks for the set and order of
stops with the highest value that keeps every hard rule, with at most the target number of stops
and as many as the rules allow: finished days are compared by must-see places, then by stops,
then by value (ties by place ids, so the result is deterministic).

- Value of a day = the sum of its stops' values (`score()['value']`: the terms below except
  nearness, detour and waiting, with the weather) less `TRAVEL_PRICE` (0.4) points per travelling
  minute (every leg: from the start, between stops, to and from meals, and back to the base) and
  `IDLE_PRICE` (1/6) per minute of waiting. 25 travelling minutes cost what one interest weight
  step gives (10 points).
- A stop that only a relaxed level allows costs the price of its level (`RELAX_PRICES`, 10 to 40, below) and says so in
  its notes.
- The beam keeps the `BEAM_WIDTH` (12) best partial days per number of stops (must-see places
  first, then value) and extends each with every candidate; every survivor whose end fits
  (`fit()` with the base, or the end point) is a finished day. Places for the rest of the day are
  kept for must-see places still to come, and they have first call on the budget.
- Candidates: the day's own group, at most `SEARCH_CANDIDATES` (40) by `layoutValue()` less the
  price of the way there and back from the base; a second search adds the 40 best other places of
  the area, and its day is taken when it ranks higher (must-see places, stops, value), so nothing
  good is lost.
- From `RAIN_INDOOR_FIRST` (80) percent during its visit an outdoor place is a next stop only when
  no indoor place of the same level is.
- Work saved without changing the result: a child that cannot reach the 12th best child found so
  far (its value at best: `most()`, on a day without rain, with the estimate of the direct leg
  while every figure is an estimate and the transport is not public transport) is not timed; the
  budget is not tested on a day where it cannot bind (`budgetSlack()`: the whole target at the
  dearest candidate's price and the most the day can travel still keep it); the hours of a place,
  the Friday prayers and the way to a meal place are worked out once per search
  (`PlannerRules::memoStart()`, `mealMemo`).
- It returns its next-best days (`alternatives`, 3) and the places it would take next (`spares`),
  which the repair step of generation and `regenerateDay()` try when a stop gives way; a spare
  taken into a day is given its relax level as that day sees it (`relevel()`).
- A 7-day trip plans in about 0.6 to 1.7 seconds without routing (planner check, the line "planning
  time" of its INFO; the second, wider search of each day doubles the work).

`check()` stays the one feasibility check and `fit()` the one timing (a visit, or with the base
as the place the end of the day).

## Rules about time

- **Five-minute times.** `check()`, the meals and the dinner near the base start on a multiple of
  `TIME_STEP` (5) minutes, rounded up (`OpeningHours::earliestIn()` with a step). Visit and meal
  lengths are multiples of five, so the ends are too.
- **Peak hours.** `PlannerRules::peakMinutes()`: the leg's minutes times
  `PlannerCalendar::peakFactor()` (07:00-09:30 and 17:00-19:30 on a working day, leaving from
  inside the Klang Valley, George Town and Butterworth or Johor Bahru boxes; car 1.5, motorcycle
  1.2, public transport 1.3, walking 1.0), rounded. A leg leaves at the end of the visit, meal or
  hotel row before it on its day, or at the day's start. No state is passed, so only national
  holidays end a working day for it. The leg back to the base that `check()` tests (`BASE`,
  `FINISH`) leaves when the visit ends. Stored legs carry the factor: `ItineraryEditService`
  takes it off the stored minutes when it seeds them (`PlannerRules::unpeak()`, exact because the
  factor is above 1 and the product is rounded), and `routes()` puts it on again for the hour each
  leg then leaves.
- **Assumed opening hours.** A place whose text gives usable hours on no weekday has the hours of
  its type (`OpeningHours::ASSUMED`), else of its category (`ASSUMED_BY_CATEGORY`). They are a hard
  rule like known hours. The stop's notes (and a meal place's) say "opening hours assumed (...):
  check before you go", and the itinerary and review pages and the suggestions show them as
  "09:00-17:00 (assumed)" (`OpeningHours::shown()`).

  | Type | Assumed hours |
  |---|---|
  | mosque, Hindu, Chinese and Buddhist temple, church, Sikh temple, other worship | 08:00-18:00 |
  | museum, gallery, palace, cultural village, cultural centre | 09:00-17:00 |
  | memorial, fort, historic building, historic site, mausoleum | 09:00-18:00 |
  | heritage street | 09:00-21:00 |
  | market | 08:00-18:00 |
  | mall, restaurant, food street | 10:00-22:00 |
  | cafe | 08:00-22:00 |
  | park, beach, waterfall, cave, hill, island, lake or river, forest | 07:00-19:00 |
  | wildlife and zoo | 09:00-17:00 |
  | viewpoint or tower | 09:00-21:00 |
  | festival | none: its dates only |
  | other or no type | by category: culture and heritage 09:00-18:00, museum 09:00-17:00, nature 07:00-19:00, food and shopping 10:00-22:00, festival its dates only, any other as culture |

- **Friday prayers.** `PlannerRules::prayerBreak()` blocks the break of `PlannerCalendar::dayInfo()`
  (12:15-14:45 on a Friday) for a mosque; a visit that fails only on it gives `PRAYER`.
- **Rest days and public holidays.** Office hours close on the rest days of the place's state and
  on its public holidays. `PlannerRules::loadCalendar()` calls `PlannerCalendar::loadHolidays()`
  (when it exists) for each year a trip touches, in generation and in every edit. A trip date
  that is a public holiday in the state of that day (its first stop's state, else its area's)
  gets the day note "Public holiday: <name>. Opening hours may differ." in
  `itinerary_days.day_note` (migration `20261120_day_notes.sql`), shown under the day's heading on
  `itinerary/itinerary_view.php` (`ItineraryEditService::dayBases()`, `note`).

## Soft preferences and scoring

`ItineraryDayBuilder::score()` scores a candidate; `ranked()` (suggestions, the score of a place
the traveller chose) tries them best first, and the day search adds up their values. The score
is deterministic. Its terms:

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
- Place type variety (`place_type`, `services/PlaceTypeClassifier.php`): at most
  `WORSHIP_PER_DAY` (2) places of worship and `SAME_TYPE_PER_DAY` (2) stops of one type a day
  (`other` has no limit; a type asked for less of: 1), `PlannerRules::breaksTypeVariety()`. A
  soft preference with its own relax level (below). A trip of food only is exempt.
- Public transport: a place 500 to 1500 m from its stop loses `TRANSIT_WALK_POINTS` (6) and its
  notes say "a walk of about N min from the nearest stop (M m)"; the walk is in the ride's minutes.
- Wishes of a day planned again by place type: a type (or group) asked for more of counts as an
  interest loved (35 points, passes the interest step); one asked for less of loses
  `TYPE_LESS_POINTS` (12) and has a limit of one that day.
- Nearness: up to +14 for a short leg; a leg longer than the daily distance divided by the
  target costs up to 25 points.
- Waiting: minus the waiting minutes divided by 6.

### Relaxation levels

A candidate is taken at the lowest level its soft preferences allow
(`ItineraryDayBuilder::RELAXED`, `minLevel()`); each level costs its price (`RELAX_PRICES`) in
the day search. Hard rules are never relaxed. A stop chosen at a relaxed level says so in its
notes, with every lower step it actually breaks (a stop outside the preferred districts that is
also a third temple says both).

| Level | Relaxed | Price |
|---|---|---|
| 0 | nothing | 0 |
| 1 | category variety | 10 |
| 2 | and place type variety | 15 |
| 3 | and preferred districts | 20 |
| 4 | and interest match | 30 |
| 5 | and the recommended daily distance (never the hard limit) | 40 |

A day may end up short of its target. A day for which nothing is feasible is stored with one
note row titled `ItineraryGenerator::FREE_DAY_TITLE`; generation still succeeds.

### Learning from the traveller's ratings

`PlannerRules::learnedPoints()`: per category, (average rating - 3) / 2 x `LEARN_MAX_POINTS`
(4.0), scaled by the number of ratings up to `LEARN_FULL_AT` (5). It lies within plus or minus 4
points, less than half of the 10 points between two interest weights, and it never touches
`check()`. A place rated `LEARN_DROP_AT` (2) or lower is not suggested again. Ratings are read
from `ratings_reviews` by `PlannerCandidates::travellerRatings()`.

### Weather

The chance of rain for the day is asked once per trip for the starting point
(`WeatherService::rainChances()`): the day groups of the trip layout use it, the day as a whole.
The chance of rain by the hour is asked once for each base of the trip, with the dates of its
days (`WeatherService::rainHours()`, `ItineraryGenerator::rainByHour()`), and the day search
judges each stop by the hours of its own visit (`ItineraryDayBuilder::rainAt()`): the highest
chance of the hours the visit overlaps. So the value of a stop depends on when it is visited,
and an outdoor place goes to the dry hours. Only dates inside the forecast horizon are asked,
and nothing when `ROUTING_ESTIMATES_ONLY` is set. A date without hourly values keeps its chance
for the day, for every hour. The trip options `forecast` (a chance per day, every hour) and
`forecast_hours` (24 chances per day) replace the forecast for checks and tests. Without a
forecast nothing changes. Weather is a soft preference only.

| Chance of rain during the visit | Effect | Constant |
|---|---|---|
| 60 percent or more | outdoor places lose 15 points, indoor places gain 8 | `RAIN_PREFER`, `RAIN_OUTDOOR_POINTS`, `RAIN_INDOOR_POINTS` |
| 80 percent or more | outdoor places are tried only when no indoor place of the same relaxation level is feasible | `RAIN_INDOOR_FIRST` |

The note names the hours: "90% chance of rain 13:00-17:00: indoor place preferred", or
"outdoor although 85% chance of rain 14:00-15:00: no indoor place fits" (without hourly values
"... chance of rain forecast ..." as before). The hours are those of the visit as finally timed
(`ItineraryDayBuilder::rainNotesAt()`, when the day is stored): ordering the day may move a
visit after the search chose it, and a visit moved to drier hours loses the note.

A place counts as outdoor when `is_outdoor` = 1 or its category is nature
(`PlannerRules::isOutdoor()`). Must-see places are not changed by the weather.
For this soft preference only, a culture or heritage place whose name says it stands in the open
(statue, square, fountain, park, beach, bridge, monument and similar words,
`PlannerRules::OUTDOOR_NAME`) also counts as outdoor, because many older rows have no outdoor
flag. The hard rules for heat and for elderly travellers use the stored flag alone.

## Meals

`PlannerRules::MEAL_WINDOWS`: lunch may start 11:30-14:00, dinner 18:00-20:30. A meal lasts
`MEAL_MIN` of the pace: relaxed 75, normal 60, packed 45 minutes. A day gets a meal when one of
its visits ends at or before the latest start of the window and a later visit ends after it.
The meal is taken between two visits, at a food place within `MEAL_RADIUS_KM` road km of the
stop before or after it (walking 1, public transport 2, car and motorcycle 5), choosing the
least detour, or as a break without a place. A food-place visit that starts inside a meal
window is that meal. The meal stands in for the rest between the two visits. A day followed by
a night that has no dinner by its last visit has it near the base (see The base rule): a meal
row after the last visit, at a food place within the meal radius of the base or as a dinner
break; with a hotel, a dinner break comes after the hotel row.

Meals are stored as `itinerary_items` rows with `item_type` = food and `meal_slot` = lunch or
dinner, at cost 0.00, because the cost model already reserves three meals a day. Meal places
come only from `cultural_places` with category food; the table `food_places` is not used.

## Must-see and avoid

- Avoid: a place on the traveller's avoid list is never a candidate, and an edit that brings it
  in is refused with `AVOID`.
- Must-see: `ItineraryGenerator` finds the days each must-see place can be visited on under
  every hard rule, probing the day window every `MUST_PROBE_STEP_MIN` (60) minutes, and counts
  them all in the budget before any other stop. The area of a must-see place is always used and
  the place goes to the day of its group (else another day of its area, else any day it can be
  visited on, with room). The day search counts must-see places before value, with every soft
  preference relaxed and every hard rule kept, and keeps room in the day for the ones still to
  come. A must-see place that cannot
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

`optimise()` does the same for a saved itinerary (the "Optimise route" button,
`api/itinerary_optimise.php`, and the action `optimise` of `api/itinerary_edit.php`) through
`ItineraryEditService::reorderTrip()`:

1. For every day the day search runs with the stored base and group, the other days' places used
   and the day's must-see places kept, on the figures at hand (as `regenerateDay()` runs it); the
   places it would take that are not stops of the day already (its stops, then its spares) stand
   by, `OPTIMISE_CANDIDATES` (3) a day.
2. One request: a table of the trip's points (visits, hotels and bases, start and end point, then
   while there is room the standby places and the meal places), or, for a trip of more than
   `PlannerRules::LONG_TRIP_POINTS` (20) points of its own, no table but one route request along
   the legs of each day's best order on the figures at hand, the legs from each stop to the day's
   end and the standby places' legs (what does not fit in it stays on the figures at hand).
3. Every day is ordered on those figures from where it starts to its night. A must-see place is
   never dropped for a better order: when no order keeps it, the stored order is timed again, and
   when that fails too the day stays as it was (`kept_days`). A stop no order keeps gives way to
   the standby places, best first: each must pass `placeRefusal()`, the traveller's limit on places
   of worship, and `orderDay()` in every order with the budget rule (`timingFrame()` with the
   budget); the first that fits takes the stop's place, so the day keeps its number of stops when
   a place fits. A new stop's notes are judged after the kept stops (`relevel()`), and its rain
   notes are written for its visit as timed.
4. The days are stored as an edit stores them; a day that is not timed again (kept, or holding a
   row the planner cannot time) keeps its rows and its stored legs. A day left without stops gets
   the "Free day" note.
5. When the new distances put the trip over the budget, nothing is deleted for it: the report has
   `total`, `budget` and `over_by`, and the pages' Over Budget state shows it.

The report: `days` (per day stops, minutes before and after, reordered, problems, replaced,
not_replaced), `reordered_days`, `saved_min`, `replaced` (day, old, new, reason), `not_replaced`
(day, old, reason), `removed` (both, as sentences), `kept_days`, `total`, `budget`, `over_by`,
`long_trip`. `ItineraryEditService::optimiseSentence()` words it with the keys `optm.*` of `lang/`:
the days in a shorter order, what was replaced (old by new), what was removed without a
replacement, a day kept as it was, and the amount over the budget. The optimiser makes one routing
request (the REQUESTS limit of the check).

## Versions of a plan

After generation the review page offers "Compare other versions" (`itinerary/itinerary_review.php`,
`api/itinerary_versions.php`). A version is the same trip (preference, start date, starting point,
end point, `stay_at_start`) planned again by `ItineraryGenerator::generate()` with the trip option
`style`, made by `ItineraryGenerator::createVersion()` on request: one at a time (a lock per
itinerary), one per style, at most `ItineraryGenerator::MAX_VERSIONS` (3). The style changes the
target, the day window and the weights of the day search (`PlannerRules::profile()`, the
`STYLE_*` constants of `PlannerRules`); it never changes a hard rule, so every version keeps every
rule above.

| Style | What changes | Numbers |
|---|---|---|
| `relaxed` | stops a day and the end of the day window | the pace's target less `STYLE_RELAXED_STOPS_LESS` (1), never under `STYLE_RELAXED_MIN_STOPS` (2) and never more than the pace gives; the window ends `STYLE_RELAXED_END_EARLIER` (60) minutes earlier, keeping at least `STYLE_RELAXED_MIN_WINDOW` (180) minutes |
| `budget` | the weight on cost, and the paid places' share of the budget | a free place scores `STYLE_BUDGET_FREE_POINTS` (25, instead of 10); a paid place loses `STYLE_BUDGET_COST_POINTS` (0.5) per RM a person, at most `STYLE_BUDGET_COST_MAX` (20); a travelling minute costs `STYLE_BUDGET_TRAVEL_FACTOR` (1.5) times `TRAVEL_PRICE` (km cost money); the paid places take at most `STYLE_BUDGET_PAID_SHARE` (half) of what the budget rule leaves them |
| `interest` | the weight of interests and of variety | the points of an interest match times `STYLE_INTEREST_FACTOR` (2: 30, 50, 70 for a little, like, love; a type asked for more of counts as loved), the points of category variety (new category +10, repeated beyond its limit -12, the day before's theme -15) times `STYLE_INTEREST_VARIETY_FACTOR` (0.5); the limits and relax levels of variety stay, so it is relaxed only where the notes say so |

The weights go through the day builder's helpers (`interestPoints()`, `varietyPoints()`,
`costPoints()`, `travelPrice()`), which `score()`, `placeValue()` (so the trip layout) and the
search's bound `most()` all read, so the bound still covers every score term.

What is stored: a version is an itinerary of its own (its rows, days, bases and total) with
`itineraries.version_of` set to the itinerary it is a version of and `plan_style` set to its
style (migration `20261122_itinerary_versions.sql`; `version_of` has a foreign key with ON DELETE
CASCADE). A row with `version_of` set is no trip of the traveller's: "My itineraries" and the
dashboard counts leave it out, and `itinerary/itinerary_view.php` sends it to the review page of
its plan. `ItineraryEditService::context()` reads `plan_style`, so a chosen version keeps its
style in every later edit.

- "Use this version" (`ItineraryEditService::chooseVersion()`): the version's `version_of` becomes
  NULL, and the itinerary under review goes with its other versions; one transaction.
- Confirming the review (`itinerary/review_confirm_nightly.php`) and deleting the itinerary
  (`itinerary/itinerary_delete.php`, and the foreign key) remove the versions that were not chosen
  (`dropVersions()`).
- Versions older than a day that were never chosen are removed when the traveller next generates
  (`pruneVersions()`, from `itinerary/generate_itinerary.php`).

The comparison (`ItineraryEditService::versionSummary()`, built on the server when the page
loads, one column per plan) shows per version the stops per day, each day's first start and last
end (visits and meals), the total travelling minutes (every stored leg), the stored total against
the budget, how many stops match the traveller's interests (and how many of them an interest
loved), and the places of each day by name.

`api/itinerary_versions.php` (POST, the login, role, ownership and CSRF handling of
`api/itinerary_edit.php`): `itinerary_id` (the itinerary under review, not a version), `action`
`create` with `style` (`relaxed`, `budget`, `interest`) or `choose` with `version_id` (a version of
it, or the itinerary itself to keep it and remove the versions). The answer: `ok`, `status`
(`done` or `refused`), `code` (`VERSION_LIMIT`, `VERSION_EXISTS`, `NOT_FOUND`, `UNAVAILABLE`,
`NO_CHANGE` while another version is planned), `message`, `display` (the page's language), and
`version_id` and `versions` for create, `itinerary_id` (the one that remains) for choose. A
request that is not allowed or not valid gets status `error`: 401 not signed in, 405 not POST,
403 token, 400 action or style not on the list, 404 not the traveller's itinerary.

## Edit operations

`ItineraryEditService` is the one place that changes a stored itinerary. Each operation runs in
one transaction, is judged by `PlannerRules`, and re-times the days it touches with the
generator's own timing. A change that would break a hard rule is refused and leaves every row as
it was. A day may have a stored start time (table `itinerary_days`, migration
`20261117_itinerary_days.sql`) that every re-timing of that day keeps. Every re-timing keeps the
base rule: a day after a night starts at its hotel or base, a day followed by a night ends there
with its dinner, and a change that would break it is refused (`BASE`, `MEAL`). A day whose end
moved (a hotel chosen) is timed again too. Every operation returns
`ok`, `status` (`done` or `refused`), `code`, `message` and the re-timed `days`.

| Operation | Action in `api/itinerary_edit.php` |
|---|---|
| `suggestReplacements()` (`SUGGEST_LIMIT` = 3 options by default, deterministic, `offset` for more) | `suggest` |
| `replaceStop()`, `canReplace()` | `replace` |
| `removeStop()` | `remove` |
| `addStop()`, `canAdd()` | `add` |
| `moveStop()` | `move` |
| `regenerateDay()` (more and less: categories, place types `type:<type>` or groups `group:<group>` of `PlaceTypeClassifier`, validated by `PlannerRules::wishTokens()`; optional rain, for the day or by the hour; the day search with the stored base and group) | `regenerate_day` (POST `more`, `less`: comma-separated wishes) |
| `setDayStart()`, `retimeTrip()` | `set_day_start` |
| `optimise()` | `optimise` |
| `replanRainyDays()` (days with an hour inside the day window at `RAIN_PREFER` percent or more, `rainInWindow()`, planned with the hourly values) | `replan_rain` |
| `setHotels()` (a hotel is where its night is spent: the day before ends there, the day after starts there; a night whose hotel would break either day is not saved; over the budget refused with `HOTEL_BUDGET` unless confirmed, see Budget rule) | the hotel pages and the hotel assistant |
| `chooseVersion()`, `dropVersions()`, `pruneVersions()` (see Versions of a plan) | `api/itinerary_versions.php` (`choose`), the review and delete pages, generation |

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
| `SAME_LANDMARK` | the place is the same landmark as a place already in the trip, under another name |
| `UNAVAILABLE` | not an active place with coordinates, or day starts cannot be stored yet (migration missing) |
| `EARLY_START` | a day cannot start before 06:00 (`PlannerRules::EARLIEST_START`) |
| `DAY_END` | the chosen day start leaves no time before the end of the day |
| `MEAL` | the chosen meal does not fit |
| `DIETARY` | the chosen meal place is not among the food places the trip may use |
| `TRANSIT` | for a traveller by public transport: the place has no stop within 1500 m (not for a must-see place) |
| `WORSHIP` | the place of worship goes over the traveller's limit (none, or one a day), or the change puts more places of worship into a day at its limit (not for a must-see place) |
| `HOTEL_BUDGET` | the hotels would put the stored total over the budget (with `total`, `budget`, `over_by`); saved only with the confirmation |
| `VERSION_LIMIT`, `VERSION_EXISTS` | the itinerary already has its three versions; a version in this style exists already |

The AI assistant (`services/ItineraryEditIntentService.php`) reads an edit request in English,
Malay or Chinese with the local Ollama model and falls back to regular expressions. It only
produces a call of one of the operations above; the rules still decide. Its more and less lists
hold categories and place types: a word for a kind of place (temple, mosque, church, gallery,
market, mall, beach, waterfall, park, cave, palace, fort, village, heritage street, food street
and the like, `TYPE_WORDS`, in the three languages) wins over the category it lies in, so
"fewer temples, more museums" asks for fewer temples of every kind and more of the museum
category.

The regenerate panel of `itinerary/itinerary_view.php` offers the categories and every type,
grouped by its group (`ptype.*` in `lang/`).

## What is an estimate

Travel figures are estimates while places are chosen: straight line times
`RouteService::ROAD_FACTOR` (1.3) at the average speeds of `SPEED_MAP` (car 60, motorcycle 55,
public transport 30, walking 5 km/h; a ride by public transport also takes the wait and the
walks to and from its stops, see Hard rules). The stored itinerary uses road figures from OSRM or
Valhalla when they answer. With `ROUTING_ESTIMATES_ONLY=1`, or when the server does not answer,
the estimates are stored (`method` is `estimate`). Hotel prices are estimates, and entrance fees
and visit lengths of imported places are typical values. `docs/OPEN_DATA.md` has the details.

## How road figures are asked for

Every request to a public routing server costs time: `OpenDataHttp` spaces requests to one host
(`HOST_INTERVAL`, 1.1 s for the FOSSGIS servers), and the public OSRM server holds a second
"table" request (every pair of a set of points) within about ten seconds back for that long. A
"route" request (the legs of a chain of points) is not held back. So the planner asks in
batches, never per candidate, and keeps to these rules (`RouteService`):

- Candidates are screened and ranked on the figures at hand: the legs stored with the
  itinerary (`RouteService::seed()`, no request), road figures measured earlier in the same
  operation, and estimates for the rest. Only the few that will be shown or stored are then
  measured on the road, together, with the legs of their day: `RouteService::measureLegs()`
  strings the legs into as few route requests as it can (at most `MAX_ROUTE_POINTS` points
  each). A candidate that looked feasible on the estimates but fails on the road figures gives
  way to the next one.
- Planning a day again (`regenerateDay()`, also behind `replanRainyDays()` and the AI editor) plans
  the new day on the figures at hand, measures it once (its stops in order, the meal places of
  that timing, every other pair of its points, the legs to the end of the day and to the next
  day), orders it again on those figures in every order, and lets a stop that no longer fits
  give way to the day search's next places (`SETTLE_CANDIDATES`, 3, measured together in the
  second request); a must-see place of the day is tried in every position. The present day
  stays as it is only when the new day would lose a must-see place, have no stop, or make
  another day break a rule; the refusal names the rule and carries `worse` (`must`, `empty`,
  `next_day`). `settleDay()` has the steps.
- An edit (`ItineraryEditService::plan()`) times the days it touches on the figures at hand,
  measures the legs those days need that are not known (stops in order, the meal places on
  the way, the hotel or the end point), and times them again; a second request follows only for
  legs the new timing needs (a meal moved to another place). At most `MAX_REQUESTS` (2)
  requests per operation, each waiting at most `ROUTE_TIMEOUT` (5) seconds. A change to one day
  asks about that day and the day whose start it moves, not the whole trip.
- Setting a day's start moves no stop and asks nothing (`setDayStart()`, `retimeTrip()`).
- A trip of more than `PlannerRules::LONG_TRIP_POINTS` (20) points of its own (start,
  stops, bases, end point) is measured along its planned legs with route requests
  (`measureLegs()`: the days join at their bases, one chain of up to 60 points a request), and
  each day is timed in its planned order on them; a day that loses a stop is ordered again and
  repaired as below. The route optimiser asks no table for such a trip either: one route request
  along each day's best order, the legs to the day's end and its standby places' legs (What the
  optimiser does).
- Otherwise generation and the route optimiser ask for one table for the whole trip
  (`RouteService::preloadTrip()`): the stops, bases, hotels, start and end point, and, while there is
  room (`MAX_TABLE_POINTS`, 50), the optimiser's standby places and the meal places (also those near the bases); those left out are estimated. A day whose stop
  has to be replaced after the road figures adds one route request in generation. Their timeout is 8 seconds
  (`ItineraryGenerator::ROUTE_TIMEOUT`, `ItineraryRouteOptimiser::ROUTE_TIMEOUT`). A motorcycle
  trip's table of more than ten points comes from OSRM's car profile.
- The figures a day is timed with are the ones stored with it (`ItineraryEditService::routes()`
  with the operation's route service makes no request), so a stored plan keeps every rule on
  its stored figures. A slow or silent server never makes an operation fail: it goes on with
  estimates.
- `RouteService::requestCount()` counts the requests made, or with `ROUTING_ESTIMATES_ONLY` those
  that would have been made, and the planner check judges every operation by it (`REQUESTS`).

## The planner check: `tools/check_planner.php`

It saves fixed preferences for a test traveller (`TEST_EMAIL`), generates an itinerary from each
exactly as the generate page does, reads the stored result back and judges it by rules written
out again inside the tool. It never calls the planner's rule functions to decide what is
right, so it stays a fair judge. It refuses to run on a database whose name does not end in
`_ctest` (exit code 2).

```
DB_NAME=travel_itinerary_db_ctest /c/xampp/php/php.exe tools/check_planner.php
```

Options: `--scenario=<name>` runs one scenario, `--pass=default` or `--pass=skew` runs only one of
the two passes (below), `--keep` keeps the generated itineraries,
`--json` gives machine-readable output, `--road` allows real routing (the public servers).
Without `--road` it sets `ROUTING_ESTIMATES_ONLY=1` and makes no request. Exit code 0 means no
violation, 1 at least one violation. A warning does not fail the run. The column `REQ` shows the
most routing requests one call made in the scenario, and `PLAN` a fingerprint of the stored plan,
to see whether a change altered any plan.

Without `--road` there are two passes. The first runs every scenario on estimates, where the
figures a day is planned with and the figures it is stored with are the same. The second runs
the edit scenarios again (`<scenario>@skew`) with the test switch `ROUTING_TEST_SKEW=1`: with
`ROUTING_ESTIMATES_ONLY` set, every leg the planner measures then gets "road" figures that
differ from the estimates (distance and time each scaled by a factor of 0.8 to 1.6 fixed per
pair of points, `RouteService::skewed()`), while planning still sees the estimates. Every check
must hold there too. The hours the check reads for `OPEN_HOURS` include hours written as words,
from its own copy of the table above (`DESCRIBED_HOURS`).

The scenarios are the entries of the `SCENARIOS` constant, plus `<base>__edits` for each name in
`EDIT_BASES`: the base is generated, then changed through `ItineraryEditService` (replace,
remove, move, add, plan a day again, a day start of its own, a hotel, a rainy day, optimise), the
checks run again, and changes that must be refused are tried; last the dearest hotel is tried
against the budget (`HOTEL_BUDGET`). `<base>__versions` for each name in `VERSION_BASES`
(`kl_couple_car_normal`, `sabah_family_motorcycle_normal`): the base is generated, a version in
each style is made and judged, a fourth is tried, and one is chosen (`VERSIONS`).
`<base>__optimise@skew` for each name in `OPTIMISE_FRESH_BASES` (second pass only): the base is
planned on estimates and stored without its legs, as a plan stored while no routing server
answered, and the optimiser then meets the skewed figures for every leg, as a real table answer
replaces stored figures; the three bases are chosen so that a stop gives way and is replaced
(`sabah_family_motorcycle_normal`), a stop gives way with no place to take it
(`kl_selangor_family_transit_tight`), and a long trip ends over its budget
(`perak_solo_motorcycle_packed_7days_tight`).

Finding codes. A violation fails the run; a warning is only reported.

| Code | Meaning |
|---|---|
| `GENERATE`, `SETUP` | generation failed or stored nothing; the scenario's setup failed |
| `DAYS`, `STOPS` | a day without a visit or a stop outside the trip's days; more stops than the target (violation) or fewer (warning) |
| `BASE` | a day without a stored base; a day after a night whose first leg does not start at that night's hotel or base; a day followed by a night whose last visit cannot be back there by the end of its window or inside the hard daily distance with the leg back; with `stay_at_start` a base away from the starting point, or the choice not stored |
| `HOTEL_TIME` | a hotel row that begins before the traveller can have arrived from the last row with a place, or more than `HOTEL_LATE_MIN` (60) minutes after |
| `DINNER` | a day followed by a night without a dinner (a dinner row, or a food visit starting in the dinner window), or with two; not judged when the day window cannot hold the dinner |
| `ROUND` | a visit or meal that does not start and end on a multiple of five minutes |
| `PEAK` | on estimates: a stored leg time that is not the estimate times the rush-hour factor of the hour it leaves (own copy `PEAK_*`, slack `PEAK_SLACK_MIN` 2 min); in `first_leg_peak` scenarios the factor missing on the working day or applied on the Sunday |
| `PRAYER` | a mosque visit on a Friday that overlaps 12:15-14:45 |
| `HOLIDAY` | a public holiday of a trip date (`FIXED_HOLIDAYS`, the scenario's `holidays`, the table `public_holidays`) without its day note, or a holiday note on another date |
| `PLAN` | warning: a generation scenario (estimates) has fewer stops than its recorded plan (recorded after the place data clean-up, public transport reach, place types and rain by the hour), or more than 25 percent and 5 km more (`RECORDED_PLANS`, `PLAN_KM_SHARE`, `PLAN_KM_EXTRA`); the km of a trip are its stored legs plus the way back to the base after each night without a hotel row (`tripKm()`) |
| `DUPLICATE` | a place twice in a trip, or one landmark under two names (its own rule: within 150 m with the same words or all words of the shorter name, at least two, in the other; or within 60 m with a Dice score of 0.7 or more; Malay words read as English, `TWIN_*`) |
| `START`, `HARD_END`, `TIME_ORDER` | a visit before the day start or after the latest end; missing or reversed times; a gap shorter than the travel time |
| `REST` | the rest between visits is shorter than planned (warning) |
| `WAIT`, `DURATION`, `OPEN_HOURS` | waiting over 60 min; a visit shorter than 30 min or longer than allowed; a visit outside opening hours (office hours closed on rest days and holidays, assumed hours by its own copy `ASSUMED_HOURS`) |
| `ACCESS` | the heat, step-free or elderly rule broken (a visit that only runs into the heat window is a warning) |
| `DIETARY`, `FESTIVAL`, `REGION` | the dietary, festival date, state or region rule broken |
| `LEGS`, `DISTANCE` | a stored distance or time that does not fit the straight line or the speed (a day after a night measured from its hotel or base); a leg or a day over its limit (a day over the recommended km is a warning; the first leg of day 1 and of the day the trip moves to another area, towards the new base, are not counted) |
| `CATEGORY` | too many visits of one category without a note that variety was relaxed |
| `MEAL` | a meal row or a missing meal is wrong (window, length, item type, cost, reason, place, radius; a dinner after the last visit only on a day followed by a night, near its base) |
| `WEATHER`, `RAIN` | an outdoor stop on a day at 80 percent rain or more (warning); by the hour (`forecast_hours`), an outdoor stop overlapping hours at 80 percent or more while indoor places were left (warning; with `outdoor_dry_hours` a violation unless its notes say no indoor place fits); after planning a rainy day again, an outdoor stop stayed while indoor places were left |
| `TRANSIT` | on a public transport trip: a stop at a place no stop reaches within 1500 m without the note about the last stretch, a meal at such a place, a ride to a place 500 to 1500 m from its stop shorter than the walk; a missing note about the walk is a warning. On estimates `PEAK` checks every ride's walks exactly (own copy `TRANSIT_*`) |
| `TYPE` | more than two places of worship or two stops of one type in a day (one of a type asked for less of, `day_type_limits`) without the note that type variety was relaxed (or must-see); the traveller's limit on places of worship exceeded by a place that is no must-see place |
| `AVOID`, `RATED`, `MUST` | an avoided or low-rated place in the trip, or the same landmark as an avoided place; a must-see place neither planned nor named in a note |
| `LEARN`, `WEIGHTS`, `WINDOW` | warnings: ratings not reflected, interest weights not reflected, a widened window not used |
| `FINISH` | the end point is not stored, or cannot be reached in time or distance |
| `BUDGET` | the stored total differs from the total recomputed from the items, or paid attractions exceed their share; a total over the budget is a warning |
| `REQUESTS` | generation or an edit made more routing requests than `REQUEST_LIMITS` allows (counted also offline) |
| `EDIT`, `REFUSE`, `DAY_START`, `OPTIMISE` | an edit result is malformed or broke the stop's position (a refused edit is a warning; planning a day again refused on a rule of its own new plan, without `worse`, is a violation); a change that had to be refused was made or changed rows; a stored day start was lost; after the optimiser a day has fewer stops than the answer's stops not replaced allow, a day reported kept changed, or the trip is over the budget and the answer does not state the amount (violations; a stop removed without a replacement while one of the ten nearest places still fits the day by `canAdd()` is a warning) |
| `HOTEL_BUDGET` | the dearest lodging within 15 km of night 1's base, for every night: when it puts the trip over the budget it must be refused with `HOTEL_BUDGET`, the amount in the answer and no row changed; with the confirmation it must be saved, the answer state the amount and the stored total be over the budget; when it keeps the trip inside the budget the total must stay inside |
| `VERSIONS` | a version in each style is made (the generation limit of `REQUESTS`) and judged by every check (a relaxed version by its own target and day end, a budget version with paid places within half the attraction share); relaxed has more stops than the plan as generated or a visit after its earlier day end; budget costs more than the plan as generated; versions not marked as versions of the plan, or more than one itinerary of its own; a fourth version made or changing rows; choosing one does not leave exactly one itinerary |

The scenarios `kl_couple_car_stay_at_start` (the `trip` key `stay_at_start`),
`penang_kl_couple_car_5days_two_states` (two states some 300 km apart: a move between areas) and
`pahang_couple_car_7days_large_state` cover the trip layout; the first two also run as edit
scenarios. `kl_couple_car_peak_monday` and `kl_couple_car_peak_sunday` (the same day trip from
08:00 on a working day and on a Sunday), `kl_solo_car_friday_mosque` (an afternoon window on a
Friday with a mosque as a must-see place), `johor_solo_car_office_rest_day` (an office-hours
museum on a Sunday and a Monday) and `johor_solo_car_holiday_note` (a holiday handed in with
`PlannerCalendar::setHolidays()`) cover the rules about time (scenario keys `first_leg_peak`,
`musts_planned`, `holidays`). `KM` in the table is `tripKm()`.
The scenarios of `MORE_SCENARIOS` cover public transport (`kl_couple_transit_dense_stops`, also
as an edit scenario, and `sabah_solo_transit_sparse_stops`, with a must-see place 2 km from its
stop and a refused place no stop reaches, `refuse_transit`), the traveller's limit on places of
worship (`kl_couple_car_worship_one_a_day` and `kl_couple_car_worship_none`, which also refuses a
place of worship, `refuse_worship`) and rain by the hour (`kl_couple_car_rain_afternoon_hours`:
90 percent from 13:00 to 17:00 only, `outdoor_dry_hours`). `kl_couple_car_stay_at_start__edits`
plans its last day again with `regenerate_types` (more museums, less of every place of worship).

To extend it: add an entry to `SCENARIOS` (a `pref` and a `trip`, with optional `weights`, `must`, `avoid` and `ratings` keys,
the `trip` keys `end_name`, `end_lat` and `end_lng` for an end point, `stay_at_start`, and `forecast` for a rain
override; copy an existing
entry), and add its name to `EDIT_BASES` for an edit run. When a rule changes in
`PlannerRules`, change the matching constant at the top of the tool and the check in
`checkItinerary()` in the same commit. A new finding is one `$add('CODE', 'violation'|'warning',
...)` call there.
