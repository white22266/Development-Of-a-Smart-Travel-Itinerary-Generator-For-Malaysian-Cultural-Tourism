# Open data in this system

Since November 2026 this system uses no paid service and no API key for maps, places,
search, routing or weather. Everything it needs to work is free and open, and none of it
needs an account.

Two things are optional and off until they are set up: real public transport connections
(free and open, but the public server has conditions) and live traffic (TomTom, which is
**not** open data: a commercial service with a free monthly allowance and a key).

One thing no open source offers is the list of Malaysia's public holidays, so the admin enters
them (see Public holidays).

## What comes from where

| Feature | Source | Licence | Credit shown |
|---|---|---|---|
| Map pictures (tiles) | OpenStreetMap | ODbL | bottom corner of every map |
| Places, opening hours, phone, website | OpenStreetMap (Overpass API) | ODbL | place page, "Sources" |
| Place descriptions | Wikipedia, English (found through Wikidata) | CC BY-SA 4.0 | place page, "Sources", with a link to the article; itinerary PDF, credits table |
| Place photos | Wikimedia Commons | per photo (CC BY, CC BY-SA, public domain) | place page, "Sources": author, licence, link; itinerary PDF: under each photo and in the credits table |
| Hotels and other lodging | OpenStreetMap | ODbL | "from OpenStreetMap listings" |
| Address search, "what is here" | Photon, then Nominatim (OpenStreetMap data) | ODbL | - |
| Routes by car and on foot, order of the stops | OSRM on OpenStreetMap data (FOSSGIS servers) | ODbL | map info box |
| Routes by motorcycle | Valhalla on OpenStreetMap data (FOSSGIS server) | ODbL | map info box |
| Weather forecast (chance of rain by day and by hour, used when a trip is planned and edited) | Open-Meteo | CC BY 4.0 | map info box and forecast answers |
| Public transport stops (rail stations, bus stops, ferry terminals: how far each place is from its nearest stop) | OpenStreetMap (Overpass API) | ODbL | the OpenStreetMap credit, as for the other OpenStreetMap data |
| Public holidays | entered by the admin from the official announcement (`admin/admin_holidays.php`) | not a copied dataset | - |
| Weather warnings | Malaysian Meteorological Department, through data.gov.my | open government data | road conditions panel |
| Fires | NASA FIRMS (NOAA-20 VIIRS hot spots, last 24 hours) | open, credit asked for | road conditions panel |
| Live positions of buses and trains | the operators (Prasarana, KTMB, BAS.MY), through data.gov.my | CC BY 4.0 | under the map |
| Public transport connections (optional) | a MOTIS server such as Transitous, on the operators' published timetables | per timetable | details of each leg |
| Live traffic (optional) | TomTom Traffic Incidents API | **commercial, not open** | road conditions panel: "© TomTom" |
| Map library | Leaflet 1.9.4 | BSD-2-Clause | - |
| AI answers | Ollama with a local model | see the model's licence | - |

The credits are part of the licences. Keep them when changing a page.

## Setting it up

1. Create the database (see `README.md`: either `database/schema.sql` with
   `database/seed_reference.sql`, or the full dump), then apply the files in
   `database/migrations/` with `php tools/migrate.php` (`--status` lists what is applied).
2. In `.env`, set `OPEN_DATA_CONTACT` to a web address or e-mail address. The open services
   ask every application to say who runs it; it is sent with each request to them.
   It matters most for Wikimedia, whose published limits are 10 requests a minute for an
   application that gives no contact and 200 for one that does. Without it, photo downloads
   are the first thing to be refused.
3. Fill the place and lodging tables for the first time:

   ```bash
   php tools/sync_places.php --state="Melaka" --dry-run   # see what would change, save nothing
   php tools/sync_places.php --all                        # every state, one after another
   ```

4. Give every place a type and load the public transport stops (both are needed once on a new
   database, before the first trip is planned):

   ```bash
   php tools/classify_places.php                          # place_type for every place
   php tools/sync_transit_stops.php --all                 # stops, and the distance from each place to its nearest stop
   ```

   Both refuse a database whose name does not end in `_ctest` unless `--confirm=<database name>`
   is given. `--dry-run` saves nothing in either.
5. Enter the public holidays of the year on the admin page *Public Holidays*.
6. Run the sync once a week. `tools/sync_places_weekly.bat` runs it for every state with
   `--no-publish` (new places wait for review), then gives the new places a type
   (`classify_places.php --missing`), and on the first run of each month also reloads the public
   transport stops. It keeps a dated log in `storage/logs/`; `README.md` shows how to register it
   in Windows Task Scheduler.

### Optional: public transport connections

