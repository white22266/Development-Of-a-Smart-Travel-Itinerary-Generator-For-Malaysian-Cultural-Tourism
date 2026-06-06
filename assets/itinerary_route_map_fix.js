/*
 * Official itinerary route map and whole-party cost controller.
 *
 * My Location is a separate convenience marker only. It never changes the
 * official itinerary origin, route, saved sequence, distance, or cost.
 */
let OFFICIAL_ROUTE_ORIGIN = null;
let ROUTE_PARTY_SIZE = 1;
let ROUTE_ROOM_COUNT = 1;

function routeFixValidPoint(point) {
    return point && Number.isFinite(Number(point.lat)) && Number.isFinite(Number(point.lng))
        && !(Number(point.lat) === 0 && Number(point.lng) === 0);
}

function routeFixSamePoint(a, b) {
    return routeFixValidPoint(a) && routeFixValidPoint(b)
        && Math.abs(Number(a.lat) - Number(b.lat)) < 0.00001
        && Math.abs(Number(a.lng) - Number(b.lng)) < 0.00001;
}

function routeFixLastPoint(items, preferHotel) {
    const reversed = [...(items || [])].reverse();
    if (preferHotel) {
        const hotel = reversed.find(item => item.type === 'hotel' && routeFixValidPoint(item));
        if (hotel) {
            return {
                lat: Number(hotel.lat),
                lng: Number(hotel.lng),
                name: hotel.title || 'Previous night hotel',
                source: 'hotel'
            };
        }
    }
    const last = reversed.find(item => routeFixValidPoint(item));
    return last ? {
        lat: Number(last.lat),
        lng: Number(last.lng),
        name: last.title || 'Previous day last stop',
        source: 'previous_stop'
    } : null;
}

function routeFixDayOrigin(day) {
    if (day === 1 && routeFixValidPoint(OFFICIAL_ROUTE_ORIGIN)) {
        return {
            lat: Number(OFFICIAL_ROUTE_ORIGIN.lat),
            lng: Number(OFFICIAL_ROUTE_ORIGIN.lng),
            name: OFFICIAL_ROUTE_ORIGIN.name || 'Confirmed Starting Location',
            source: 'official_origin'
        };
    }
    const previousItems = DAYS_DATA[day - 1] || [];
    return routeFixLastPoint(previousItems, true) || routeFixLastPoint(previousItems, false);
}

function routeFixOriginLabel(day, origin) {
    return origin && origin.source === 'hotel' ? `${day}H` : `${day}S`;
}

function routeFixOriginTitle(day, origin) {
    if (!origin) return `Day ${day} Starting Point`;
    if (origin.source === 'official_origin') return `Day ${day} Starting Point`;
    if (origin.source === 'hotel') return `Day ${day} Starting Point - Previous Night Hotel`;
    return `Day ${day} Starting Point - Previous Day Last Stop`;
}

function routeFixOriginSubtitle(origin) {
    if (!origin) return 'Route start point';
    if (origin.source === 'official_origin') return 'Confirmed Starting Location';
    if (origin.source === 'hotel') return 'Previous night hotel';
    return 'Previous day last stop';
}

function routeFixCreateOriginMarker(day, origin, color) {
    if (!routeFixValidPoint(origin)) return null;
    const position = { lat: Number(origin.lat), lng: Number(origin.lng) };
    const label = routeFixOriginLabel(day, origin);
    const marker = new google.maps.Marker({
        position,
        map,
        label: { text: label, color: '#fff', fontWeight: 'bold', fontSize: '10px' },
        title: routeFixOriginTitle(day, origin),
        zIndex: 9999,
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 17,
            fillColor: '#111827',
            fillOpacity: 0.96,
            strokeColor: color || '#fff',
            strokeWeight: 3
        }
    });

    marker.addListener('click', () => {
        infoWindow.setContent(
            `<div style="max-width:240px;">
                <div style="font-weight:800;font-size:13px;">${escHtml(routeFixOriginTitle(day, origin))}</div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;">${escHtml(routeFixOriginSubtitle(origin))}</div>
                <div style="font-size:11px;margin-top:4px;">${escHtml(origin.name || 'Starting point')}</div>
                <div style="font-size:11px;margin-top:6px;color:#64748b;">Not counted as a travel place.</div>
            </div>`
        );
        infoWindow.open(map, marker);
    });

    return marker;
}

function routeFixMoney(value) {
    return `RM ${Number(value || 0).toFixed(2)}`;
}

