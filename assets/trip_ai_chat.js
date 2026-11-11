// assets/trip_ai_chat.js
// AI assistant chat shared by itinerary_view.php and trip_summary.php.
// The page provides the panel markup:
//   <div class="ai-chat-panel" id="aiChatPanel" data-itinerary-id="123" data-missing-info='["start date"]'>
// Messages are routed to one of three endpoints; nothing is saved until the traveller
// presses a confirmation button on a card.

const AI_CHAT_TIMEOUT_MS = 90000;

const AI_ENDPOINTS = {
    chat: '../api/ai_travel_assistant.php',
    edit: '../api/ai_itinerary_editor.php',
    hotel: '../api/ai_hotel_assistant.php',
    geoSearch: '../api/geo_search.php',
};

const CLOCK_TIME = /\b\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)\b/i;
const TIMETABLE_WORDS = /\b(?:re-?arrange|reschedule|re-?time|new\s+timetable|timetable|schedule)\b/i;
const START_AT_TIME = /\b(?:start|begin)(?:\s+time)?\s*(?:at|from|to)?\s*\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)\b/i;
const CHANGE_START_TIME = /\b(?:change|set|move|update)\s+(?:the\s+|my\s+)?(?:start(?:ing)?\s+)?time\b/i;
const ORIGIN_WORDS = /\b(?:start(?:ing)?\s+(?:location|point)|staring\s+(?:location|point)|origin|start(?:ing)?\s+from|staring\s+from|depart(?:ing)?\s+from|leav(?:e|ing)\s+from)\b/i;
const DATE_WORDS = /\b(?:start\s+date|travel\s+date|trip\s+date|travel\s+on|go\s+on|visit\s+on|arrive\s+on|depart\s+on|date)\b|\b\d{4}[\/\-.]\d{1,2}[\/\-.]\d{1,2}\b|\b\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}\b|\b\d{1,2}\s*[a-zA-Z]+\s*\d{4}\b|\b[a-zA-Z]+\s*\d{1,2}\s*\d{4}\b/i;
const WEATHER_WORDS = /\b(?:weather|forecast|rain|raining|rainy|temperature|hot|humid|storm|umbrella)\b/i;
const HOTEL_WORDS = /\b(?:hotels?|accommodation|stays?|rooms?|sleep|overnight|check\s*-?in|place\s+to\s+stay)\b/i;
const EDIT_WORDS = /\b(?:replace|change|swap|switch|modify|substitute|regenerate|replan|reroute|remove|delete|skip|drop|add|include|instead|alternatives?|another\s+(?:place|stop)|more\s+(?:places?|stops?)|new\s+(?:place|stop)|extra\s+(?:place|stop)|dislike|don'?t\s+(?:want|like)|do\s+not\s+(?:want|like)|too\s+(?:far|expensive|crowded|tiring)|cheaper|lower\s+cost|reduce\s+cost|save\s+money|budget\s+friendly|fill|empty\s+(?:day|slot)|improve)\b/i;

/**
 * Decide which endpoint should handle a message.
 * - timetable: an explicit request to restart the day at a clock time ("start at 9.30am").
 * - hotel: anything about accommodation.
 * - edit: an explicit change to the stops, unless it is really about the date, origin or weather.
 * - chat: everything else, including date/origin updates, which ai_travel_assistant detects.
 */
function detectChatRoute(text) {
    const hotel = HOTEL_WORDS.test(text);
    const timetable = !hotel && CLOCK_TIME.test(text)
        && (TIMETABLE_WORDS.test(text) || START_AT_TIME.test(text) || CHANGE_START_TIME.test(text));
    if (timetable) return 'timetable';
    if (hotel) return 'hotel';

    const origin = ORIGIN_WORDS.test(text);
    const date = DATE_WORDS.test(text);
    const weather = WEATHER_WORDS.test(text);
    if (!origin && !date && !weather && EDIT_WORDS.test(text)) return 'edit';
    return 'chat';
}

function aiChatPanel() {
    return document.getElementById('aiChatPanel');
}

function aiItineraryId() {
    const panel = aiChatPanel();
    return panel ? String(panel.dataset.itineraryId || '') : '';
}

function toggleAiChat() {
    const panel = aiChatPanel();
    if (!panel) return;
    panel.classList.toggle('open');
    if (panel.classList.contains('open')) {
        const input = document.getElementById('aiChatInput');
        if (input) setTimeout(() => input.focus(), 80);
    }
}

function addAiMessage(role, text) {
    const body = document.getElementById('aiChatBody');
    if (!body) return null;
    const msg = document.createElement('div');
    msg.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
    msg.textContent = text;
    body.appendChild(msg);
    body.scrollTop = body.scrollHeight;
    return msg;
}

function clearAiPendingCards() {
    document.querySelectorAll('.ai-card').forEach(card => card.remove());
}

function scrollAiChatToBottom() {
    const body = document.getElementById('aiChatBody');
    if (body) body.scrollTop = body.scrollHeight;
}

function cleanAiText(text) {
    return String(text || '')
        .replace(/\*{1,3}([^*]+)\*{1,3}/g, '$1')
        .replace(/^\s{0,3}#{1,6}\s*/gm, '')
        .replace(/[ \t]+\n/g, '\n')
        .trim();
}

async function parseJsonResponse(resp) {
    const raw = await resp.text();
    try {
        return JSON.parse(raw);
    } catch (e) {
        // Tolerate stray PHP output before or after the JSON body.
        const start = raw.indexOf('{');
        const end = raw.lastIndexOf('}');
        if (start >= 0 && end > start) {
            return JSON.parse(raw.slice(start, end + 1));
        }
        throw e;
    }
}

/** POST form fields and return parsed JSON. Throws on network error or timeout. */
async function postAiForm(url, fields) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), AI_CHAT_TIMEOUT_MS);
    try {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(fields),
            signal: controller.signal,
        });
        return await parseJsonResponse(resp);
    } finally {
        clearTimeout(timer);
    }
}

