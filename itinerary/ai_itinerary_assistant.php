<?php
// itinerary/ai_itinerary_assistant.php
// Controlled AI itinerary recommendation page powered by local Ollama.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$ollamaModel = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen3:8b";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Itinerary Assistant</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        .assistant-grid {
            display: grid;
            grid-template-columns: minmax(320px, 430px) 1fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 1050px) {
            .assistant-grid { grid-template-columns: 1fr; }
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (max-width: 640px) {
            .form-row { grid-template-columns: 1fr; }
        }
        .field { margin-bottom: 13px; }
        .field label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            margin-bottom: 6px;
        }
        .field input,
        .field select,
        .field textarea {
            width: 100%;
            border: 1px solid rgba(15,23,42,0.12);
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 13px;
            background: #fff;
            box-sizing: border-box;
        }
        .field textarea {
            min-height: 88px;
            resize: vertical;
        }
        .category-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .category-grid label {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 9px;
            border: 1px solid rgba(15,23,42,0.10);
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            cursor: pointer;
        }
        .category-grid input { width: auto; }
        .status-box {
            display: none;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }
        .status-box.show { display: block; }
        .status-box.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .status-box.ok {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .status-box.info {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
        }
        .result-empty {
            min-height: 360px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
            border: 1px dashed rgba(15,23,42,0.18);
            border-radius: 12px;
            background: #f8fafc;
            padding: 24px;
        }
        .ai-summary {
            background: #f8fafc;
            border: 1px solid rgba(15,23,42,0.08);
            border-radius: 12px;
            padding: 13px 14px;
            margin-bottom: 13px;
        }
        .ai-summary h3 { margin: 0 0 6px; font-size: 16px; }
        .ai-summary p { margin: 0; color: #475569; line-height: 1.55; }
        .day-card {
            border: 1px solid rgba(15,23,42,0.10);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 12px;
            background: #fff;
        }
        .day-card h4 {
            margin: 0 0 8px;
            font-size: 15px;
            color: #4f46e5;
        }
        .route-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 8px 0 10px;
        }
        .route-chip {
            background: #eef2ff;
            color: #3730a3;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 800;
        }
        .place-list {
            display: grid;
            gap: 8px;
            margin: 10px 0;
        }
        .place-item {
            border-left: 4px solid #6366f1;
            background: #f8fafc;
            border-radius: 8px;
            padding: 9px 10px;
        }
        .place-item strong { display: block; color: #0f172a; margin-bottom: 3px; }
        .place-item span { display: block; font-size: 12px; color: #64748b; line-height: 1.45; }
        .day-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            margin-top: 10px;
        }
        @media (max-width: 640px) {
            .day-meta { grid-template-columns: 1fr; }
        }
        .day-meta div {
            background: #fff7ed;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 12px;
            color: #7c2d12;
        }
        .raw-response {
            white-space: pre-wrap;
            line-height: 1.55;
            color: #475569;
            background: #f8fafc;
            border-radius: 12px;
            padding: 14px;
        }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-badge">ST</div>
            <div class="brand-title">
                <strong>Smart Travel Itinerary Generator</strong>
                <span>AI Itinerary Assistant</span>
            </div>
        </div>

        <nav class="nav" aria-label="Sidebar Navigation">
            <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
            <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
            <a class="active" href="ai_itinerary_assistant.php"><span class="dot"></span> AI Itinerary Assistant</a>
            <a href="../itinerary/select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
            <a href="../itinerary/my_itineraries.php"><span class="dot"></span> Cost Estimation and Trip Summary</a>
            <a href="../cultural/cultural_guide.php"><span class="dot"></span> Cultural Guide Presentation</a>
            <a href="../auth/profile/profile.php"><span class="dot"></span> Profile</a>
            <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
        </nav>

        <div class="sidebar-footer">
            <div class="small">Logged in as:</div>
            <div style="margin-top:6px; font-weight:800;"><?php echo htmlspecialchars($travellerName); ?></div>
            <div class="chip">Role: Traveller</div>
        </div>
    </aside>

    <main class="content">
        <div class="topbar">
            <div class="page-title">
                <h1>AI Itinerary Assistant</h1>
                <p>Generate a draft itinerary with local Ollama AI. Review first, then save only when you confirm.</p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="select_preference.php">Back</a>
            </div>
        </div>

        <div class="assistant-grid">
            <div class="card">
                <h3 style="margin-bottom:6px;">Trip Request</h3>
                <p class="meta" style="margin-top:0;">Local model: <?php echo htmlspecialchars($ollamaModel); ?></p>

                <div id="statusBox" class="status-box"></div>

                <form id="aiForm">
                    <div class="field">
                        <label for="destination">Destination state *</label>
                        <input type="text" id="destination" name="destination" placeholder="Example: Johor" required>
                    </div>

                    <div class="field">
                        <label for="start_location">Travel start location *</label>
                        <input type="text" id="start_location" name="start_location" placeholder="Example: Kuala Lumpur" required>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="days">Number of days *</label>
                            <input type="number" id="days" name="days" min="1" max="7" value="3" required>
                        </div>
                        <div class="field">
                            <label for="travel_style">Travel style</label>
                            <select id="travel_style" name="travel_style">
                                <option value="budget">Budget</option>
                                <option value="normal" selected>Normal</option>
                                <option value="luxury">Luxury</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label for="budget_min">Min budget (RM) *</label>
                            <input type="number" id="budget_min" name="budget_min" min="0" step="0.01" value="300" required>
                        </div>
                        <div class="field">
                            <label for="budget_max">Max budget (RM) *</label>
                            <input type="number" id="budget_max" name="budget_max" min="1" step="0.01" value="800" required>
                        </div>
                    </div>

                    <div class="field">
                        <label>Preferred categories</label>
                        <div class="category-grid">
                            <label><input type="checkbox" name="categories[]" value="culture" checked> Culture</label>
                            <label><input type="checkbox" name="categories[]" value="nature"> Nature</label>
                            <label><input type="checkbox" name="categories[]" value="food" checked> Food</label>
                            <label><input type="checkbox" name="categories[]" value="shopping"> Shopping</label>
                            <label><input type="checkbox" name="categories[]" value="family"> Family</label>
                            <label><input type="checkbox" name="categories[]" value="historical"> Historical</label>
                        </div>
                    </div>

                    <div class="field">
                        <label for="transport_mode">Transport mode</label>
                        <select id="transport_mode" name="transport_mode">
                            <option value="car">Car</option>
                            <option value="public_transport">Public transport</option>
                            <option value="walking">Walking</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="special_notes">Special notes</label>
                        <textarea id="special_notes" name="special_notes" placeholder="Example: avoid very expensive attractions, include halal food, family-friendly pace"></textarea>
                    </div>

                    <div class="actions" style="justify-content:flex-start; padding-left:0;">
                        <button type="submit" class="btn btn-primary" id="btnGenerate">Generate AI Itinerary</button>
                        <button type="button" class="btn btn-ghost" id="btnSave" style="display:none;">Save Itinerary</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:12px;">
                    <div>
                        <h3 style="margin:0;">AI Recommendation</h3>
                        <p class="meta" style="margin:4px 0 0;">The assistant suggests a draft only. Confirm before saving.</p>
                    </div>
                    <span class="chip" id="sourceChip">Source: Ollama</span>
                </div>
                <div id="resultArea" class="result-empty">
                    Enter your trip details and generate a draft itinerary.
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const form = document.getElementById('aiForm');
const statusBox = document.getElementById('statusBox');
const resultArea = document.getElementById('resultArea');
const btnGenerate = document.getElementById('btnGenerate');
const btnSave = document.getElementById('btnSave');
let latestAiResponse = '';

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearStatus();
    latestAiResponse = '';
    btnSave.style.display = 'none';

    const validation = validateForm();
    if (validation) {
        showStatus(validation, 'error');
        return;
    }

    btnGenerate.disabled = true;
    btnGenerate.textContent = 'Generating...';
    resultArea.className = 'result-empty';
    resultArea.textContent = 'Ollama is generating your itinerary. This can take a while on the first run.';

    try {
        const resp = await fetch('../api/ai_itinerary.php', {
            method: 'POST',
            body: new FormData(form),
        });
        const data = await parseJsonResponse(resp);
        if (data.status !== 'success') {
            showStatus(data.message || 'AI response is invalid. Please try again.', 'error');
            resultArea.className = 'result-empty';
            resultArea.textContent = 'No valid itinerary generated yet.';
            return;
        }

        latestAiResponse = data.ai_response || JSON.stringify(data.itinerary || {});
        renderItinerary(data.itinerary);
        btnSave.style.display = 'inline-flex';
        showStatus('AI itinerary generated. Review it before saving.', 'ok');
    } catch (e) {
        showStatus('AI service is currently unavailable. Please make sure Ollama is running.', 'error');
        resultArea.className = 'result-empty';
        resultArea.textContent = 'No valid itinerary generated yet.';
    } finally {
        btnGenerate.disabled = false;
        btnGenerate.textContent = 'Generate AI Itinerary';
    }
});

