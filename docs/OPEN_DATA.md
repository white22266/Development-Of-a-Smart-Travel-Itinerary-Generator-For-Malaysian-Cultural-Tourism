# Open data in this system

Since November 2026 this system uses no paid service and no API key for maps, places,
search, routing or weather. Everything it needs to work is free and open, and none of it
needs an account.

Two things are optional and off until they are set up: real public transport connections
(free and open, but the public server has conditions) and live traffic (TomTom, which is
**not** open data: a commercial service with a free monthly allowance and a key).

## What comes from where

| Feature | Source | Licence | Credit shown |
|---|---|---|---|
| Map pictures (tiles) | OpenStreetMap | ODbL | bottom corner of every map |
| Places, opening hours, phone, website | OpenStreetMap (Overpass API) | ODbL | place page, "Sources" |
| Place descriptions | Wikipedia, English (found through Wikidata) | CC BY-SA 4.0 | place page, "Sources", with a link to the article |
| Place photos | Wikimedia Commons | per photo (CC BY, CC BY-SA, public domain) | place page, "Sources": author, licence, link |
| Hotels and other lodging | OpenStreetMap | ODbL | "from OpenStreetMap listings" |
| Address search, "what is here" | Photon, then Nominatim (OpenStreetMap data) | ODbL | - |
| Routes by car and on foot, order of the stops | OSRM on OpenStreetMap data (FOSSGIS servers) | ODbL | map info box |
| Routes by motorcycle | Valhalla on OpenStreetMap data (FOSSGIS server) | ODbL | map info box |
| Weather forecast | Open-Meteo | CC BY 4.0 | map info box and forecast answers |
| Weather warnings | Malaysian Meteorological Department, through data.gov.my | open government data | road conditions panel |
| Fires | NASA FIRMS (NOAA-20 VIIRS hot spots, last 24 hours) | open, credit asked for | road conditions panel |
| Live positions of buses and trains | the operators (Prasarana, KTMB, BAS.MY), through data.gov.my | CC BY 4.0 | under the map |
| Public transport connections (optional) | a MOTIS server such as Transitous, on the operators' published timetables | per timetable | details of each leg |
| Live traffic (optional) | TomTom Traffic Incidents API | **commercial, not open** | road conditions panel: "© TomTom" |
| Map library | Leaflet 1.9.4 | BSD-2-Clause | - |
| AI answers | Ollama with a local model | see the model's licence | - |

The credits are part of the licences. Keep them when changing a page.

## Setting it up

1. Run the migrations in `database/migrations/` on the database (they can be run again safely).
2. In `.env`, set `OPEN_DATA_CONTACT` to a web address or e-mail address. The open services
   ask every application to say who runs it; it is sent with each request to them.
3. Fill the place and lodging tables for the first time:

   ```bash
   php tools/sync_places.php --state="Melaka" --dry-run   # see what would change, save nothing
   php tools/sync_places.php --all                        # every state, one after another
   ```

4. Schedule `php tools/sync_places.php --all` once a week (Windows Task Scheduler:
   `C:\xampp\php\php.exe C:\path\to\project\tools\sync_places.php --all`).

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

Running the job again never creates duplicates. Answers from the open services are cached in
`storage/cache/`, so a second run within a few days makes almost no requests.

## Routes: how the best order is found

A day's stops are visited in the order with the least travelling for the trip's transport.

1. While a trip is planned, places are chosen with estimated travel times (straight line x 1.3
   at a typical speed): hundreds of possible legs are compared, too many to ask a server about.
2. When the plan is complete, `ItineraryRouteOptimiser` asks the routing server once for the
   road time between every pair of the trip's points, compares every possible order of each
   day's stops (up to eight stops; beyond that the order is improved step by step) and times
   the days again. Car, walking and public transport are measured by OSRM, motorcycles by
   Valhalla's motorcycle profile, so each way of travelling can end up with its own order.
3. The shortest order is not used when it reaches a place while it is closed on that weekday,
   reaches an outdoor place between 11 am and 4 pm for a traveller who asked to avoid heat, or
   puts two meals one right after the other. The next shortest order without such a fault is.
4. A traveller can run the same step again with "Optimise route" on the itinerary page, for
   example after replacing a stop or choosing hotels (a day then ends at its hotel).

The public servers have limits the code is built around: OSRM takes fifty points in one
request but makes a client wait about ten seconds before the next one, so a whole trip goes
into one request; Valhalla answers at once but takes ten points, so each day is one request.

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
to the same server (one a second for Nominatim, OSRM, Valhalla, Transitous and Wikimedia), asks
for many items in one request where the service allows it, and waits when a server says "slow
down". Do not call the services from anywhere else.

To stop depending on the public routing servers, run your own and set `ROUTING_CAR_URL`,
`ROUTING_FOOT_URL` (OSRM) and `ROUTING_VALHALLA_URL` in `.env`.
