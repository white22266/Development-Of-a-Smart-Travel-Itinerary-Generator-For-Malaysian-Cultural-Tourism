/*
 * Official itinerary route map controller.
 *
 * My Location is a separate convenience marker only. It never changes the
 * official itinerary origin, route, saved sequence, distance, or cost.
 */
let OFFICIAL_ROUTE_ORIGIN = null;

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
        if (hotel) return { lat: Number(hotel.lat), lng: Number(hotel.lng), name: hotel.title || 'Hotel' };
    }
    const last = reversed.find(item => routeFixValidPoint(item));
    return last ? { lat: Number(last.lat), lng: Number(last.lng), name: last.title || 'Previous stop' } : null;
}

function routeFixDayOrigin(day) {
    if (day === 1) return OFFICIAL_ROUTE_ORIGIN;
    const previousItems = DAYS_DATA[day - 1] || [];
    return routeFixLastPoint(previousItems, true) || routeFixLastPoint(previousItems, false);
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
        if (data.status === 'success' && routeFixValidPoint(data.origin)) {
            OFFICIAL_ROUTE_ORIGIN = {
                lat: Number(data.origin.lat),
                lng: Number(data.origin.lng),
                name: data.origin.name || 'Confirmed Starting Location'
            };
        }
    } catch (error) {
        console.warn('Official itinerary origin could not be loaded.', error);
    }

    if (typeof map !== 'undefined' && map) {
        renderAllDays();
    }
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
    if (routeFixValidPoint(origin) && !routeFixSamePoint(origin, firstPoint)) {
        allPoints.push({ lat: Number(origin.lat), lng: Number(origin.lng) });
    }

    dayMarkers[day] = [];
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
            infoWindow.setContent(
                `<div style="max-width:230px;">
                    <div style="font-weight:800;font-size:13px;">${escHtml(item.title)}</div>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Day ${day} | Stop ${stopLabel}</div>
                    ${item.address ? `<div style="font-size:11px;margin-top:4px;">Address: ${escHtml(item.address)}</div>` : ''}
                    ${item.opening_hours ? `<div style="font-size:11px;margin-top:4px;">Hours: ${escHtml(item.opening_hours)}</div>` : ''}
                    <div style="font-size:11px;margin-top:4px;">${item.start_fmt} - ${item.end_fmt}</div>
                </div>`
            );
            infoWindow.open(map, marker);
        });
        dayMarkers[day].push(marker);
    });

    if (allPoints.length >= 2) {
        const travelMode = getTravelMode(currentTransport);
        if (currentTransport === 'public_transport') {
            renderSegmentedTransitRoute(day, allPoints, color);
        } else {
            renderWaypointRoute(day, allPoints, color, travelMode);
        }
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