function routeFixItemCost(item) {
    const perUnit = Math.max(0, Number(item?.cost || 0));
    const isHotel = String(item?.type || '').toLowerCase() === 'hotel';
    const units = isHotel ? ROUTE_ROOM_COUNT : ROUTE_PARTY_SIZE;
    return {
        perUnit,
        whole: perUnit * units,
        unitText: isHotel ? `${routeFixMoney(perUnit)} per room` : `${routeFixMoney(perUnit)} per person`,
        wholeText: isHotel
            ? `${routeFixMoney(perUnit * units)} for ${units} room(s)`
            : `${routeFixMoney(perUnit * units)} for ${units} traveller(s)`,
    };
}

function routeFixUpdateCostDisplay() {
    const itemById = new Map();
    for (let day = 1; day <= TOTAL_DAYS; day++) {
        (DAYS_DATA[day] || []).forEach(item => itemById.set(String(item.item_id), item));
    }

    document.querySelectorAll('tr[data-item-id]').forEach(row => {
        const item = itemById.get(String(row.getAttribute('data-item-id') || ''));
        if (!item) return;
        const cells = row.querySelectorAll('td');
        const costCell = cells[cells.length - 1];
        if (!costCell) return;
        const cost = routeFixItemCost(item);
        if (cost.perUnit <= 0) {
            costCell.innerHTML = '<span style="color:#94a3b8;">Free</span>';
            return;
        }
        costCell.innerHTML = `
            <div style="font-weight:800;">${escHtml(cost.wholeText)}</div>
            <div style="font-size:10.5px;color:#64748b;margin-top:2px;">${escHtml(cost.unitText)}</div>
        `;
    });

    for (let day = 1; day <= TOTAL_DAYS; day++) {
        const box = document.getElementById('day-' + day);
        if (!box) continue;
        const total = (DAYS_DATA[day] || []).reduce((sum, item) => sum + routeFixItemCost(item).whole, 0);
        const candidates = [...box.querySelectorAll('div')];
        const totalElement = candidates.find(element => /^\s*Day\s+\d+\s+Total:/i.test(element.textContent || ''));
        if (totalElement) {
            totalElement.textContent = `Day ${day} Scheduled Places Total (Whole Party): ${routeFixMoney(total)}`;
        }
    }
}

function routeFixClearAllDays() {
    for (let day = 1; day <= TOTAL_DAYS; day++) {
        clearDayRoutes(day);
        if (dayMarkers[day]) {
            dayMarkers[day].forEach(marker => marker.setMap(null));
            delete dayMarkers[day];
        }
        dayVisible[day] = false;
        const legend = document.getElementById('legend-' + day);
        if (legend) legend.style.opacity = day === ACTIVE_DAY ? '1' : '0.35';
    }
}

function routeFixBoundsForDay(day) {
    if (!map) return;
    const bounds = new google.maps.LatLngBounds();
    let hasPoint = false;
    const origin = routeFixDayOrigin(day);
    if (routeFixValidPoint(origin)) {
        bounds.extend({ lat: Number(origin.lat), lng: Number(origin.lng) });
        hasPoint = true;
    }
    (DAYS_DATA[day] || []).forEach(item => {
        if (!routeFixValidPoint(item)) return;
        bounds.extend({ lat: Number(item.lat), lng: Number(item.lng) });
        hasPoint = true;
    });
    if (hasPoint) map.fitBounds(bounds, { padding: 60 });
}

async function routeFixLoadOfficialOrigin() {
    try {
        const response = await fetch('../api/itinerary_route_context.php?itinerary_id=' + encodeURIComponent(ITINERARY_ID), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.status === 'success') {
            if (routeFixValidPoint(data.origin)) {
                OFFICIAL_ROUTE_ORIGIN = {
                    lat: Number(data.origin.lat),
                    lng: Number(data.origin.lng),
                    name: data.origin.name || 'Confirmed Starting Location'
                };
            }
            ROUTE_PARTY_SIZE = Math.max(1, Number(data.party_size || 1));
            ROUTE_ROOM_COUNT = Math.max(1, Number(data.room_count || Math.ceil(ROUTE_PARTY_SIZE / 2)));
        }
    } catch (error) {
        console.warn('Official itinerary route/cost context could not be loaded.', error);
    }

    routeFixUpdateCostDisplay();
    if (typeof map !== 'undefined' && map) renderAllDays();
}

// Override the page map renderer. Default map view displays only the active day.
renderAllDays = function () {
    if (!map) return;
    clearRouteNotice();
    routeFixClearAllDays();
    dayVisible[ACTIVE_DAY] = true;
    renderDay(ACTIVE_DAY);
    setTimeout(() => routeFixBoundsForDay(ACTIVE_DAY), 350);
};