Set `TRANSIT_API_URL=https://api.transitous.org` in `.env`. The map then shows, for a trip
by public transport, which bus or train to take, where to board and alight, and when it
leaves. Timetables are published for Kuala Lumpur and the Klang Valley (Rapid KL bus and
rail, KTM), Penang (Rapid Penang) and the KTM trains; elsewhere, Melaka for one, there are
none and a leg keeps its estimate.

Transitous is run by volunteers. Read its usage policy at https://transitous.org/api/ before
turning it on: it is meant for open-source, non-commercial projects that use it lightly and
say who they are (`OPEN_DATA_CONTACT`). Answers are cached for six hours.

### Optional: live traffic (TomTom)

Traffic jams, closed roads, road works, accidents and flooded roads come from TomTom. This
is the one part of the system that is not open: TomTom is a company, the service needs an
account and a key, and its terms apply (https://docs.tomtom.com/legal/terms-and-conditions).

1. Create a free account at https://developer.tomtom.com and copy the API key. At the time
   of writing no credit card is asked for, the Traffic Incidents API has 2,500 free requests
   a month, and requests beyond that are refused rather than charged
   (https://docs.tomtom.com/pricing).
2. Put the key in `.env` as `TOMTOM_API_KEY`. It stays on the server: the browser never
   sees it.

What is sent to TomTom is the area of the day's route, with its edges rounded outwards to
0.02 degrees (about 2 km): no account, no names, no exact places. An area is asked about at
most once in ten minutes, and the application stops by itself at `TOMTOM_MONTHLY_LIMIT`
requests in a calendar month (default 2400).

## The place sync (`tools/sync_places.php`)

For each state the job reads visitor places and lodging from OpenStreetMap, adds facts, text
and photos from Wikidata, Wikipedia and Wikimedia Commons, and then:

- **Existing places** are linked to their OpenStreetMap entry and get what they are missing:
  opening hours, phone, website, a photo, and a description when theirs is very short. A value
  that is already there is never overwritten. Every change is listed on the admin page
  *Open Data Imports > Automatic changes*, where it can be undone; an undone change is not
  made again.
- **New places** get a score from 0 to 100 for how well documented they are (a Wikipedia
  article, a photo, opening hours, a website). At or above `PLACE_SYNC_AUTO_PUBLISH_MIN`
  (default 80) a place is published by itself; the others wait on *Open Data Imports > New
  places* for an admin to publish or reject. A rejected place is not proposed again.
- **Possible duplicates** of a place the database already has are listed separately. An admin
  can say "same place" (the existing place is linked and completed by the next sync) or
  publish it as a new place.
- **Lodging** goes into the `lodgings` table, which hotel suggestions are answered from.
- **Photos** are saved in `uploads/places/`, never linked from Wikimedia's servers. The job
  takes a resized copy: 1280 pixels wide, or the next smaller width Wikimedia keeps (960, 500,
  330, 250) when the original is smaller than that. Wikimedia asks for this, and it limits
  downloads of original files much more tightly. When it answers "slow down" all the same,
  the job does not wait: the place is saved without its photo, the log says so, and the next
  run adds the photo.

Running the job again never creates duplicates. Answers from the open services are cached in
`storage/cache/`, so a second run within a few days makes almost no requests.

## Place types (`tools/classify_places.php`)

Every place has a finer type than its category (`cultural_places.place_type`: mosque, Hindu
temple, museum, beach and so on, listed in `services/PlaceTypeClassifier.php`). The classifier
decides from the name, the OpenStreetMap kind the place sync stored, the first sentence of the
description and, last, the category. `--missing` classifies only places without a type; without
it every place is classified again. A type an admin chose in the *Type* field of the knowledge
base form (`place_type_source` = `admin`) is never changed. The job prints the count per type
and a clean-up list of places whose type contradicts their category. The planner uses the type
for variety, for the traveller's choice about places of worship, for the Friday prayers and for
assumed opening hours.

## Public transport stops (`tools/sync_transit_stops.php`)

For a traveller by public transport the planner needs to know how far a place is from a stop.

- One Overpass request per state (`OsmDataService::transitStops()`) reads the rail stations,
  tram and light rail stops, bus stops and stations and ferry terminals. The stops go into the
  table `transit_stops`, matched by their OpenStreetMap id, so the job can run again: a changed
  stop is updated and a stop the answer no longer has is removed. An empty answer is never
  believed and changes nothing.
- Then, for every active place of the state, the straight-line distance to the nearest stop and
  to the nearest rail stop is stored (`cultural_places.nearest_stop_m`, `nearest_rail_m`,
  `transit_checked_at`), looking only at stops within a box of `TransitStopService::BOX_DEGREES`
  around the place (about 5.5 km). NULL means no stop in the box; a place with
  `transit_checked_at` NULL has not been measured and is planned as before.
- The planner then keeps a place no stop reaches out of a public transport trip, adds the walk to
  and from the stop to every ride and says so in the notes. The numbers are in
  `docs/PLANNER.md` (Hard rules).
- Stops change slowly, so a monthly run is enough (the weekly script does it on the first run of
  each month). `--state="<state>"` runs one state; `--all --dry-run` fetches and counts and
  stores nothing. It ends with a table per state and exits with code 2 when a state could not
  be fetched; run it again later for those states.

These are stops as OpenStreetMap volunteers have mapped them: a missing or wrongly placed stop
makes a place look farther away than it is. No timetable is involved (see the optional
connections above).

## Routes: how the best order is found

A day's stops are visited in the order with the least travelling for the trip's transport.

1. While a trip is planned, places are chosen with estimated travel times (straight line x 1.3
   at a typical speed): hundreds of possible legs are compared, too many to ask a server about.
2. When the plan is complete, `ItineraryGenerator` asks the routing server once for the road
   time between every pair of the trip's points (a long trip is measured along its planned legs
   with route requests instead), and `ItineraryRouteOptimiser` compares every
   possible order of each day's stops (up to eight stops; beyond that the order is improved
   step by step) and times the days again. Car, walking and public transport are measured by
   OSRM, motorcycles by Valhalla's motorcycle profile, so each way of travelling can end up
   with its own order.
