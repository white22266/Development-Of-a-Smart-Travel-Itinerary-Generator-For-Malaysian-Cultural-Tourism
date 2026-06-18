#!/usr/bin/env bash
set -euo pipefail

PROJECT="${1:-/var/www/travel-system}"

if [ ! -d "$PROJECT" ]; then
  echo "ERROR: Project folder not found: $PROJECT"
  exit 1
fi

cd "$PROJECT"

echo "=== Removing user-facing technical AI fallback messages ==="

sudo chown -R "$USER":"$USER" "$PROJECT" 2>/dev/null || true

python3 <<'PY'
from pathlib import Path

project = Path.cwd()

TRAVEL_TIMEOUT = "I need a more focused travel question to give a useful recommendation. Please ask about start date, starting location, route, cost, hotel, or itinerary readiness."
TRAVEL_UNAVAILABLE = "I can still guide your trip using the saved itinerary details. Please ask about preference readiness, starting location, route, cost, or hotel options."
TRAVEL_PREPARE = "I could not prepare that travel guidance. Please ask again with the destination, start date, starting location, or preference."
TRAVEL_INVALID = "I need clearer trip details to guide this itinerary. Please ask about preference, route, cost, hotel, or starting location."
TRAVEL_NETWORK = "I could not complete that travel request. Please ask about cost, route, hotel options, or itinerary readiness."

files = [p for p in project.rglob('*') if p.is_file() and p.suffix.lower() in {'.php', '.js'}]

replacements = {
    "The local AI took too long to answer. Please ask a shorter question, or use qwen2.5:1.5b for faster replies.": TRAVEL_TIMEOUT,
    "The local AI took too long to answer. Please ask a shorter question, or use a smaller Ollama model such as qwen2.5:1.5b for faster replies.": TRAVEL_TIMEOUT,
    "AI service is currently unavailable. Please check whether Ollama is running.": TRAVEL_UNAVAILABLE,
    "AI request could not be prepared. Please try again.": TRAVEL_PREPARE,
    "AI response is invalid. Please try again.": TRAVEL_INVALID,
    "AI assistant could not answer this request.": "I need more travel details to guide this request.",
    "AI took too long to answer. Please ask a shorter travel question.": TRAVEL_TIMEOUT,
    "Network error. Please try again.": TRAVEL_NETWORK,
    "Network error while saving trip date. Please try again.": "I could not save the trip date. Please confirm the date again.",
    "cURL is not enabled.": "I can still help with the trip plan. Please provide your destination, start date, starting location, and preference.",
}

changed = []
for p in files:
    try:
        text = p.read_text(encoding='utf-8')
    except UnicodeDecodeError:
        continue

    original = text
    for old, new in replacements.items():
        text = text.replace(old, new)

    text = text.replace(
        "'answer' => 'AI model is not installed. Please run: ollama pull ' . $this->model,",
        "'answer' => 'I can still guide your trip using the saved itinerary details. Please ask about preference readiness, starting location, route, cost, or hotel options.',"
    )

    text = text.replace(
        "data.answer || data.message || 'I need more travel details to guide this request.'",
        "sanitizeAiReply(data.answer || data.message || 'I need more travel details to guide this request.')"
    )

    if p.name == 'trip_summary.php' and 'function sanitizeAiReply(' not in text:
        marker = "        function addAiMessage(role, text) {\n"
        helper = """        function sanitizeAiReply(text) {
            const raw = String(text || '').trim();
            const technicalPatterns = [
                /local\s+ai/i,
                /ollama/i,
                /qwen/i,
                /model\s+such\s+as/i,
                /smaller\s+model/i,
                /service\s+is\s+currently\s+unavailable/i,
                /check\s+whether/i,
                /not\s+installed/i,
                /curl/i,
                /timeout/i
            ];
            if (!raw || technicalPatterns.some((pattern) => pattern.test(raw))) {
                return 'I need more travel details to guide this request. Please ask about cost, route, hotel options, starting location, or itinerary readiness.';
            }
            return raw;
        }

"""
        if marker in text:
            text = text.replace(marker, helper + marker, 1)

    if text != original:
        p.write_text(text, encoding='utf-8')
        changed.append(str(p.relative_to(project)))

print('Changed files:')
for item in changed:
    print(' - ' + item)

if not changed:
    print('No matching technical fallback strings were found.')
PY

echo "=== PHP syntax check ==="
php -l services/AiTravelAssistantService.php
php -l api/ai_travel_assistant.php
if [ -f itinerary/trip_summary.php ]; then
  php -l itinerary/trip_summary.php
fi

echo "=== Verify no user-facing technical fallback remains ==="
if grep -RInE "local AI|Ollama model|qwen2\.5|smaller model|check whether Ollama|AI model is not installed|cURL is not enabled|AI service is currently unavailable" --include='*.php' --include='*.js' .; then
  echo "WARNING: Some technical strings still exist. Review the grep output above."
else
  echo "OK: No common technical fallback strings found in PHP/JS files."
fi

sudo chown -R www-data:www-data "$PROJECT" 2>/dev/null || true
sudo systemctl restart apache2 2>/dev/null || true

echo "DONE. Hard refresh the browser with Ctrl + F5."
