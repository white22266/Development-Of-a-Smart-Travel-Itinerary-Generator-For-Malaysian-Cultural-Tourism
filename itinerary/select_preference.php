<?php
session_start();
require_once "../config/db_connect.php";
require_once "../config/api_keys.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "traveller") {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerId = (int)($_SESSION["traveller_id"] ?? 0);
if ($travellerId <= 0) {
    header("Location: ../auth/login.php?role=traveller");
    exit;
}

$travellerName = $_SESSION["traveller_name"] ?? "Traveller";
$ollamaModel = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
$googleMapsKey = defined("GOOGLE_MAPS_API_KEY") ? trim((string)GOOGLE_MAPS_API_KEY) : "";
$errors = $_SESSION["form_errors"] ?? [];
unset($_SESSION["form_errors"]);

$stmt = $conn->prepare("
  SELECT preference_id, trip_days, budget, budget_tier, transport_type, traveller_type, travel_pace,
         dietary_preference, preferred_visit_time, interests, preferred_states, preferred_districts, created_at
  FROM traveller_preferences
  WHERE traveller_id = ?
  ORDER BY preference_id DESC
");
$stmt->bind_param("i", $travellerId);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Smart Itinerary Generator</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        /* ---- Origin input group ---- */
        .origin-group {
            position: relative;
        }
        .origin-input-wrap {
            display: flex;
            gap: 8px;
            align-items: stretch;
            margin-top: 8px;
        }
        .origin-input-wrap input[type="text"] {
            flex: 1;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid rgba(15,23,42,0.10);
            font-size: 13px;
        }
        .btn-locate {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0 14px;
            border-radius: 12px;
            border: 1px solid rgba(99,102,241,0.35);
            background: rgba(99,102,241,0.07);
            color: #6366f1;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }
        .btn-locate:hover { background: rgba(99,102,241,0.14); }
        .btn-locate svg { width:14px; height:14px; flex-shrink:0; }
        .origin-status {
            font-size: 11px;
            margin-top: 5px;
            min-height: 16px;
            color: var(--muted);
        }
        .origin-status.ok   { color: #22c55e; }
        .origin-status.err  { color: #ef4444; }
        .origin-status.spin { color: #6366f1; }
        .pac-container {
            z-index: 99999;
            border-radius: 12px;
            margin-top: 4px;
            border: 1px solid rgba(15,23,42,0.12);
            box-shadow: 0 16px 40px rgba(15,23,42,0.14);
            font-family: inherit;
        }
        .pac-item {
            padding: 8px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .pac-item:hover {
            background: #f8fafc;
        }

        /* ---- Route strategy info box ---- */
        .route-info-box {
            background: rgba(99,102,241,0.06);
            border: 1px solid rgba(99,102,241,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 8px;
        }
        .route-info-box .route-title {
            font-weight: 800;
            font-size: 13px;
            color: #4f46e5;
            margin-bottom: 6px;
        }
        .route-info-box ul {
            margin: 0;
            padding-left: 18px;
            font-size: 12px;
            color: #475569;
            line-height: 1.7;
        }
        .ai-helper {
            margin-top: 16px;
        }
        .ai-helper-grid {
            display: grid;
            grid-template-columns: minmax(280px, 410px) minmax(0, 1fr);
            gap: 14px;
            align-items: start;
        }
        .ai-form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .ai-field {
            margin-bottom: 12px;
        }
        .ai-field label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            margin-bottom: 6px;
        }
        .ai-field input,
        .ai-field select,
        .ai-field textarea {
            width: 100%;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 13px;
            background: #fff;
            box-sizing: border-box;
        }
        .ai-field textarea {
            min-height: 76px;
            resize: vertical;
        }
        .ai-category-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
        }
        .ai-category-grid label {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 9px;
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
        }
        .ai-category-grid input {
            width: auto;
        }
        .ai-status {
            display: none;
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }
        .ai-status.show { display: block; }
        .ai-status.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .ai-status.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .ai-result-empty {
            min-height: 310px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #64748b;
            border: 1px dashed rgba(15, 23, 42, 0.18);
            border-radius: 12px;
            background: #f8fafc;
            padding: 20px;
        }
        .ai-day-card {
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            background: #fff;
        }
        .ai-day-card h4 {
            margin: 0 0 8px;
            color: #4f46e5;
        }
        .ai-route-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 8px;
        }
        .ai-route-chip {
            background: #eef2ff;
            color: #3730a3;
            border-radius: 999px;
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 800;
        }
        .ai-place {
            border-left: 4px solid #6366f1;
            background: #f8fafc;
            border-radius: 8px;
            padding: 9px 10px;
            margin-top: 8px;
        }
        .ai-place span {
            display: block;
            font-size: 12px;
            color: #64748b;
            line-height: 1.45;
        }
        .ai-summary {
            background: #f8fafc;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
            color: #475569;
            line-height: 1.5;
        }
        .ai-chat-shell {
            display: grid;
            grid-template-columns: minmax(260px, 340px) minmax(0, 1fr);
            gap: 14px;
        }
        .ai-chat-context {
            background: #f8fafc;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 14px;
            color: #475569;
            font-size: 13px;
            line-height: 1.55;
        }
        .ai-chat-window {
            border: 1px solid rgba(15, 23, 42, 0.10);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
        }
        .ai-chat-messages {
            min-height: 260px;
            max-height: 420px;
            overflow-y: auto;
            padding: 14px;
            background: #f8fafc;
        }
        .ai-msg {
            max-width: 86%;
            margin-bottom: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.45;
            white-space: pre-wrap;
        }
        .ai-msg.bot {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.08);
            color: #334155;
        }
        .ai-msg.user {
            margin-left: auto;
            background: #4f46e5;
            color: #fff;
        }
        .ai-chat-form {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid rgba(15, 23, 42, 0.08);
        }
        .ai-chat-form input {
            flex: 1;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 13px;
        }
        @media (max-width: 850px) {
            .ai-chat-shell { grid-template-columns: 1fr; }
            .ai-chat-form { flex-direction: column; }
        }
        @media (max-width: 1050px) {
            .ai-helper-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 640px) {
            .ai-form-row, .ai-category-grid { grid-template-columns: 1fr; }
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
                <span>Smart Itinerary Generator</span>
            </div>
        </div>

        <nav class="nav" aria-label="Sidebar Navigation">
            <a href="../traveller/traveller_dashboard.php"><span class="dot"></span> Dashboard</a>
            <a href="../preference/preference_form.php"><span class="dot"></span> Traveller Preference Analyzer</a>
            <a class="active" href="select_preference.php"><span class="dot"></span> Smart Itinerary Generator</a>
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
                <h1>Smart Itinerary Generator</h1>
                <p>Select a saved preference to generate your personalised cultural itinerary. Weather will adjust outdoor activities.</p>
            </div>
            <div class="actions">
                <a class="btn btn-primary" href="#ai-travel-assistant">AI Travel Assistant</a>
                <a class="btn btn-ghost" href="../traveller/traveller_dashboard.php">Back</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="card" style="border-left:6px solid rgba(239,68,68,.7); margin-bottom:12px;">
                <strong style="color:rgba(239,68,68,1);"><?php echo htmlspecialchars($errors[0]); ?></strong>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Generate Itinerary</h3>

            <?php if ($res->num_rows === 0): ?>
                <p style="color:#ef4444; font-weight:800;">
                    No preference found. Please create one first.
                </p>
                <a class="btn btn-primary" href="../preference/preference_form.php">Go to Preference Analyzer</a>

            <?php else: ?>
                <form method="post" action="generate_itinerary.php" id="genForm">

                    <!-- ===== Saved Preference ===== -->
                    <label style="font-weight:800; font-size:13px;">Saved Preference *</label><br>
                    <select name="preference_id" id="preferenceSelect" required
                        style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px; font-size:13px;">
                        <option value="" disabled selected>— Select one preference —</option>
                        <?php while ($p = $res->fetch_assoc()): ?>
                            <?php
                                $ps = trim((string)($p["preferred_states"] ?? ""));
                                $pd = trim((string)($p["preferred_districts"] ?? ""));
                                $loc = "";
                                if ($ps !== "") {
                                    $loc = $ps;
                                    if ($pd !== "") $loc .= " › " . $pd;
                                } else {
                                    $loc = "All Malaysia";
                                }
                            ?>
                            <option value="<?php echo (int)$p["preference_id"]; ?>">
                                #<?php echo (int)$p["preference_id"]; ?> |
                                <?php echo (int)$p["trip_days"]; ?> day<?php echo $p["trip_days"] > 1 ? 's' : ''; ?> |
                                RM<?php echo number_format((float)$p["budget"], 2); ?> |
                                <?php echo htmlspecialchars($p["budget_tier"] ?? "normal"); ?> |
                                <?php echo htmlspecialchars($p["transport_type"]); ?> |
                                <?php echo htmlspecialchars($p["traveller_type"] ?? "solo"); ?> |
                                <?php echo htmlspecialchars($p["travel_pace"] ?? "normal"); ?> |
                                <?php echo htmlspecialchars($p["interests"]); ?> |
                                <?php echo htmlspecialchars($loc); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>

                    <div style="height:14px;"></div>

                    <!-- ===== Start Date ===== -->
                    <label style="font-weight:800; font-size:13px;">Start Date</label><br>
                    <input type="date" name="start_date"
                        style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px; font-size:13px;">
                    <div class="meta" style="margin-top:5px;">If left empty, weather will show current conditions only.</div>

                    <div style="height:14px;"></div>

                    <!-- ===== Places Per Day ===== -->
                    <label style="font-weight:800; font-size:13px;">Places Per Day *</label><br>
                    <select name="items_per_day" required
                        style="width:100%; padding:10px 12px; border-radius:12px; border:1px solid rgba(15,23,42,0.10); margin-top:8px; font-size:13px;">
                        <option value="1">1 place per day</option>
                        <option value="2">2 places per day</option>
                        <option value="3" selected>3 places per day</option>
                        <option value="4">4 places per day</option>
                        <option value="5">5 places per day</option>
                    </select>

                    <div style="height:14px;"></div>

                    <!-- ===== Route Strategy (single mode, read-only info) ===== -->
                    <label style="font-weight:800; font-size:13px;">Route Strategy</label>
                    <input type="hidden" name="route_strategy" value="nearest_next">
                    <div class="route-info-box">
                        <div class="route-title">&#9654; Rule-Based Nearest-Next Routing</div>
                        <ul>
                            <li>Places are filtered by your <strong>preferred state &amp; district</strong>, interests, and budget.</li>
                            <li>Each day, the system picks the <strong>closest unvisited place</strong> from the previous stop (Haversine distance).</li>
                            <li>If a starting location is provided below, Day 1 routing begins from your origin.</li>
                            <li>Category diversity is enforced — no two consecutive places share the same category.</li>
                            <li>Outdoor places (nature/festival) are deprioritised when weather is unfavourable.</li>
                        </ul>
                    </div>

                    <div style="height:14px;"></div>

                    <!-- ===== Starting Location ===== -->
                    <label style="font-weight:800; font-size:13px;">
                        Starting Location
                        <span style="font-weight:400; color:var(--muted); font-size:12px;">(optional — for origin-aware Day 1 routing)</span>
                    </label>
                    <div class="origin-group">
                        <div class="origin-input-wrap">
                            <input type="text" name="origin_name" id="origin_name"
                                placeholder="Type a city or address, e.g. Johor Bahru, Kuala Lumpur...">
                            <button type="button" class="btn-locate" id="btnLocate" title="Use my current location">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>
                                    <path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z" stroke-width="0"/>
                                </svg>
                                Use My Location
                            </button>
                        </div>
                        <input type="hidden" name="origin_lat" id="origin_lat">
                        <input type="hidden" name="origin_lng" id="origin_lng">
                        <div class="origin-status" id="originStatus">Start typing and choose a Google Maps suggestion, or click "Use My Location".</div>
                    </div>

                    <div style="height:18px;"></div>

                    <button class="btn btn-primary" type="submit">&#9654; Generate Itinerary</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card ai-helper" id="ai-travel-assistant">
            <h3>AI Travel Assistant</h3>
            <p class="meta">
                Ask about the selected saved preference above. The assistant gives route, cost, culture, hotel, festival timing, and improvement advice using Ollama
                without creating or saving a formal itinerary. Local model: <?php echo htmlspecialchars($ollamaModel); ?>.
            </p>

            <div class="ai-chat-shell">
                <div class="ai-chat-context">
                    <strong>How this assistant works</strong><br>
                    Select one saved preference in Generate Itinerary, then ask a question here. The AI reads that selected preference, so you do not need to fill another trip form.
                    <div class="meta" id="aiSelectedPreference" style="margin-top:10px;">Selected preference: none</div>
                </div>
                <div class="ai-chat-window">
                    <div id="aiChatMessages" class="ai-chat-messages">
                        <div class="ai-msg bot">Hi, I can help check your selected preference before you generate. Ask things like “is this budget enough?”, “suggest route style”, or “should I include festival places?”</div>
                    </div>
                    <form id="aiPreferenceChatForm" class="ai-chat-form">
                        <input type="text" id="aiPreferenceMessage" placeholder="Ask about the selected preference..." autocomplete="off">
                        <button type="submit" class="btn btn-primary" id="aiPreferenceSend">Send</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
(function () {
    var nameInp   = document.getElementById('origin_name');
    var latField  = document.getElementById('origin_lat');
    var lngField  = document.getElementById('origin_lng');
    var status    = document.getElementById('originStatus');
    var btnLocate = document.getElementById('btnLocate');
    var pickedFromAutocomplete = false;

    window.initOriginAutocomplete = function () {};

    if (!nameInp) return;

    window.initOriginAutocomplete = function () {
        if (!window.google || !google.maps || !google.maps.places) return;

        var autocomplete = new google.maps.places.Autocomplete(nameInp, {
            componentRestrictions: { country: 'my' },
            fields: ['formatted_address', 'geometry', 'name']
        });

        autocomplete.addListener('place_changed', function () {
            var place = autocomplete.getPlace();
            if (!place || !place.geometry || !place.geometry.location) {
                latField.value = '';
                lngField.value = '';
                setStatus('err', '&#10007; Please choose a valid Google Maps suggestion.');
                return;
            }

            pickedFromAutocomplete = true;
            latField.value = place.geometry.location.lat().toFixed(6);
            lngField.value = place.geometry.location.lng().toFixed(6);
            nameInp.value = place.formatted_address || place.name || nameInp.value;
            setStatus('ok', '&#10003; Location selected: ' + escapeStatusHtml(nameInp.value));
        });
    };

    /* ---- Geocode typed address (debounced 800ms) ---- */
    var timer = null;
    nameInp.addEventListener('input', function () {
        clearTimeout(timer);
        if (pickedFromAutocomplete) {
            pickedFromAutocomplete = false;
            return;
        }

        var q = nameInp.value.trim();
        if (q.length < 3) {
            latField.value = '';
            lngField.value = '';
            setStatus('', 'Start typing and choose a Google Maps suggestion, or click "Use My Location".');
            return;
        }
        setStatus('spin', 'Looking up address…');
        timer = setTimeout(function () {
            fetch('geocode_origin.php?q=' + encodeURIComponent(q + ', Malaysia'))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.lat && d.lng) {
                        latField.value = d.lat;
                        lngField.value = d.lng;
                        setStatus('ok', '&#10003; Location found: ' + escapeStatusHtml(d.address || q));
                    } else {
                        latField.value = '';
                        lngField.value = '';
                        setStatus('err', '&#10007; Location not found. Routing will start from the first place.');
                    }
                })
                .catch(function () {
                    setStatus('err', '&#10007; Geocoding failed. Check your connection.');
                });
        }, 800);
    });

    /* ---- Use browser Geolocation ---- */
    if (btnLocate) {
        btnLocate.addEventListener('click', function () {
            if (!navigator.geolocation) {
                setStatus('err', '&#10007; Geolocation is not supported by your browser.');
                return;
            }
            setStatus('spin', 'Detecting your location…');
            btnLocate.disabled = true;
            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    var lat = pos.coords.latitude.toFixed(6);
                    var lng = pos.coords.longitude.toFixed(6);
                    latField.value = lat;
                    lngField.value = lng;
                    /* Reverse geocode to get a readable name */
                    fetch('geocode_origin.php?lat=' + lat + '&lng=' + lng)
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            var label = (d && d.address) ? d.address : lat + ', ' + lng;
                            pickedFromAutocomplete = true;
                            nameInp.value = label;
                            setStatus('ok', '&#10003; Location detected: ' + escapeStatusHtml(label));
                        })
                        .catch(function () {
                            pickedFromAutocomplete = true;
                            nameInp.value = lat + ', ' + lng;
                            setStatus('ok', '&#10003; GPS coordinates captured (' + lat + ', ' + lng + ')');
                        });
                    btnLocate.disabled = false;
                },
                function (err) {
                    var msg = '&#10007; Could not get location.';
                    if (err.code === 1) msg = '&#10007; Location permission denied.';
                    if (err.code === 2) msg = '&#10007; Location unavailable.';
                    if (err.code === 3) msg = '&#10007; Location request timed out.';
                    setStatus('err', msg);
                    btnLocate.disabled = false;
                },
                { timeout: 10000, maximumAge: 60000 }
            );
        });
    }

    function setStatus(cls, msg) {
        status.className = 'origin-status' + (cls ? ' ' + cls : '');
        status.innerHTML = msg;
    }

    function escapeStatusHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
})();

