#!/usr/bin/env bash
set -euo pipefail

PROJECT="${1:-/var/www/travel-system}"

if [ ! -d "$PROJECT" ]; then
  echo "ERROR: Project folder not found: $PROJECT"
  exit 1
fi

cd "$PROJECT"

echo "=== Backup files ==="
cp itinerary/trip_summary.php itinerary/trip_summary.php.bak_ai_routes_$(date +%Y%m%d_%H%M%S)
cp api/ai_travel_assistant.php api/ai_travel_assistant.php.bak_ai_routes_$(date +%Y%m%d_%H%M%S)
cp services/AiTravelAssistantService.php services/AiTravelAssistantService.php.bak_ai_routes_$(date +%Y%m%d_%H%M%S)

python3 <<'PY'
from pathlib import Path
import re

# 1) Fix trip_summary frontend routing and quick replies.
p = Path('itinerary/trip_summary.php')
s = p.read_text(encoding='utf-8')

old = """            addAiMessage('user', text);\n            clearAiPendingCards();\n            const loading = addAiMessage('bot', 'Writing answer...');\n            try {\n"""
new = """            addAiMessage('user', text);\n            clearAiPendingCards();\n\n            const quickText = text.toLowerCase().replace(/[^a-z0-9\\s.]/g, '').replace(/\\s+/g, ' ').trim();\n            if (/^(h+i+|h+i+h+i+|hello+|hey+|yo|hai|helo|good morning|good afternoon|good evening)$/.test(quickText)) {\n                addAiMessage('bot', 'Hello. I can help with this trip summary, hotel confirmation, route plan, estimated cost, and timetable changes.');\n                return;\n            }\n            if (/^(ok|okay|thanks|thank you|tq|thx)$/.test(quickText)) {\n                addAiMessage('bot', 'You are welcome. Ask me about cost, route, hotel options, or itinerary changes.');\n                return;\n            }\n            if (/^(then|next|continue|more|go on|and then|what next|so)$/.test(quickText)) {\n                addAiMessage('bot', 'Please ask a specific travel question, such as changing timetable time, checking route, estimating cost, or confirming a hotel.');\n                return;\n            }\n            if (/\\b(what can you do|what you can do|what u can do|what can u do|how can you help|your features|your function|your capabilities)\\b/.test(quickText)) {\n                addAiMessage('bot', 'I can explain this trip summary, estimate cost, review route and travel time, suggest hotel options, and prepare timetable or itinerary changes for confirmation.');\n                return;\n            }\n\n            const loading = addAiMessage('bot', 'Writing answer...');\n            const controller = new AbortController();\n            const aiTimeout = setTimeout(() => controller.abort(), 15000);\n            try {\n"""
if old in s and 'const quickText = text.toLowerCase()' not in s:
    s = s.replace(old, new, 1)

old_regex = """                const scheduleTimeIntent = /\\b(?:rearrange|arrange|new\\s+timetable|timetable|schedule|reschedule|itinerary|trip|day)\\b.*\\b(?:start|begin)\\b.*\\b(?:at|from)?\\s*\\d{1,2}(?::\\d{2})?\\s*(?:am|pm)\\b/i.test(text)\n                    || /\\b(?:start|begin)\\s+(?:at|from)\\s*\\d{1,2}(?::\\d{2})?\\s*(?:am|pm)\\b/i.test(text);\n"""
new_regex = """                const scheduleTimeIntent = /\\b(?:rearrange|reschedule|retime|change|adjust|move|set|start|begin)\\b.*\\b(?:timetable|schedule|time|itinerary)\\b.*\\d{1,2}(?:(?::|\\.)\\d{2})?\\s*(?:am|pm)\\b/i.test(text)\n                    || /\\b(?:rearrange|reschedule|retime|change|adjust|move|set)\\b.*\\d{1,2}(?:(?::|\\.)\\d{2})?\\s*(?:am|pm)\\b/i.test(text)\n                    || /\\b(?:start|begin)\\s+(?:at|from)\\s*\\d{1,2}(?:(?::|\\.)\\d{2})?\\s*(?:am|pm)\\b/i.test(text);\n"""
if old_regex in s:
    s = s.replace(old_regex, new_regex, 1)

s = s.replace("""                const resp = await fetch(endpoint, {\n                    method: 'POST',\n                    headers: {\n""", """                const resp = await fetch(endpoint, {\n                    method: 'POST',\n                    signal: controller.signal,\n                    headers: {\n""")

s = s.replace("""                const data = await parseJsonResponse(resp);\n                if (loading) loading.textContent = data.answer || data.message || 'AI assistant could not answer this request.';\n""", """                const data = await parseJsonResponse(resp);\n                clearTimeout(aiTimeout);\n                if (loading) loading.textContent = data.answer || data.message || 'Please ask a specific travel question about cost, route, hotel options, or timetable changes.';\n""")

s = s.replace("""            } catch (e) {\n                if (loading) loading.textContent = 'Network error. Please try again.';\n            }\n""", """            } catch (e) {\n                clearTimeout(aiTimeout);\n                if (loading) loading.textContent = 'Please ask a specific travel question about cost, route, hotel options, or timetable changes.';\n            }\n""")

p.write_text(s, encoding='utf-8')

# 2) Fix general travel API fallback and early quick replies.
p = Path('api/ai_travel_assistant.php')
s = p.read_text(encoding='utf-8')