3. Only orders that keep every rule of `PlannerRules` are used (opening hours, day window,
   rest, distance limits, budget, accessibility). `docs/PLANNER.md` lists the rules and their
   numbers. A stop that no order can keep on the road figures is dropped; at generation the
   next best places of its day are tried in its place.
4. A traveller can run the same step again with "Optimise route" on the itinerary page, for
   example after replacing a stop or choosing hotels (a day followed by a night then ends at its hotel).

The public servers have limits the code is built around: OSRM takes fifty points in one
request but makes a client wait about ten seconds before the next one, so a whole trip goes
into one request; Valhalla answers at once but takes ten points, so each day is one request.

## Weather, meals, accessibility and languages in planning

- **Weather.** The chance of rain for the trip's dates comes from Open-Meteo, only for dates
  inside the forecast (16 days). The chance for each day is asked once per trip for the starting
  point, and the chance for each hour once for each place the traveller sleeps at. A stop is judged by the
  hours of its own visit: when the chance during the visit is 60 percent or more the planner
  prefers indoor places, and from 80 percent it tries outdoor places only when no indoor place
  is feasible. It is a preference, never a hard rule, and without a forecast the plan is made as
  usual. The itinerary page can plan the rainy days again. The exact numbers are in
  `docs/PLANNER.md` (Weather).
- **Meals.** Lunch and dinner are planned as rows of their own, at food places taken from the
  imported place data or as a break without a place. No restaurant booking, price or menu data
  is used.
- **Accessibility.** OpenStreetMap and the other sources give no surveyed access data (step-free
  entrances, lifts, ramps). Access is decided from the traveller's ticked needs and from
  keywords in a place's name and description, so it is a best effort and not a guarantee.
  Halal and vegetarian choices are decided in the same way, from the place's halal flag and
  its name and description.
- **Languages.** The traveller pages are in English, Malay and Simplified Chinese
  (`config/i18n.php`, texts in `lang/`). The PDF is in
  English or Malay; a traveller using Chinese gets the English PDF.
- **Planner rules.** All rules, numbers and the planner check are in `docs/PLANNER.md`.

## Public holidays