(function () {
    var form = document.getElementById('aiPreferenceChatForm');
    var prefSelect = document.getElementById('preferenceSelect');
    var input = document.getElementById('aiPreferenceMessage');
    var messages = document.getElementById('aiChatMessages');
    var sendBtn = document.getElementById('aiPreferenceSend');
    var selectedText = document.getElementById('aiSelectedPreference');
    if (!form || !prefSelect || !input || !messages) return;

    function updateSelectedPreferenceText() {
        var opt = prefSelect.options[prefSelect.selectedIndex];
        var text = opt && opt.value ? opt.textContent.replace(/\s+/g, ' ').trim() : 'none';
        selectedText.textContent = 'Selected preference: ' + text;
    }

    prefSelect.addEventListener('change', updateSelectedPreferenceText);
    updateSelectedPreferenceText();

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var preferenceId = prefSelect.value;
        var text = input.value.trim();
        if (!preferenceId) {
            appendMessage('bot', 'Please select one saved preference above first.');
            return;
        }
        if (!text) return;

        appendMessage('user', text);
        input.value = '';
        sendBtn.disabled = true;
        sendBtn.textContent = 'Thinking...';
        var pending = appendMessage('bot', 'AI is checking your selected preference...');

        var fd = new FormData();
        fd.append('preference_id', preferenceId);
        fd.append('message', text);

        fetch('../api/ai_preference_chat.php', {
            method: 'POST',
            body: fd
        })
            .then(function (resp) { return resp.json(); })
            .then(function (data) {
                pending.textContent = data.status === 'success'
                    ? (data.reply || 'No reply returned.')
                    : (data.message || 'AI response is invalid. Please try again.');
            })
            .catch(function () {
                pending.textContent = 'AI service is currently unavailable. Please make sure Ollama is running.';
            })
            .finally(function () {
                sendBtn.disabled = false;
                sendBtn.textContent = 'Send';
                messages.scrollTop = messages.scrollHeight;
            });
    });

    function appendMessage(type, text) {
        var div = document.createElement('div');
        div.className = 'ai-msg ' + type;
        div.textContent = text;
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }
})();
</script>
<?php if ($googleMapsKey !== ""): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($googleMapsKey); ?>&libraries=places&callback=initOriginAutocomplete" async defer></script>
<?php endif; ?>
</body>
</html>
