# Smart Travel Itinerary Generator for Malaysian Cultural Tourism

A self-hosted web application that builds day-by-day itineraries for cultural tourism in
Malaysia. A traveller gives a budget, a way of travelling, interests, states and a few
preferences; the system picks cultural places and food, puts the stops in a good order, shows
the routes on a map and exports the plan. An admin side looks after the cultural knowledge base,
place imports, suggestions, users and reports.

It began as a final-year project and is now kept as a clean, working system. It uses only free
and open services (OpenStreetMap, Wikipedia, Open-Meteo and others), no Google service, and a
local AI model (Ollama) for the optional AI features. Plans are made by rules, not by an AI
model.

## Features

For travellers:

- Sign up and sign in; the pages are in English, Malay and Simplified Chinese.
- A preference form: budget and budget tier, transport (car, motorcycle, public transport,
  walking), traveller type, adults, children and seniors, pace, day start and end time, stops
  per day, interests with weights, dietary needs, accessibility tick boxes, states and
  districts, and places to see or to avoid.
- A starting point, with an optional end point for the trip.
- A generated itinerary with lunch and dinner rows, road times and distances, weather-aware
  choices on rainy days, and a note where a day or a must-see place could not be planned.
- Editing a saved itinerary: replace, remove, move or add a stop, plan a day again, set a day's
  start time, re-plan rainy days, optimise the route, choose a hotel for each night. A change
  that breaks a rule is refused with the reason.
- An AI assistant that understands edit requests in English, Malay and Chinese (local Ollama
  model, with a fallback that needs no model).