needle = """if ($travellerId <= 0 || $itineraryId <= 0) {\n    http_response_code(400);\n    echo json_encode([\"status\" => \"error\", \"answer\" => \"Invalid request.\"]);\n    exit;\n}\n"""
insert = """\n$quickMessage = strtolower(trim((string)$message));\n$quickMessage = preg_replace('/[^a-z0-9\\s.]/i', '', $quickMessage) ?? $quickMessage;\n$quickMessage = trim(preg_replace('/\\s+/', ' ', $quickMessage) ?? $quickMessage);\n\nif ($action === \"chat\" && preg_match('/^(h+i+|h+i+h+i+|hello+|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i', $quickMessage)) {\n    echo json_encode([\n        \"status\" => \"success\",\n        \"answer\" => \"Hello. I can help with this trip summary, hotel confirmation, route plan, estimated cost, and timetable changes.\",\n        \"pending_action\" => null,\n        \"pending_actions\" => [],\n        \"source\" => \"quick_rule\"\n    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);\n    exit;\n}\n\nif ($action === \"chat\" && preg_match('/^(then|next|continue|more|go on|and then|what next|so)$/i', $quickMessage)) {\n    echo json_encode([\n        \"status\" => \"success\",\n        \"answer\" => \"Please ask a specific travel question, such as changing timetable time, checking route, estimating cost, or confirming a hotel.\",\n        \"pending_action\" => null,\n        \"pending_actions\" => [],\n        \"source\" => \"quick_rule\"\n    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);\n    exit;\n}\n\nif ($action === \"chat\" && preg_match('/\\b(what can you do|what you can do|what u can do|what can u do|how can you help|your features|your function|your capabilities)\\b/i', $quickMessage)) {\n    echo json_encode([\n        \"status\" => \"success\",\n        \"answer\" => \"I can explain this trip summary, estimate cost, review route and travel time, suggest hotel options, and prepare timetable or itinerary changes for confirmation.\",\n        \"pending_action\" => null,\n        \"pending_actions\" => [],\n        \"source\" => \"quick_rule\"\n    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);\n    exit;\n}\n"""
if needle in s and '$quickMessage = strtolower' not in s:
    s = s.replace(needle, needle + insert, 1)

s = s.replace(
    'I can help with this trip, but the local AI service is not available right now. You can still use the rule-based itinerary generator, hotel confirmation, and route tools.',
    'Please ask a specific travel question about this trip, such as cost, route, hotel options, confirmed hotel, or timetable changes.'
)

p.write_text(s, encoding='utf-8')

# 3) Fix service-level fallback and greeting patterns.
p = Path('services/AiTravelAssistantService.php')
s = p.read_text(encoding='utf-8')
s = s.replace(
    "/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i",
    "/^(h+i+|h+i+h+i+|hello+|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i"
)
s = s.replace("getenv('OLLAMA_TEMPERATURE') ?: 0.15", "getenv('OLLAMA_TEMPERATURE') ?: 0.1")
s = s.replace("getenv('GEMINI_TEMPERATURE') ?: 0.15", "getenv('GEMINI_TEMPERATURE') ?: 0.1")
s = s.replace(
    'The local AI took too long to answer. Please ask a shorter question, or use qwen2.5:1.5b for faster replies.',
    'Please ask a specific travel question about cost, route, hotel options, confirmed hotel, or timetable changes.'
)
s = s.replace(
    'AI service is currently unavailable. Please check whether Ollama is running.',
    'Please ask a specific travel question about cost, route, hotel options, confirmed hotel, or timetable changes.'
)
s = s.replace(
    'AI model is not installed. Please run: ollama pull ' . "$this->model",
    'Please ask a specific travel question about cost, route, hotel options, confirmed hotel, or timetable changes.'
)
p.write_text(s, encoding='utf-8')
PY

echo "=== Set fast AI env ==="
touch .env
set_env_value() {
  KEY="$1"
  VALUE="$2"
  if grep -q "^${KEY}=" .env; then
    sed -i "s|^${KEY}=.*|${KEY}=${VALUE}|g" .env
  else
    echo "${KEY}=${VALUE}" >> .env
  fi
}
set_env_value "AI_USE_GEMINI" "false"
set_env_value "OLLAMA_MODEL" "qwen2.5:1.5b"
set_env_value "OLLAMA_TIMEOUT" "10"
set_env_value "OLLAMA_CONNECT_TIMEOUT" "2"
set_env_value "OLLAMA_NUM_CTX" "512"
set_env_value "OLLAMA_NUM_PREDICT" "100"
set_env_value "OLLAMA_NUM_THREAD" "4"
set_env_value "OLLAMA_TEMPERATURE" "0.1"
set_env_value "GEMINI_TEMPERATURE" "0.1"
set_env_value "OLLAMA_KEEP_ALIVE" "30m"

echo "=== Syntax check ==="
php -l itinerary/trip_summary.php
php -l api/ai_travel_assistant.php
php -l services/AiTravelAssistantService.php

echo "=== Restart services ==="
sudo chown -R www-data:www-data "$PROJECT"
sudo systemctl restart apache2
sudo systemctl restart ollama || true

echo "=== Verification ==="
grep -n "const quickText = text.toLowerCase" itinerary/trip_summary.php || true
grep -n "scheduleTimeIntent" itinerary/trip_summary.php | head -3 || true
grep -n "quickMessage = strtolower" api/ai_travel_assistant.php || true
grep -E "^(OLLAMA_MODEL|OLLAMA_TEMPERATURE|OLLAMA_TIMEOUT)=" .env || true

echo "DONE. Hard refresh browser with Ctrl + F5."