No free source lists Malaysia's public holidays: Nager.Date has none, and most of them move
every year (Chinese New Year, Hari Raya, Wesak, Deepavali, Awal Muharram, the Prophet's
birthday, state rulers' birthdays). So the admin enters them from the official announcement on
the page *Public Holidays* (`admin/admin_holidays.php`), into the table `public_holidays`, for
the whole country or for chosen states. Only the holidays with a fixed date come from a rule
(`PlannerCalendar::fixedHolidays()`: New Year's Day, in all states except Johor, Kedah,
Kelantan, Perlis and Terengganu, Labour Day, National Day, Malaysia Day and Christmas Day).
Nothing is fetched from any server. Until the admin has entered a year, the planner knows the
fixed holidays of that year only, and places with office hours are planned as if the moving
holidays were working days.

The planner uses them so that a place with office hours is not planned on a public holiday of
its state, and writes a day note ("Public holiday: ... Opening hours may differ.") on a trip date
that is one.

## What is an estimate

- **Hotel prices.** OpenStreetMap does not know prices, and no free service publishes live
  hotel prices. A price is worked out from the kind of lodging, its star class, its name and
  its location, and is always labelled an estimate. "Check price" opens a search for the place
  on a booking site with the night's date filled in.
- **Public transport without a timetable.** Where no connection is known, a hop of up to one
  kilometre is shown as a walk and a longer leg as the road distance at 30 km/h plus ten minutes
  for reaching the stop and waiting. The map says which legs are estimates. The clock times of
  an itinerary are planned with these estimates even where real connections are shown.
- **Travel times while places are being chosen** (step 1 above). The saved itinerary has road
  figures; they fall back to the estimates only when the routing server does not answer.
- **Entrance fees and visit lengths of imported places** are typical values for the kind of
  place, unless OpenStreetMap gives a fee.
- **Peak-hour factors.** No free source gives rush-hour speeds, so a leg that starts at
  07:00 to 09:30 or 17:00 to 19:30 on a working day, inside one of three boxes drawn by hand
  (the Klang Valley, George Town with Butterworth, Johor Bahru), takes longer by a fixed factor
  for the way of travelling. The boxes, hours and factors are estimates, kept in one place
  (`PlannerCalendar::PEAK_BOXES`, `PEAK_WINDOWS`, `PEAK_FACTORS`); outside the boxes there is no
  factor. A road figure measured by a routing server does not include the rush hour either, so
  the factor is applied to it too.
- **Assumed opening hours.** When OpenStreetMap or the description gives no usable hours for a
  place, the planner assumes the hours that are usual for its type (a temple 08:00 to 18:00, a
  museum 09:00 to 17:00 and so on, `OpeningHours::ASSUMED`). They are marked "assumed" in the
  stop's notes and on the pages, with the advice to check before going. Hours written as words
  ("office hours", "daytime") are read as approximate times, `OpeningHours::DESCRIBED`.
- **Rest days and prayer times.** The weekly rest days of a state (Friday and Saturday in
  Kedah, Kelantan and Terengganu, otherwise Saturday and Sunday) and the Friday prayer break
  of 12:15 to 14:45 for mosques are fixed rules, not data read from a source.
- **Walks to public transport stops.** A walk is the straight line times the road factor at
  5 km/h; a point that is no measured place (the starting point, a hotel) is assumed to be 300 m
  from its stop. These numbers are in `docs/PLANNER.md`.

## Live buses and trains

"Live buses" on the itinerary map shows where the buses and trains are at this moment. It is
on by itself when a trip goes by public transport, and the vehicles of the lines the
traveller's own connections use stand out. Pressing a vehicle says which route it is on, where
it is heading and how fast it is moving, and can draw the route's line.

The positions are open data that the operators report to the national Open API (data.gov.my),
renewed every 30 seconds. In November 2026 that covers:

- Rapid KL buses and MRT feeder buses, and Rapid Penang (Prasarana)
- the KTM trains (KTMB)
- the BAS.MY stage buses of Kangar, Alor Setar, Kota Bharu, Kuala Terengganu, Ipoh, Seremban,
  Melaka, Johor Bahru and Kuching

Rail lines of Rapid KL (LRT, MRT, monorail) do not publish positions yet. A new operator is
one line in the feed list of `services/BusTrackingService.php`.

data.gov.my allows four requests a minute. So only the feeds of the part of the map on show
are asked for (three at most), each at most every 40 seconds, and a counter keeps all
requests together inside the allowance; when it is used up the last known positions stay on
the map a little longer. Route numbers, names, colours and lines come from the operators'
timetable packages, which are read again every three days. No key and no setting is needed.
## Road conditions

"Road conditions now" on the itinerary page shows what is happening on the route of the day
on show, and loads by itself on the day of travel. It is the situation at that moment, never
a forecast.

- **Traffic** (TomTom, optional): incidents on the route itself, and closed or flooded roads
  within two kilometres of it.
- **Weather warnings**: those in force that name the state of the day's stops, and their
  district where the warning lists districts. Warnings for shipping are left out.
- **Fires**: hot spots a satellite saw within five kilometres of the route in the last 24
  hours. A hot spot can be open burning as well as a wildfire.

There is no open live source for river levels in Malaysia at the moment: the flood-warning
list on data.gov.my has not been updated since February 2024, so it is not used. The panel
links to Public InfoBanjir, the official site.

## Being a light user

These services are free because each application uses them lightly. `services/OpenDataHttp.php`
is the only place that calls them: it caches every answer on disk, waits between two requests
to the same server (one a second for Nominatim, OSRM, Valhalla, Transitous and Wikimedia, two
seconds for Overpass), asks
for many items in one request where the service allows it, and waits when a server says "slow
down". A file download (a photo, the list of fires) is not worth that wait: after a refusal,
nothing more is downloaded from that server until the pause it asked for is over (five minutes
when it names none, longer after each further refusal), and the caller goes on without the
file. Do not call the services from anywhere else.

To stop depending on the public routing servers, run your own and set `ROUTING_CAR_URL`,
`ROUTING_FOOT_URL` (OSRM) and `ROUTING_VALHALLA_URL` in `.env`.
