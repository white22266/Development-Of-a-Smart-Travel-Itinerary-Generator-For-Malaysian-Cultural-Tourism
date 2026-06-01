// assets/itinerary_view_origin_distance_fix.js
// Ensures itinerary_view distance and transport cost include the starting point/origin leg.
(function () {
    if (window.__itineraryViewOriginDistanceFix) return;
    window.__itineraryViewOriginDistanceFix = true;

    const meta = window.ITINERARY_VIEW_META || {};
    const origin = meta.origin || {};
    const hasSavedOrigin = isValidCoord(origin.lat, origin.lng);
    const RATES = { car: 0.60, motorcycle: 0.30, public_transport: 0.15, walking: 0.00 };
    const MODE_LABELS = { car: 'Car', motorcycle: 'Motorcycle', public_transport: 'Public Transport', walking: 'Walking' };

    function isValidCoord(lat, lng) {
        lat = Number(lat); lng = Number(lng);
        return Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180 && !(lat === 0 && lng === 0);
    }

    function normalizeMode(mode) {
        if (typeof normalizeTransportMode === 'function') return normalizeTransportMode(mode);
        const m = String(mode || 'car').toLowerCase().trim().replace(/[-\s]+/g, '_');
        if (['public', 'public_transport', 'publictransit', 'public_transit', 'transit', 'bus', 'train'].includes(m)) return 'public_transport';
        if (['drive', 'driving'].includes(m)) return 'car';
        if (m === 'walk') return 'walking';
        if (m === 'motorbike' || m === 'bike') return 'motorcycle';
        return ['car', 'motorcycle', 'public_transport', 'walking'].includes(m) ? m : 'car';
    }

    function currentMode() {
        try {
            if (typeof currentTransport !== 'undefined') return normalizeMode(currentTransport);
        } catch (e) {}
        return normalizeMode(meta.transportType || 'car');
    }

    function roadDistanceKm(a, b) {
        if (!a || !b || !isValidCoord(a.lat, a.lng) || !isValidCoord(b.lat, b.lng)) return 0;
        const earthKm = 6371.0;
        const dLat = (Number(b.lat) - Number(a.lat)) * Math.PI / 180;
        const dLng = (Number(b.lng) - Number(a.lng)) * Math.PI / 180;
        const lat1 = Number(a.lat) * Math.PI / 180;
        const lat2 = Number(b.lat) * Math.PI / 180;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return earthKm * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h)) * 1.3;
    }

    function validItems(day) {
        const data = (typeof DAYS_DATA !== 'undefined' && DAYS_DATA[day]) ? DAYS_DATA[day] : [];
        return data.filter(it => isValidCoord(it.lat, it.lng));
    }

    function lastValidItem(day) {
        const items = validItems(day);
        for (let i = items.length - 1; i >= 0; i--) {
            if (isValidCoord(items[i].lat, items[i].lng)) return items[i];
        }
        return null;
    }

    function startPointForDay(day) {
        if (day === 1) {
            if (hasSavedOrigin) return { lat: Number(origin.lat), lng: Number(origin.lng), title: origin.name || 'Starting point' };
            try {
                if (typeof userLat !== 'undefined' && userLat !== null && userLng !== null) return { lat: Number(userLat), lng: Number(userLng), title: 'Detected current location' };
            } catch (e) {}
            return null;
        }
        const prev = lastValidItem(day - 1);
        return prev ? { lat: Number(prev.lat), lng: Number(prev.lng), title: prev.title || "Previous night's hotel" } : null;
    }

    function effectiveDistanceKm() {
        let total = 0;
        const totalDays = Number(typeof TOTAL_DAYS !== 'undefined' ? TOTAL_DAYS : 1);
        for (let day = 1; day <= totalDays; day++) {
            const items = validItems(day);
            if (!items.length) continue;
            let prev = startPointForDay(day);
            items.forEach((item) => {
                const storedKm = Number(item.dist_km || 0);
                let km = storedKm > 0 ? storedKm : 0;
                if (km <= 0 && prev && isValidCoord(prev.lat, prev.lng)) {
                    km = roadDistanceKm(prev, item);
                }
                total += Math.max(0, km);
                prev = { lat: Number(item.lat), lng: Number(item.lng), title: item.title || '' };
            });
        }
        return Math.round(total * 10) / 10;
    }

    function parseMoney(text) {
        const match = String(text || '').replace(/,/g, '').match(/RM\s*(-?\d+(?:\.\d+)?)/i);
        return match ? Number(match[1]) : 0;
    }

    function setSummaryValue(labelText, value) {
        document.querySelectorAll('.summary-card').forEach(card => {
            const label = card.querySelector('.summary-label');
            const val = card.querySelector('.summary-value');
            if (label && val && label.textContent.trim().toLowerCase() === labelText.toLowerCase()) {
                val.textContent = value;
            }
        });
    }

    function updateCostSummary() {
        const km = effectiveDistanceKm();
        const mode = currentMode();
        const rate = RATES[mode] ?? 0.60;
        const transportCost = Math.round(km * rate * 100) / 100;
        let oldTransport = 0;
        let sumRows = 0;

        setSummaryValue('Distance', km.toFixed(1) + ' km');

        document.querySelectorAll('.cost-mini').forEach(row => {
            const amountEl = row.querySelector('strong');
            const spans = row.querySelectorAll('span');
            if (!amountEl || spans.length < 2) return;
            const label = spans[0].textContent.trim();
            const oldAmount = parseMoney(amountEl.textContent);
            if (label.toLowerCase().startsWith('transport')) {
                oldTransport = oldAmount;
                amountEl.textContent = 'RM ' + transportCost.toFixed(2);
                spans[0].textContent = 'Transport (' + (MODE_LABELS[mode] || mode) + ')';
                spans[1].textContent = km.toFixed(1) + ' km × RM ' + rate.toFixed(2) + '/km · includes starting point/origin and daily start legs';
                sumRows += transportCost;
            } else {
                sumRows += oldAmount;
            }
        });

        const totalEstimate = Math.round(sumRows * 100) / 100;
        setSummaryValue('Total Estimate', 'RM ' + totalEstimate.toFixed(2));

        const budget = Number(meta.budget || 0);
        const budgetEl = document.querySelector('.budget-ok, .budget-over');
        if (budgetEl && budget > 0) {
            const diff = Math.round((budget - totalEstimate) * 100) / 100;
            budgetEl.classList.toggle('budget-ok', diff >= 0);
            budgetEl.classList.toggle('budget-over', diff < 0);
            budgetEl.textContent = (diff >= 0 ? 'Within Budget' : 'Over Budget') + ' RM ' + Math.abs(diff).toFixed(2);
        }
    }

    function applySavedOriginMarker() {
        if (!hasSavedOrigin) return;
        try {
            if (typeof userLat !== 'undefined') {
                userLat = Number(origin.lat);
                userLng = Number(origin.lng);
            }
            if (typeof map !== 'undefined' && map && typeof placeUserMarker === 'function') {
                placeUserMarker(Number(origin.lat), Number(origin.lng));
            }
        } catch (e) {}
    }

    function patchRenderDay() {
        if (typeof renderDay !== 'function' || typeof google === 'undefined') return;
        if (renderDay.__originFixed) return;

        const fixedRenderDay = function(day) {
            clearDayRoutes(day);
            if (dayMarkers[day]) {
                dayMarkers[day].forEach(m => m.setMap(null));
                delete dayMarkers[day];
            }
            if (!dayVisible[day]) return;

            const items = DAYS_DATA[day] || [];
            const color = DAY_COLORS[day] || '#6366f1';
            const valid = items.filter(it => it.lat && it.lng);
            if (valid.length === 0) return;

            let originLatLng = null;
            const start = startPointForDay(day);
            if (start && isValidCoord(start.lat, start.lng)) originLatLng = { lat: Number(start.lat), lng: Number(start.lng) };

            dayMarkers[day] = [];
            const allPoints = [];
            if (originLatLng) allPoints.push(originLatLng);

            valid.forEach((item, idx) => {
                const pos = { lat: item.lat, lng: item.lng };
                allPoints.push(pos);
                const label = String.fromCharCode(65 + idx);
                const marker = new google.maps.Marker({
                    position: pos,
                    map,
                    label: { text: label, color: '#fff', fontWeight: 'bold', fontSize: '12px' },
                    title: item.title,
                    icon: { path: google.maps.SymbolPath.CIRCLE, scale: 14, fillColor: color, fillOpacity: 0.95, strokeColor: '#fff', strokeWeight: 2 },
                });
                const typeLabel = { food: 'Food', hotel: 'Hotel', festival: 'Festival', museum: 'Museum', heritage: 'Heritage', culture: 'Culture', nature: 'Nature', attraction: 'Place' };
                const markerLabel = typeLabel[item.type] || 'Place';
                marker.addListener('click', () => {
                    infoWindow.setContent(`<div style="max-width:230px;"><div style="font-weight:800;font-size:13px;">${markerLabel}: ${escHtml(item.title)}</div><div style="font-size:11px;color:#64748b;margin-top:4px;">Day ${day} | Stop ${label}</div>${item.address ? `<div style="font-size:11px;margin-top:4px;">Address: ${escHtml(item.address)}</div>` : ''}${item.opening_hours ? `<div style="font-size:11px;margin-top:4px;">Hours: ${escHtml(item.opening_hours)}</div>` : ''}<div style="font-size:11px;margin-top:4px;">${item.start_fmt} - ${item.end_fmt}${item.cost > 0 ? ` | RM${item.cost.toFixed(2)}` : ' | Free'}</div></div>`);
                    infoWindow.open(map, marker);
                });
                dayMarkers[day].push(marker);
            });

            if (allPoints.length >= 2) {
                const travelMode = getTravelMode(currentTransport);
                if (currentTransport === 'public_transport') renderSegmentedTransitRoute(day, allPoints, color);
                else renderWaypointRoute(day, allPoints, color, travelMode);
            }
        };
        fixedRenderDay.__originFixed = true;
        renderDay = fixedRenderDay;
    }

    function patchSetTransport() {
        if (typeof setTransport !== 'function' || setTransport.__originCostFixed) return;
        const original = setTransport;
        setTransport = function(mode) {
            const result = original(mode);
            setTimeout(updateCostSummary, 80);
            return result;
        };
        setTransport.__originCostFixed = true;
    }

    function applyFix() {
        applySavedOriginMarker();
        updateCostSummary();
        patchRenderDay();
        patchSetTransport();
        try {
            if (typeof renderAllDays === 'function' && typeof map !== 'undefined' && map) renderAllDays();
        } catch (e) {}
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyFix);
    else applyFix();
    window.addEventListener('load', () => setTimeout(applyFix, 600));
})();
