#!/usr/bin/env bash
set -euo pipefail

PROJECT="/var/www/travel-system"
REPO_URL="https://github.com/white22266/Development-Of-a-Smart-Travel-Itinerary-Generator-For-Malaysian-Cultural-Tourism.git"
FAST_MODEL="${1:-qwen2.5:1.5b}"

log() {
  echo
  echo "=== $1 ==="
}

log "1. Check live project folder"
if [ ! -d "$PROJECT" ]; then
  echo "ERROR: Project folder not found: $PROJECT"
  exit 1
fi

cd "$PROJECT"
pwd

log "2. Fix Git safe directory and ownership"
git config --global --add safe.directory "$PROJECT" || true
sudo git config --global --add safe.directory "$PROJECT" || true
sudo chown -R "$USER":"$USER" "$PROJECT"

log "3. Backup important live files"
BACKUP_DIR="$HOME/travel_system_ai_fast_backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

for f in \
  services/AiTravelAssistantService.php \
  api/ai_travel_assistant.php \
  api/ai_hotel_assistant.php \
  api/ai_itinerary_editor.php \
  itinerary/trip_summary.php \
  config/api_keys.php \
  config/db_connect.php \
  .env
 do
  if [ -f "$f" ]; then
    mkdir -p "$BACKUP_DIR/$(dirname "$f")"
    cp "$f" "$BACKUP_DIR/$f"
  fi
done

echo "Backup saved to: $BACKUP_DIR"

log "4. Pull latest GitHub main branch"
git remote set-url origin "$REPO_URL"
git fetch origin main
git reset --hard origin/main

log "5. Configure fast AI environment"
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
set_env_value "GEMINI_TEMPERATURE" "0.15"
set_env_value "GEMINI_TIMEOUT" "4"
set_env_value "OLLAMA_BASE_URL" "http://127.0.0.1:11434"
set_env_value "OLLAMA_MODEL" "$FAST_MODEL"
set_env_value "OLLAMA_NUM_CTX" "512"
set_env_value "OLLAMA_TIMEOUT" "10"
set_env_value "OLLAMA_CONNECT_TIMEOUT" "2"
set_env_value "OLLAMA_NUM_PREDICT" "100"
set_env_value "OLLAMA_NUM_THREAD" "4"
set_env_value "OLLAMA_TEMPERATURE" "0.15"
set_env_value "OLLAMA_KEEP_ALIVE" "30m"

grep -E "^(AI_USE_GEMINI|GEMINI_|OLLAMA_)" .env || true

log "6. Patch backend quick replies before database-heavy processing"
python3 <<'PY'
from pathlib import Path

p = Path("api/ai_travel_assistant.php")
s = p.read_text(encoding="utf-8")

needle = '''if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "answer" => "Invalid request."]);
    exit;
}
'''

patch = '''if ($travellerId <= 0 || $itineraryId <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "answer" => "Invalid request."]);
    exit;
}

$normalizedQuickMessage = strtolower(trim((string)$message));
$normalizedQuickMessage = preg_replace('/[^a-z0-9\\s]/i', '', $normalizedQuickMessage) ?? $normalizedQuickMessage;
$normalizedQuickMessage = trim(preg_replace('/\\s+/', ' ', $normalizedQuickMessage) ?? $normalizedQuickMessage);

if ($action === "chat" && preg_match('/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/i', $normalizedQuickMessage)) {
    echo json_encode([
        "status" => "success",
        "answer" => "Hello. I can help with this trip summary, estimated cost, route, hotel options, and itinerary changes.",
        "pending_action" => null,
        "pending_actions" => [],
        "source" => "quick_greeting"
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === "chat" && preg_match('/^(ok|okay|thanks|thank you|tq|thx)$/i', $normalizedQuickMessage)) {
    echo json_encode([
        "status" => "success",
        "answer" => "You are welcome. Ask me about cost, route, hotel options, or changes to this itinerary.",
        "pending_action" => null,
        "pending_actions" => [],
        "source" => "quick_greeting"
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === "chat" && preg_match('/^(then|next|continue|more|go on|and then|what next|so)$/i', $normalizedQuickMessage)) {
    echo json_encode([
        "status" => "success",
        "answer" => "Please ask a specific travel question, such as cost, route, hotel options, or itinerary changes.",
        "pending_action" => null,
        "pending_actions" => [],
        "source" => "quick_greeting"
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === "chat" && preg_match('/\\b(?:what\\s+(?:can|could)\\s+you\\s+do|what\\s+you\\s+can\\s+do|what\\s+u\\s+can\\s+do|what\\s+can\\s+u\\s+do|what\\s+do\\s+you\\s+do|how\\s+can\\s+you\\s+help|help\\s+me\\s+with)\\b/i', $message)) {
    echo json_encode([
        "status" => "success",
        "answer" => "I can explain the trip summary, check estimated cost and budget, describe routes and travel time, suggest hotel options, and propose itinerary changes. I will only save hotel or route changes after you confirm.",
        "pending_action" => null,
        "pending_actions" => [],
        "source" => "quick_capability"
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
'''

if '"source" => "quick_greeting"' not in s:
    if needle not in s:
        raise SystemExit("Could not find backend insertion point in api/ai_travel_assistant.php")
    s = s.replace(needle, patch, 1)

p.write_text(s, encoding="utf-8")
PY

log "7. Patch frontend quick replies and 15-second timeout"
python3 <<'PY'
from pathlib import Path

