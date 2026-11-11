# Security Setup Notes

This project does not store SMTP passwords or database passwords in PHP configuration files. It needs no API keys:
maps, place data, routing and weather come from open services (see `docs/OPEN_DATA.md`).

One optional feature has a key: live traffic from TomTom (`TOMTOM_API_KEY`). Treat it like a password. It belongs in
`.env` only, it is sent from the server to api.tomtom.com and never to the browser, and it must not be committed.

## 1. Create local environment file

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

Then fill in your real local values:

```env
DB_HOST=localhost
DB_USER=root
DB_PASS=
DB_NAME=travel_itinerary_db

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your_email@gmail.com
SMTP_PASS=your_new_gmail_app_password
SMTP_FROM=your_email@gmail.com
SMTP_FROM_NAME=Admin Smart Travel Itinerary Generator

OLLAMA_MODEL=qwen2.5:3b
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_NUM_CTX=4096

OPEN_DATA_CONTACT=your_site_address_or_email
```

Do not commit `.env`.

## 2. Revoke exposed credentials

Because old credentials were previously committed to the public repository, deal with them now:

- Google Maps API key: the system no longer uses it. Delete the key and the Google Cloud project.
- OpenWeather API key: no longer used. Delete it.
- SerpAPI key: no longer used. Delete it.
- Gmail App Password: revoke it and create a new one for `SMTP_PASS`.

Removing the value from the latest commit is not enough if it already exists in Git history.

## 3. Database dump warning

Do not commit SQL exports that contain real users, emails, phone numbers, password hashes, reset tokens, or chat logs.

Recommended structure:

```text
schema.sql      # CREATE TABLE only
seed_demo.sql   # fake demo data only
```

## 4. CSRF protection

`config/security.php` provides CSRF helper functions:

```php
require_once __DIR__ . '/../config/security.php';
echo csrf_field();
verify_csrf_token();
```

Use `csrf_field()` inside every POST form and call `verify_csrf_token()` at the top of every POST-processing script.