btnSave.addEventListener('click', async () => {
    if (!latestAiResponse) {
        showStatus('Please generate an itinerary before saving.', 'error');
        return;
    }

    const fd = new FormData(form);
    fd.append('action', 'save');
    fd.append('ai_response', latestAiResponse);

    btnSave.disabled = true;
    btnSave.textContent = 'Saving...';
    try {
        const resp = await fetch('../api/ai_itinerary.php', {
            method: 'POST',
            body: fd,
        });
        const data = await parseJsonResponse(resp);
        if (data.status === 'success') {
            showStatus('Saved successfully. Saved ID: ' + data.id, 'ok');
        } else {
            showStatus(data.message || 'Could not save itinerary.', 'error');
        }
    } catch (e) {
        showStatus('Could not save itinerary. Please try again.', 'error');
    } finally {
        btnSave.disabled = false;
        btnSave.textContent = 'Save Itinerary';
    }
});

function validateForm() {
    const destination = document.getElementById('destination').value.trim();
    const start = document.getElementById('start_location').value.trim();
    const days = Number(document.getElementById('days').value);
    const minBudget = Number(document.getElementById('budget_min').value);
    const maxBudget = Number(document.getElementById('budget_max').value);

    if (!destination) return 'Destination cannot be empty.';
    if (!start) return 'Start location cannot be empty.';
    if (!Number.isInteger(days) || days < 1 || days > 7) return 'Number of days must be 1 to 7.';
    if (!Number.isFinite(minBudget) || !Number.isFinite(maxBudget)) return 'Budget must be numeric.';
    if (minBudget < 0 || maxBudget <= 0 || maxBudget < minBudget) return 'Budget range is invalid.';
    return '';
}

