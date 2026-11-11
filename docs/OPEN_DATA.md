# Open data in this system

Since November 2026 this system uses no paid service and no API key for maps, places,
search, routing or weather. Everything below is free and open; none of it needs an account.

## What comes from where

| Feature | Source | Licence | Credit shown |
|---|---|---|---|
| Map pictures (tiles) | OpenStreetMap | ODbL | bottom corner of every map |
| Places, opening hours, phone, website | OpenStreetMap (Overpass API) | ODbL | place page, "Sources" |
| Place descriptions | Wikipedia, English (found through Wikidata) | CC BY-SA 4.0 | place page, "Sources", with a link to the article |
| Place photos | Wikimedia Commons | per photo (CC BY, CC BY-SA, public domain) | place page, "Sources": author, licence, link |
| Hotels and other lodging | OpenStreetMap | ODbL | "from OpenStreetMap listings" |
| Address search, "what is here" | Photon, then Nominatim (OpenStreetMap data) | ODbL | - |
| Road routes and directions | OSRM on OpenStreetMap data (FOSSGIS servers) | ODbL | map info box |
| Weather forecast | Open-Meteo | CC BY 4.0 | map info box and forecast answers |
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

## What is an estimate

- **Hotel prices.** OpenStreetMap does not know prices. A price is worked out from the kind of
  lodging, its star class, its name and its location, and is always labelled an estimate.
- **Travel times while planning.** The planner compares hundreds of possible legs, so it uses
  a distance estimate (straight line x 1.3 at a typical speed). The map shows the real road
  route and its time; the two can differ, especially in town centres with one-way streets.
- **Public transport.** No free timetable service covers Malaysia. The map shows the road
  distance with an estimated time and says so.
- **Entrance fees and visit lengths of imported places** are typical values for the kind of
  place, unless OpenStreetMap gives a fee.

## Being a light user

These services are free because each application uses them lightly. `services/OpenDataHttp.php`
is the only place that calls them: it caches every answer on disk, waits between two requests
to the same server (one a second for Nominatim, OSRM and Wikimedia), asks for many items in one
request where the service allows it, and waits when a server says "slow down". Do not call the
services from anywhere else.

To stop depending on the public routing servers, run your own OSRM server and set
`ROUTING_CAR_URL` and `ROUTING_FOOT_URL` in `.env`.