- Map with routes, live buses and trains, road conditions, and map app links for each stop.
- Export as a PDF (English or Malay) and as a calendar file (.ics); share a trip by link.
- A cultural guide with place pages, reviews and ratings (the planner learns from a
  traveller's own ratings), and place suggestions to the admin.

For admins:

- A dashboard, the cultural knowledge base, and users (`admin/`).
- Pending place suggestions and reports, with analysis by the local AI model.
- Open Data Imports: places found by the place sync wait here for review, with automatic
  changes that can be undone and possible duplicates.

## Requirements

- Windows with XAMPP (Apache, PHP 8.2, MariaDB 10.4). Other systems work in principle but are
  not tested.
- Composer, to install the two PHP libraries (dompdf and PHPMailer).
- Optional: Ollama with a small model (`qwen2.5:3b`) for the AI features.
- Internet access for maps, the place sync, routes and weather.

## Installation on XAMPP

1. Put the project folder in `C:\xampp\htdocs\`. Start Apache and MariaDB (`StartFYP.bat` starts
   them, or use the XAMPP control panel).
2. Install the PHP libraries: `composer install` in the project folder.
3. Create the settings file: copy `.env.example` to `.env` and fill it in. Never commit `.env`.
   The keys are:
   - `APP_ENV`, `APP_URL`, `APP_TIMEZONE`
   - `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`
   - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_FROM`, `SMTP_FROM_NAME` (password
     reset mail)
   - `OLLAMA_MODEL`, `OLLAMA_BASE_URL`, `OLLAMA_NUM_CTX`, `OLLAMA_TIMEOUT`,
     `OLLAMA_CONNECT_TIMEOUT`, `OLLAMA_KEEP_ALIVE`
   - `OPEN_DATA_CONTACT` (a web address or e-mail address that open services ask for),
     `PLACE_SYNC_AUTO_PUBLISH_MIN`
   - `ROUTING_CAR_URL`, `ROUTING_FOOT_URL`, `ROUTING_VALHALLA_URL`, and the optional
     `ROUTING_ESTIMATES_ONLY`
   - `TRANSIT_API_URL`, `TOMTOM_API_KEY`, `TOMTOM_MONTHLY_LIMIT` (optional)

   `SECURITY_SETUP.md` explains the settings file and CSRF protection.
4. Create the database. Choose one of two ways.

   **Clean install (use this for a new or public copy).** It holds no accounts and no personal
   data. Create an empty database whose name is your `DB_NAME`, then load the schema and the
   reference rows (the 16 states, the 7 interests and the list of applied migrations):

   ```
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE travel_itinerary_db CHARACTER SET utf8mb4"
   C:\xampp\mysql\bin\mysql.exe -u root travel_itinerary_db < database\schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root travel_itinerary_db < database\seed_reference.sql
   ```

   Add the `-p` option if the database account has a password. The place tables are empty
   until step 6. The first admin has to be added by hand because there is no sample account:
   make a password hash with
   `C:\xampp\php\php.exe -r "echo password_hash('CHOOSE-A-PASSWORD', PASSWORD_DEFAULT);"` and run
   `INSERT INTO admins (username, email, password_hash) VALUES ('admin', 'you@example.com', '<the hash>');`.
   Travellers register themselves on the site.

   **Full dump.** `travel_itinerary_db.sql` is the database of the final-year project with
   sample accounts and sample data. It is useful for a demonstration on your own machine. It
   holds sample accounts, so do not use it for a public installation.

   `database/schema.sql` and `database/seed_reference.sql` are written by
   `tools/export_schema.php` from the test copy; run it again after adding a migration.
5. Apply the migrations: `C:\xampp\php\php.exe tools\migrate.php`. `--status` lists what is
   applied and what is pending. The clean install already records every current file, so
   nothing is pending; a database from the full dump may need some. A database whose name does
   not end in `_ctest` needs `--confirm=<database name>`.
6. Fill the place tables (first time). Set `OPEN_DATA_CONTACT` first.

   ```
   C:\xampp\php\php.exe tools\sync_places.php --state="Melaka" --dry-run
   C:\xampp\php\php.exe tools\sync_places.php --all --no-publish
   ```

   `--dry-run` saves nothing. Without `--no-publish` the sync also publishes the places that
   score at least `PLACE_SYNC_AUTO_PUBLISH_MIN`; with it every new place waits for review on the
   admin page *Open Data Imports*. The run takes a long time for all states and caches what it
   downloads in `storage/cache/`.
7. Optional, local AI: install Ollama, run `ollama pull qwen2.5:3b` and keep
   `OLLAMA_BASE_URL` on `127.0.0.1`. Without it the AI answers are unavailable; planning and
   editing still work.
8. Open `http://localhost/Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism/`.

### The weekly place sync

`tools\sync_places_weekly.bat` runs the place sync for every state with `--no-publish` through
`C:\xampp\php\php.exe`, from the folder it sits in, and appends the output to a dated log,
`storage\logs\sync_places_<date>.log` (the folder is created when missing and is ignored by
git). It works on the database named in `.env`, and it only stages new places for review: an
admin publishes them on *Open Data Imports*. Fields that were missing on existing places are
filled in, and each change is listed there and can be undone.

To run it every Sunday at 03:00, register it once in Windows Task Scheduler (run this in a
command prompt as the user who owns the site):

```
schtasks /Create /SC WEEKLY /D SUN /ST 03:00 /TN "Smart Travel place sync" /TR "C:\xampp\htdocs\Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism\tools\sync_places_weekly.bat"
```

Remove it with `schtasks /Delete /TN "Smart Travel place sync"`. The task does not need Apache, but MariaDB must be running when it starts.

## Checks

- Syntax of a PHP file: `C:\xampp\php\php.exe -l <file>`.
- The planner: with the test copy of the database,
  `DB_NAME=travel_itinerary_db_ctest C:\xampp\php\php.exe tools\check_planner.php` in Git Bash
  (in PowerShell set `$env:DB_NAME = 'travel_itinerary_db_ctest'` first). It generates fixed
  scenarios and checks the stored plans against the rules. It refuses to run on a database whose
  name does not end in `_ctest`, and makes no network request unless `--road` is given.
  `docs/PLANNER.md` explains each check code.
- Migrations: `C:\xampp\php\php.exe tools\migrate.php --status`.

Keep a separate copy of the database named `travel_itinerary_db_ctest` for every test.

## Documents

| Document | What it is |
|---|---|
| `docs/PLANNER.md` | How a plan is made: rules, numbers, edit operations, the planner check |
| `docs/OPEN_DATA.md` | Data sources and licences, the place sync, routing, live transport, estimates |
| `SECURITY_SETUP.md` | The settings file, credentials and CSRF |
| `AGENTS.md` | Short notes and project rules for coding agents (`CLAUDE.md` imports it) |
| `DEVELOPER_HANDOVER_REPORT.md`, `CHANGES_DOCUMENTATION.md`, `SV_MODULE_COMPLIANCE_AUDIT.md`, `Tutorial_Readme_SmartTravelItineraryGenerator.txt` | Historical documents from the final-year project. They describe the earlier system, which still used Google Maps, and are kept unchanged. Where they disagree with this page, `docs/PLANNER.md` or `docs/OPEN_DATA.md`, those win |

## Licence and credits

The repository has no licence file yet; the owner decides which licence to add. Map, place,
photo, route, weather and transport data come from open sources under their own licences, and
the credits are conditions of those licences: they are shown on the pages and in the PDF and
must stay there. `docs/OPEN_DATA.md` lists every source, its licence and the credit that must
appear. The one non-open part is the optional TomTom traffic key.