p = Path("itinerary/trip_summary.php")
s = p.read_text(encoding="utf-8")

old_start = '''            const loading = addAiMessage('bot', 'Writing answer...');
            try {
'''
new_start = '''            const loading = addAiMessage('bot', 'Writing answer...');
            const quickText = text.toLowerCase().replace(/[^a-z0-9\\s]/g, '').replace(/\\s+/g, ' ').trim();
            if (/^(hi|hello|hey|yo|hai|helo|good morning|good afternoon|good evening)$/.test(quickText)) {
                if (loading) loading.textContent = 'Hello. I can help with this trip summary, estimated cost, route, hotel options, and itinerary changes.';
                return;
            }
            if (/^(ok|okay|thanks|thank you|tq|thx)$/.test(quickText)) {
                if (loading) loading.textContent = 'You are welcome. Ask me about cost, route, hotel options, or changes to this itinerary.';
                return;
            }
            if (/^(then|next|continue|more|go on|and then|what next|so)$/.test(quickText)) {
                if (loading) loading.textContent = 'Please ask a specific travel question, such as cost, route, hotel options, or itinerary changes.';
                return;
            }
            if (/\\b(?:what\\s+(?:can|could)\\s+you\\s+do|what\\s+you\\s+can\\s+do|what\\s+u\\s+can\\s+do|what\\s+can\\s+u\\s+do|what\\s+do\\s+you\\s+do|how\\s+can\\s+you\\s+help|help\\s+me\\s+with)\\b/i.test(text)) {
                if (loading) loading.textContent = 'I can explain the trip summary, check estimated cost and budget, describe routes and travel time, suggest hotel options, and propose itinerary changes. I will only save hotel or route changes after you confirm.';
                return;
            }

            let aiTimeout = null;
            try {
'''

if "quickText = text.toLowerCase()" not in s:
    if old_start not in s:
        raise SystemExit("Could not find frontend quick reply insertion point in itinerary/trip_summary.php")
    s = s.replace(old_start, new_start, 1)

old_fetch = '''                const resp = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
'''
new_fetch = '''                const controller = new AbortController();
                aiTimeout = setTimeout(() => controller.abort(), 15000);
                const resp = await fetch(endpoint, {
                    method: 'POST',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
'''

if "signal: controller.signal" not in s:
    if old_fetch not in s:
        raise SystemExit("Could not find frontend fetch insertion point in itinerary/trip_summary.php")
    s = s.replace(old_fetch, new_fetch, 1)

s = s.replace(
'''                const data = await parseJsonResponse(resp);
                if (loading) loading.textContent = data.answer || data.message || 'AI assistant could not answer this request.';
''',
'''                const data = await parseJsonResponse(resp);
                if (aiTimeout) clearTimeout(aiTimeout);
                if (loading) loading.textContent = data.answer || data.message || 'AI assistant could not answer this request.';
'''
)

s = s.replace(
'''            } catch (e) {
                if (loading) loading.textContent = 'Network error. Please try again.';
            }
''',
'''            } catch (e) {
                if (aiTimeout) clearTimeout(aiTimeout);
                if (loading) loading.textContent = (e && e.name === 'AbortError')
                    ? 'AI took too long to answer. Please ask a shorter travel question.'
                    : 'Network error. Please try again.';
            }
'''
)

p.write_text(s, encoding="utf-8")
PY

log "8. Check PHP syntax"
php -l services/AiTravelAssistantService.php
php -l api/ai_travel_assistant.php
php -l api/ai_hotel_assistant.php
php -l api/ai_itinerary_editor.php
php -l itinerary/trip_summary.php

log "9. Configure Ollama service for warm single-model serving"
sudo mkdir -p /etc/systemd/system/ollama.service.d
sudo tee /etc/systemd/system/ollama.service.d/override.conf >/dev/null <<EOF
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
Environment="OLLAMA_KEEP_ALIVE=30m"
Environment="OLLAMA_NUM_PARALLEL=1"
Environment="OLLAMA_MAX_LOADED_MODELS=1"
EOF

sudo systemctl daemon-reload
sudo systemctl restart ollama || true

log "10. Pull and warm up fast model"
if command -v ollama >/dev/null 2>&1; then
  ollama pull "$FAST_MODEL" || true
  curl -sS http://127.0.0.1:11434/api/generate \
    -H "Content-Type: application/json" \
    -d "{\"model\":\"$FAST_MODEL\",\"prompt\":\"OK\",\"stream\":false,\"keep_alive\":\"30m\",\"options\":{\"num_predict\":1}}" || true
  echo
else
  echo "WARNING: ollama command not found. Install Ollama first."
fi

log "11. Restore Apache ownership and restart Apache"
sudo chown -R www-data:www-data "$PROJECT"
sudo systemctl restart apache2

log "12. Final verification"
cd "$PROJECT"
git log --oneline -3 || true
grep -n "quick_greeting" api/ai_travel_assistant.php || true
grep -n "quickText = text.toLowerCase" itinerary/trip_summary.php || true
grep -E "^(OLLAMA_MODEL|OLLAMA_TIMEOUT|OLLAMA_NUM_PREDICT|OLLAMA_TEMPERATURE|AI_USE_GEMINI)=" .env || true
sudo systemctl status apache2 --no-pager | head -12 || true
sudo systemctl status ollama --no-pager | head -12 || true

echo
echo "DONE. Hard refresh browser with Ctrl + F5."
echo "Test messages: hi | what u can do | then | summarise cost briefly"