renderDay = function (day) {
    clearDayRoutes(day);
    if (dayMarkers[day]) {
        dayMarkers[day].forEach(marker => marker.setMap(null));
        delete dayMarkers[day];
    }
    if (!dayVisible[day]) return;

    const items = (DAYS_DATA[day] || []).filter(item => routeFixValidPoint(item));
    if (items.length === 0) return;

    const color = DAY_COLORS[day] || '#6366f1';
    const origin = routeFixDayOrigin(day);
    const firstPoint = { lat: Number(items[0].lat), lng: Number(items[0].lng) };
    const allPoints = [];

    dayMarkers[day] = [];
    if (routeFixValidPoint(origin) && !routeFixSamePoint(origin, firstPoint)) {
        allPoints.push({ lat: Number(origin.lat), lng: Number(origin.lng) });
        const originMarker = routeFixCreateOriginMarker(day, origin, color);
        if (originMarker) dayMarkers[day].push(originMarker);
    }

    items.forEach((item, index) => {
        const position = { lat: Number(item.lat), lng: Number(item.lng) };
        allPoints.push(position);
        const stopLabel = String.fromCharCode(65 + index);
        const displayLabel = String(day) + stopLabel;
        const marker = new google.maps.Marker({
            position,
            map,
            label: { text: displayLabel, color: '#fff', fontWeight: 'bold', fontSize: '10px' },
            title: item.title,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 15,
                fillColor: color,
                fillOpacity: 0.95,
                strokeColor: '#fff',
                strokeWeight: 2
            }
        });
        marker.addListener('click', () => {
            const cost = routeFixItemCost(item);
            const costHtml = cost.perUnit <= 0
                ? '<div style="font-size:11px;margin-top:4px;">Cost: Free</div>'
                : `<div style="font-size:11px;margin-top:4px;">Cost: ${escHtml(cost.wholeText)}<br><span style="color:#64748b;">${escHtml(cost.unitText)}</span></div>`;
            infoWindow.setContent(
                `<div style="max-width:240px;">
                    <div style="font-weight:800;font-size:13px;">${escHtml(item.title)}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Day ${day} | Stop ${stopLabel}</div>
                    ${item.address ? `<div style="font-size:11px;margin-top:4px;">Address: ${escHtml(item.address)}</div>` : ''}
                    ${item.opening_hours ? `<div style="font-size:11px;margin-top:4px;">Hours: ${escHtml(item.opening_hours)}</div>` : ''}
                    <div style="font-size:11px;margin-top:4px;">${item.start_fmt} - ${item.end_fmt}</div>
                    ${costHtml}
                </div>`
            );
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

fitAllBounds = function () {
    routeFixBoundsForDay(ACTIVE_DAY);
};

showDay = function (day) {
    ACTIVE_DAY = day;
    document.querySelectorAll('.day-box').forEach(element => element.style.display = 'none');
    const box = document.getElementById('day-' + day);
    if (box) box.style.display = 'block';
    const color = DAY_COLORS[day] || '#6366f1';
    document.querySelectorAll('.day-tab').forEach((button, index) => {
        const active = index + 1 === day;
        button.classList.toggle('active', active);
        button.style.background = active ? color : '';
        button.style.borderColor = active ? color : '';
        button.style.color = active ? '#fff' : '';
    });
    renderAllDays();
};

setTransport = function (mode) {
    currentTransport = normalizeTransportMode(mode);
    clearRouteNotice();
    document.querySelectorAll('.transport-btn').forEach(button => button.classList.remove('active'));
    const modeMap = { car: 0, motorcycle: 1, public_transport: 2, walking: 3 };
    const buttons = document.querySelectorAll('.transport-btn');
    if (buttons[modeMap[currentTransport]]) buttons[modeMap[currentTransport]].classList.add('active');
    renderAllDays();
};

// My Location only places/pans to a marker. It does not redraw or change Day 1 route.
locateMe = function () {
    if (!navigator.geolocation) {
        alert('Geolocation not supported.');
        return;
    }
    navigator.geolocation.getCurrentPosition(
        position => {
            userLat = position.coords.latitude;
            userLng = position.coords.longitude;
            placeUserMarker(userLat, userLng);
            map.panTo({ lat: userLat, lng: userLng });
            map.setZoom(14);
            loadWeather(userLat, userLng);
        },
        () => alert('Could not get your location. Please allow location access.')
    );
};

routeFixLoadOfficialOrigin();