function aiErrorText(e, networkMessage) {
    return e && e.name === 'AbortError'
        ? 'The assistant took too long to respond. Please try again.'
        : networkMessage;
}

async function sendAiMessage(event) {
    if (event) event.preventDefault();
    const input = document.getElementById('aiChatInput');
    if (!input) return;

    const text = input.value.trim();
    if (!text) return;
    input.value = '';

    addAiMessage('user', text);
    clearAiPendingCards();
    const loading = addAiMessage('bot', 'Writing answer...');

    const route = detectChatRoute(text);
    const endpoint = route === 'hotel' ? AI_ENDPOINTS.hotel
        : (route === 'edit' || route === 'timetable') ? AI_ENDPOINTS.edit
        : AI_ENDPOINTS.chat;

    try {
        const data = await postAiForm(endpoint, {
            action: route === 'chat' ? 'chat' : 'recommend',
            itinerary_id: aiItineraryId(),
            message: text,
        });
        if (loading) loading.textContent = cleanAiText(data.answer || data.message || 'The assistant could not answer this request.');

        if (data.pending_actions && data.pending_actions.length) {
            const firstAction = data.pending_actions[0];
            if (data.pending_actions.length > 1) firstAction.next_action = data.pending_actions[1];
            renderPendingAction(loading, firstAction);
        } else if (data.pending_action) {
            renderPendingAction(loading, data.pending_action);
        }
        if (route === 'hotel' && data.hotels && data.hotels.length) {
            renderHotelCards(loading, data.hotels);
        }
        if (data.proposals && data.proposals.length) {
            renderChangeCards(loading, data.proposals);
        }
    } catch (e) {
        if (loading) loading.textContent = aiErrorText(e, 'Network error. Please try again.');
    }
}

function makeAiCard(variant, titleText, lines, buttonText, onClick) {
    const card = document.createElement('div');
    card.className = 'ai-card ai-card--' + variant;

    const title = document.createElement('strong');
    title.textContent = titleText;
    card.appendChild(title);

    lines.filter(Boolean).forEach(line => {
        const span = document.createElement('span');
        span.textContent = line;
        card.appendChild(span);
    });

    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = buttonText;
    button.addEventListener('click', () => onClick(button));
    card.appendChild(button);
    return card;
}

