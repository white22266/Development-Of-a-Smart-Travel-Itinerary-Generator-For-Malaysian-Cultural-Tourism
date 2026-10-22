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
import re

project = Path.cwd()

TRAVEL_TIMEOUT = "I need a more focused travel question to give a useful recommendation. Please ask about start date, starting location, route, cost, hotel, or itinerary readiness."
TRAVEL_UNAVAILABLE = "I can still guide your trip using the saved itinerary details. Please ask about preference readiness, starting location, route, cost, or hotel options."
TRAVEL_PREPARE = "I could not prepare that travel guidance. Please ask again with the destination, start date, starting location, or preference."
TRAVEL_INVALID = "I need clearer trip details to guide this itinerary. Please ask about preference, route, cost, hotel, or starting location."
TRAVEL_NETWORK = "I could not complete that travel request. Please ask about cost, route, hotel options, or itinerary readiness."
TRAVEL_DETAILS = "I need more travel details to guide this request. Please ask about cost, route, hotel options, starting location, or itinerary readiness."
TRAVEL_ASSISTANT_GREETING = "Hi, I am your travel assistant. Ask for hotel recommendations, cost checks, route explanations, or itinerary changes. I will not save hotel or route changes unless you confirm them."

files = [p for p in project.rglob('*') if p.is_file() and p.suffix.lower() in {'.php', '.js'}]

literal_replacements = {
    # Timeout/model/server wording shown to travellers.
    "The local AI took too long to answer. Please ask a shorter question, or use qwen2.5:1.5b for faster replies.": TRAVEL_TIMEOUT,
    "The local AI took too long to answer. Please ask a shorter question, or use a smaller Ollama model such as qwen2.5:1.5b for faster replies.": TRAVEL_TIMEOUT,
    "AI service is currently unavailable. Please check whether Ollama is running.": TRAVEL_UNAVAILABLE,
    "AI service is currently unavailable. Please try again later.": TRAVEL_UNAVAILABLE,
    "AI service is currently unavailable.": TRAVEL_UNAVAILABLE,
    "AI assistant could not answer this request.": "I need more travel details to guide this request.",
    "AI request could not be prepared. Please try again.": TRAVEL_PREPARE,
    "AI response is invalid. Please try again.": TRAVEL_INVALID,
    "AI response is invalid.": TRAVEL_INVALID,
    "AI took too long to answer. Please ask a shorter travel question.": TRAVEL_TIMEOUT,
    "Network error. Please try again.": TRAVEL_NETWORK,
    "Network error while saving trip date. Please try again.": "I could not save the trip date. Please confirm the date again.",
    "cURL is not enabled.": "I can still help with the trip plan. Please provide your destination, start date, starting location, and preference.",
    "cURL is not enabled in PHP. Enable extension=curl in php.ini and restart Apache.": "Report guidance is not available right now. Please review the saved report data and try again later.",
    # UI labels: keep the agent professional, do not expose implementation.
    "Hi, I am your local AI assistant. Ask for hotel recommendations, cost checks, route explanation, or itinerary changes. I will not save hotel or route changes unless you click a confirmation button.": TRAVEL_ASSISTANT_GREETING,
    "Hi, I am your local AI chatbot. Ask me about this generated itinerary, route order, budget, hotel choice, or places to replace before you confirm.": "Hi, I am your travel assistant. Ask me about this generated itinerary, route order, budget, hotel choice, or places to replace before you confirm.",
    "Chat with the local AI before confirming this itinerary.": "Chat with the travel assistant before confirming this itinerary.",
    "local AI assistant": "travel assistant",
    "local AI chatbot": "travel assistant",
    "local AI": "travel assistant",
    "You are a local AI travel assistant for a Malaysian cultural tourism itinerary system.": "You are a professional travel assistant for a Malaysian cultural tourism itinerary system.",
}

regex_replacements = [
    # PHP return strings that concatenate a model name.
    (
        re.compile(r"['\"]message['\"]\s*=>\s*['\"]AI model is not installed\. Please run: ollama pull\s*['\"]\s*\.\s*\$[A-Za-z_][A-Za-z0-9_>\-]*"),
        "\"message\" => \"I can still guide your trip using the saved itinerary details. Please ask about preference readiness, starting location, route, cost, or hotel options.\""
    ),
    (
        re.compile(r"['\"]answer['\"]\s*=>\s*['\"]AI model is not installed\. Please run: ollama pull\s*['\"]\s*\.\s*\$[A-Za-z_][A-Za-z0-9_>\-]*"),
        "\"answer\" => \"I can still guide your trip using the saved itinerary details. Please ask about preference readiness, starting location, route, cost, or hotel options.\""
    ),
    # Plain quoted variants.
    (
        re.compile(r"AI model is not installed\. Please run: ollama pull[^'\";]*"),
        TRAVEL_UNAVAILABLE
    ),
    (
        re.compile(r"I can help with this trip, but the travel assistant service is not available right now\. You can still use the rule-based itinerary generator, hotel confirmation, and route tools\."),
        "I can still guide this trip using the saved itinerary details. Ask about route, cost, hotel options, starting location, or itinerary readiness."
    ),
    (
        re.compile(r"I can help with this trip, but the local AI service is not available right now\. You can still use the rule-based itinerary generator, hotel confirmation, and route tools\."),
        "I can still guide this trip using the saved itinerary details. Ask about route, cost, hotel options, starting location, or itinerary readiness."
    ),
]

changed = []
for p in files:
    try:
        text = p.read_text(encoding='utf-8')
    except UnicodeDecodeError:
        continue

    original = text
    for old, new in literal_replacements.items():
        text = text.replace(old, new)

    for pattern, replacement in regex_replacements:
        text = pattern.sub(replacement, text)

    # Sanitize frontend replies in the trip summary assistant.
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
if [ -f services/AccessibilityNeedsAnalysisService.php ]; then
  php -l services/AccessibilityNeedsAnalysisService.php
fi
if [ -f services/AiAdminReportAnalysisService.php ]; then
  php -l services/AiAdminReportAnalysisService.php
fi
php -l api/ai_travel_assistant.php
if [ -f api/ai_preference_chat.php ]; then
  php -l api/ai_preference_chat.php
fi
if [ -f itinerary/trip_summary.php ]; then
  php -l itinerary/trip_summary.php
fi
if [ -f itinerary/select_preference.php ]; then
  php -l itinerary/select_preference.php
fi

echo "=== Verify no user-facing technical fallback remains ==="
# This intentionally excludes internal model defaults such as OLLAMA_MODEL or qwen2.5 in config files.
if grep -RInE "local AI|smaller Ollama model|smaller model such as|check whether Ollama|AI model is not installed|cURL is not enabled|AI service is currently unavailable|local AI service|took too long to answer.*qwen|ollama pull" \
  --include='*.php' --include='*.js' \
  --exclude='api_keys.php' --exclude='ollama_health.php' --exclude='test_ollama.php' \
  .; then
  echo "WARNING: Some user-facing technical fallback strings still exist. Review the grep output above."
else
  echo "OK: No common user-facing technical fallback strings found in PHP/JS files."
fi

sudo chown -R www-data:www-data "$PROJECT" 2>/dev/null || true
sudo systemctl restart apache2 2>/dev/null || true

echo "DONE. Hard refresh the browser with Ctrl + F5."