function renderItinerary(plan) {
    if (!plan || typeof plan !== 'object') {
        resultArea.className = 'raw-response';
        resultArea.textContent = 'AI response is invalid. Please try again.';
        return;
    }

    resultArea.className = '';
    const days = Array.isArray(plan.days) ? plan.days : [];
    let html = `
        <div class="ai-summary">
            <h3>${escapeHtml(plan.title || 'AI Suggested Itinerary')}</h3>
            <p>${escapeHtml(plan.summary || plan.why_suitable || 'Generated itinerary suggestion.')}</p>
        </div>
    `;

    days.forEach((day, index) => {
        const places = Array.isArray(day.places) ? day.places : [];
        const route = Array.isArray(day.route_order) ? day.route_order : places.map(p => p.name || '');
        html += `
            <div class="day-card">
                <h4>Day ${escapeHtml(String(day.day || index + 1))}</h4>
                <div class="route-list">
                    ${route.filter(Boolean).map(name => `<span class="route-chip">${escapeHtml(name)}</span>`).join('')}
                </div>
                <div class="place-list">
                    ${places.map(place => `
                        <div class="place-item">
                            <strong>${escapeHtml(place.name || 'Unnamed place')}</strong>
                            <span>Category: ${escapeHtml(place.category || '-')}</span>
                            <span>Transport time: ${escapeHtml(place.estimated_transport_time || '-')}</span>
                            <span>Estimated cost: ${escapeHtml(place.estimated_cost || '-')}</span>
                            <span>${escapeHtml(place.reason || '')}</span>
                        </div>
                    `).join('')}
                </div>
                <div class="day-meta">
                    <div><strong>Food:</strong> ${escapeHtml(day.food_suggestion || '-')}</div>
                    <div><strong>Hotel:</strong> ${escapeHtml(day.hotel_suggestion || '-')}</div>
                    <div><strong>Day cost:</strong> ${escapeHtml(day.estimated_day_cost || '-')}</div>
                    <div><strong>Why suitable:</strong> ${escapeHtml(day.explanation || '-')}</div>
                </div>
            </div>
        `;
    });

    html += `
        <div class="ai-summary">
            <p><strong>Total estimated cost:</strong> ${escapeHtml(plan.total_estimated_cost || '-')}</p>
            <p><strong>Transport notes:</strong> ${escapeHtml(plan.transport_notes || '-')}</p>
            <p><strong>Suitability:</strong> ${escapeHtml(plan.why_suitable || '-')}</p>
        </div>
    `;
    resultArea.innerHTML = html;
}

function showStatus(message, type) {
    statusBox.textContent = message;
    statusBox.className = 'status-box show ' + type;
}

function clearStatus() {
    statusBox.textContent = '';
    statusBox.className = 'status-box';
}

async function parseJsonResponse(resp) {
    const raw = await resp.text();
    try {
        return JSON.parse(raw);
    } catch (e) {
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
            return JSON.parse(raw.slice(start, end + 1));
        }
        throw e;
    }
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>
</body>
</html>
