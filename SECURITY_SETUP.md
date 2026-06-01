# Security Setup Notes

This project no longer stores API keys, SMTP passwords, or database passwords directly in PHP configuration files.

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

GOOGLE_MAPS_API_KEY=your_new_google_maps_key
OPENWEATHER_API_KEY=your_new_openweather_key

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=your_email@gmail.com
SMTP_PASS=your_new_gmail_app_password
SMTP_FROM=your_email@gmail.com
SMTP_FROM_NAME=Admin Smart Travel Itinerary Generator

OLLAMA_MODEL=qwen2.5:3b
OLLAMA_BASE_URL=http://localhost:11434
```

Do not commit `.env`.

## 2. Revoke exposed credentials

Because old credentials were previously committed to the public repository, delete or regenerate them immediately:

- Google Maps API key
- OpenWeather API key
- Gmail App Password

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