function renderPendingAction(container, action) {
    if (!container || !action || !['update_start_date', 'update_origin', 'retime_itinerary'].includes(action.type)) return;

    let title, summary, buttonText, onClick;
    if (action.type === 'update_origin') {
        title = 'Confirm starting location';
        summary = action.summary || ('Update starting location to ' + (action.label || action.origin_name));
        buttonText = 'Confirm Location';
        onClick = button => confirmTripOrigin(action.origin_name || action.label || '', button);
    } else if (action.type === 'retime_itinerary') {
        title = 'Confirm timetable change';
        summary = action.summary || ('Rearrange timetable to start from ' + (action.label || action.start_time));
        buttonText = 'Confirm Timetable';
        onClick = button => confirmItineraryRetiming(action.start_time || '', button);
    } else {
        title = 'Confirm trip date';
        summary = action.summary || ('Update itinerary start date to ' + action.label);
        buttonText = 'Confirm Date';
        onClick = button => confirmTripDate(action.start_date, button, action.next_action || null);
    }

    container.appendChild(makeAiCard('action', title, [summary], buttonText, onClick));
    scrollAiChatToBottom();
}

function renderHotelCards(container, hotels) {
    if (!container) return;
    hotels.slice(0, 1).forEach(hotel => {
        const price = Number(hotel.price_per_night || 0);
        const meta = (hotel.kind_label || 'Hotel') + (hotel.stars ? ', ' + Number(hotel.stars) + '-star' : '')
            + ' - about RM ' + price.toFixed(0) + '/night (estimate)'
            + (hotel.distance_km ? ' - ' + Number(hotel.distance_km).toFixed(1) + ' km away' : '')
            + (hotel.address ? ' - ' + hotel.address : '');
        container.appendChild(makeAiCard('hotel', hotel.name || 'Hotel', [meta], 'Confirm Hotel',
            button => confirmHotel(Number(hotel.hotel_id || 0), button)));
    });
    scrollAiChatToBottom();
}

function renderChangeCards(container, proposals) {
    if (!container) return;
    // Only the first proposal is offered, so the card always matches the chat answer.
    proposals.slice(0, 1).forEach(proposal => {
        const place = proposal.new_place || {};
        const isAdd = proposal.proposal_type === 'add' || !Number(proposal.item_id || 0);
        const title = isAdd
            ? 'Day ' + proposal.day_no + ': add new stop'
            : 'Day ' + proposal.day_no + ' stop ' + proposal.sequence_no + ': ' + (proposal.current_title || 'Current stop');
        const meta = (isAdd ? 'Add ' : 'Replace with ') + (place.name || 'new place')
            + ' - ' + (place.category || 'place') + ' - RM ' + Number(place.estimated_cost || 0).toFixed(2);
        const reason = proposal.reason || 'Suggested from the cultural places database';
        container.appendChild(makeAiCard(isAdd ? 'add' : 'change', title, [meta, reason],
            isAdd ? 'Confirm Add' : 'Confirm Change',
            button => isAdd
                ? confirmItineraryAdd(Number(proposal.day_no || 0), Number(place.place_id || 0), button)
                : confirmItineraryChange(Number(proposal.item_id || 0), Number(place.place_id || 0), button)));
    });
    scrollAiChatToBottom();
}

/**
 * Run one confirm request: show progress, then reload on success or re-enable the button
 * and show the reason on failure.
 */
async function runAiConfirm(button, progressText, url, fields, messages) {
    if (button) button.disabled = true;
    const loading = addAiMessage('bot', progressText);
    try {
        const data = await postAiForm(url, Object.assign({ itinerary_id: aiItineraryId() }, fields));
        if (data.status === 'success') {
            if (loading) loading.textContent = cleanAiText(data.answer || data.message || messages.success);
            clearAiPendingCards();
            return data;
        }
        if (button) button.disabled = false;
        if (loading) loading.textContent = cleanAiText(data.answer || data.message || messages.failure);
    } catch (e) {
        if (button) button.disabled = false;
        if (loading) loading.textContent = aiErrorText(e, messages.network);
    }
    return null;
}

function reloadSoon() {
    setTimeout(() => window.location.reload(), 700);
}

async function confirmTripDate(startDate, button, nextAction = null) {
    if (!startDate) return;
    const data = await runAiConfirm(button, 'Saving confirmed trip date...', AI_ENDPOINTS.chat,
        { action: 'confirm_date', start_date: String(startDate) },
        { success: 'Trip date saved. Reloading...', failure: 'Could not save this trip date.', network: 'Network error while saving trip date. Please try again.' });
    if (!data) return;
    if (nextAction) {
        const body = document.getElementById('aiChatBody');
        renderPendingAction(body ? body.lastElementChild : null, nextAction);
        return;
    }
    reloadSoon();
}

/** Resolve a typed place name to coordinates in Malaysia (OpenStreetMap search through this server). */
async function geocodeOriginName(originName) {
    const response = await fetch(AI_ENDPOINTS.geoSearch + '?q=' + encodeURIComponent(originName), { credentials: 'same-origin' });
    const data = await response.json();
    const best = data && Array.isArray(data.results) ? data.results[0] : null;
    if (!best) throw new Error('Location not found');
    return { name: best.label || originName, lat: Number(best.lat), lng: Number(best.lng) };
}

async function confirmTripOrigin(originName, button) {
    originName = String(originName || '').trim();
    if (!originName) return;
    let place;
    try {
        if (button) button.disabled = true;
        place = await geocodeOriginName(originName);
    } catch (e) {
        if (button) button.disabled = false;
        addAiMessage('bot', 'Could not find coordinates for that location. Try a clearer city, hotel, landmark, or address.');
        return;
    }
    const data = await runAiConfirm(button, 'Saving starting location...', AI_ENDPOINTS.chat,
        { action: 'confirm_origin', origin_name: place.name, origin_lat: String(place.lat), origin_lng: String(place.lng) },
        { success: 'Starting location saved. Reloading...', failure: 'Could not save this starting location.', network: 'Network error while saving starting location. Please try again.' });
    if (data) reloadSoon();
}

async function confirmItineraryRetiming(startTime, button = null) {
    startTime = String(startTime || '').trim();
    if (!startTime) return;
    const data = await runAiConfirm(button, 'Rearranging timetable with the confirmed start time...', AI_ENDPOINTS.edit,
        { action: 'confirm_retime', start_time: startTime },
        { success: 'Timetable updated. Reloading...', failure: 'Could not rearrange this timetable.', network: 'Network error while rearranging timetable. Please try again.' });
    if (data) reloadSoon();
}

async function confirmItineraryChange(itemId, placeId, button = null) {
    if (!itemId || !placeId) return;
    const data = await runAiConfirm(button, 'Applying the change and recalculating route and cost...', AI_ENDPOINTS.edit,
        { action: 'confirm', item_id: String(itemId), place_id: String(placeId) },
        { success: 'Itinerary changed. Reloading...', failure: 'Could not apply this itinerary change.', network: 'Network error while saving itinerary change. Please try again.' });
    if (data) reloadSoon();
}

async function confirmItineraryAdd(dayNo, placeId, button = null) {
    if (!dayNo || !placeId) return;
    const data = await runAiConfirm(button, 'Adding the place and recalculating route and cost...', AI_ENDPOINTS.edit,
        { action: 'confirm_add', day_no: String(dayNo), place_id: String(placeId) },
        { success: 'Place added. Reloading...', failure: 'Could not add this place.', network: 'Network error while adding place. Please try again.' });
    if (data) reloadSoon();
}

async function confirmHotel(hotelId, button = null) {
    if (!hotelId) return;
    const data = await runAiConfirm(button, 'Saving the selected hotel into your itinerary and cost summary...', AI_ENDPOINTS.hotel,
        { action: 'confirm_hotel', hotel_id: String(hotelId) },
        { success: 'Hotel confirmed. Reloading...', failure: 'Could not confirm this hotel.', network: 'Network error while saving hotel. Please try again.' });
    if (data) reloadSoon();
}

// Open the panel with a prompt when the page reports missing trip details.
document.addEventListener('DOMContentLoaded', () => {
    const panel = aiChatPanel();
    const initial = document.getElementById('aiInitialMessage');
    if (!panel || !initial) return;
    let missing = [];
    try {
        missing = JSON.parse(panel.dataset.missingInfo || '[]');
    } catch (e) {
        missing = [];
    }
    if (!Array.isArray(missing) || !missing.length) return;
    initial.textContent = 'Before this trip is complete, I still need: ' + missing.join(', ')
        + '. Tell me the missing details here, then confirm the detected information.';
    panel.classList.add('open');
    const input = document.getElementById('aiChatInput');
    if (input) setTimeout(() => input.focus(), 120);
});
