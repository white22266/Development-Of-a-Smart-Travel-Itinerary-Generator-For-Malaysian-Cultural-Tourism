// assets/itinerary_map.js
// The map on the itinerary page: one day at a time (or all days), the stops, the road route
// between them and the details of every leg. Leaflet draws it on OpenStreetMap tiles; the
// route comes from api/itinerary_route.php and the forecast from api/weather.php.
//
// The page sets these before loading this file:
//   ITINERARY_ID, TOTAL_DAYS, DAYS_DATA (day -> stops), DAY_COLORS (day -> colour),
//   START_TRANSPORT (car | motorcycle | public_transport | walking)

(function () {
    "use strict";

    var TILES = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
    var ATTRIBUTION = "&copy; <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\" rel=\"noopener\">OpenStreetMap</a> contributors";
    var MODE_NAME = { car: "Car", motorcycle: "Motorcycle", public_transport: "Public Transport", walking: "Walk" };
    var MODE_PHRASE = { car: "by car", motorcycle: "by motorcycle", public_transport: "by public transport", walking: "on foot" };
    var FALLBACK_COLOR = "#6366f1";

    var map = null;
    var drawn = null;        // layer group holding everything on show
    var markers = {};        // item id -> marker
    var legLines = {};       // item id -> line of the leg that arrives at that stop
    var routes = {};         // "day|mode" -> promise of that route from the server
    var activeDay = 1;       // the day whose timetable is on show
    var mapDay = 1;          // the day on the map; 0 = all days
    var transport = START_TRANSPORT;
    var drawNo = 0;          // only the latest drawing may touch the map
    var myMarker = null;
    var weather = null;

    function esc(value) {
        return String(value === null || value === undefined ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function hasPoint(p) {
        return !!p && isFinite(Number(p.lat)) && isFinite(Number(p.lng)) && !(Number(p.lat) === 0 && Number(p.lng) === 0);
    }

    function minutesText(minutes) {
        minutes = Math.round(Number(minutes) || 0);
        if (minutes < 60) return minutes + " min";
        return Math.floor(minutes / 60) + " h " + (minutes % 60 < 10 ? "0" : "") + (minutes % 60) + " min";
    }

    function kmText(km) {
        km = Number(km) || 0;
        return km < 1 ? Math.round(km * 1000) + " m" : km.toFixed(1) + " km";
    }

    function dayColor(day) {
        return DAY_COLORS[day] || FALLBACK_COLOR;
    }

    function setNotice(message) {
        var el = document.getElementById("routeNotice");
        if (!el) return;
        el.textContent = message || "";
        el.classList.toggle("visible", !!message);
    }

    function setSummary(html) {
        var el = document.getElementById("routeSummary");
        if (el) el.innerHTML = html || "";
    }

    function pin(label, color) {
        return L.divIcon({
            className: "iv-pin",
            html: "<span style=\"background:" + color + "\">" + esc(label) + "</span>",
            iconSize: [30, 30],
            iconAnchor: [15, 15],
            popupAnchor: [0, -14]
        });
    }

    /** The labels the timetable uses: places are numbered, a hotel is "H". */
    function stopLabels(items) {
        var number = 0;
        return items.map(function (item) { return item.type === "hotel" ? "H" : String(++number); });
    }

    function stopPopup(item, day, label) {
        return "<div class=\"iv-popup\">" +
            "<strong>" + esc(item.title) + "</strong>" +
            "<div class=\"iv-popup-meta\">Day " + day + (label === "H" ? " &middot; hotel" : " &middot; stop " + esc(label)) + "</div>" +
            (item.address ? "<div>" + esc(item.address) + "</div>" : "") +
            "<div>" + esc(item.start_fmt) + " - " + esc(item.end_fmt) + "</div>" +
            "<div>" + esc(item.cost_label || "Free") + "</div>" +
            "</div>";
    }

    /** One request per day and way of travelling; a failed one may be asked again. */
    function fetchRoute(day, mode) {
        var key = day + "|" + mode;
        if (!routes[key]) {
            var url = "../api/itinerary_route.php?itinerary_id=" + encodeURIComponent(ITINERARY_ID) +
                "&day=" + encodeURIComponent(day) + "&mode=" + encodeURIComponent(mode);
            routes[key] = fetch(url, { credentials: "same-origin" })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || data.status !== "ok") throw new Error("route unavailable");
                    return data;
                })
                .catch(function () {
                    delete routes[key];
                    return null;
                });
        }
        return routes[key];
    }

    function drawStops(day, bounds) {
        var items = (DAYS_DATA[day] || []).filter(hasPoint);
        var labels = stopLabels(items);
        items.forEach(function (item, index) {
            var marker = L.marker([item.lat, item.lng], {
                icon: pin(labels[index], item.type === "hotel" ? "#0f766e" : dayColor(day)),
                title: item.title
            }).bindPopup(stopPopup(item, day, labels[index]));
            drawn.addLayer(marker);
            markers[item.item_id] = marker;
            bounds.push([item.lat, item.lng]);
        });
        return items;
    }

    /** Draws where the day starts and its legs. Returns false when only straight lines could be drawn. */
    function drawRoute(day, route, items, bounds) {
        var color = dayColor(day);
        var start = route && hasPoint(route.start) ? route.start : null;
        // With all days on the map, a later day starts on the pin where the day before ended.
        if (start && !(mapDay === 0 && day > 1)) {
            var title = start.source === "origin" ? "Starting location"
                : (start.source === "hotel" ? "Start: last night's hotel" : "Start: where the day before ended");
            drawn.addLayer(L.marker([start.lat, start.lng], {
                icon: pin(start.source === "hotel" ? "H" : "S", "#111827"),
                title: title,
                zIndexOffset: -100
            }).bindPopup("<div class=\"iv-popup\"><strong>" + esc(title) + "</strong><div>" + esc(start.name) + "</div></div>"));
            bounds.push([start.lat, start.lng]);
        }

        if (route && route.road) {
            route.road.legs.forEach(function (leg) {
                if (!leg.geometry || leg.geometry.length < 2) return;
                var line = L.polyline(leg.geometry, { color: color, weight: 5, opacity: 0.85, dashArray: leg.on_foot ? "2 9" : null });
                drawn.addLayer(line);
                legLines[leg.to_item_id] = line;
                leg.geometry.forEach(function (point) { bounds.push(point); });
            });
            return true;
        }

        // No road route: join the points with straight dashed lines, so the order still shows.
        var points = (start ? [[start.lat, start.lng]] : []).concat(items.map(function (item) { return [item.lat, item.lng]; }));
        if (points.length >= 2) {
            drawn.addLayer(L.polyline(points, { color: color, weight: 3, opacity: 0.7, dashArray: "8 8" }));
            return false;
        }
        return true;   // a single point has no route to miss
    }

    function fitTo(bounds) {
        if (bounds.length === 1) map.setView(bounds[0], 15);
        else if (bounds.length > 1) map.fitBounds(L.latLngBounds(bounds), { padding: [40, 40], maxZoom: 16 });
    }

    /** Draw the day on show (or all days): the stops at once, the road route when it arrives. */
    function draw() {
        if (!map) return;
        var mine = ++drawNo;
        drawn.clearLayers();
        markers = {};
        legLines = {};
        setNotice("");

        var days = [];
        if (mapDay === 0) {
            for (var d = 1; d <= TOTAL_DAYS; d++) days.push(d);
        } else {
            days.push(mapDay);
        }
        var bounds = [];
        var itemsByDay = {};
        days.forEach(function (day) { itemsByDay[day] = drawStops(day, bounds); });
        fitTo(bounds);
        setSummary("<span class=\"spinner\"></span> Loading the road route...");

        Promise.all(days.map(function (day) { return fetchRoute(day, transport); })).then(function (results) {
            if (mine !== drawNo) return;   // the traveller has already moved to another day or mode
            var km = 0;
            var minutes = 0;
            var estimated = false;
            var missing = false;
            results.forEach(function (route, index) {
                var day = days[index];
                if (!drawRoute(day, route, itemsByDay[day], bounds)) missing = true;
                if (route && route.road) {
                    km += route.road.distance_km;
                    minutes += route.road.travel_time_min;
                    estimated = estimated || route.road.time_is_estimate;
                }
                if (day === activeDay) updateLegRows(day, route);
            });
            fitTo(bounds);

            var what = mapDay === 0 ? "All " + TOTAL_DAYS + " days" : "Day " + mapDay;
            setSummary(km > 0
                ? "<strong>" + what + " " + MODE_PHRASE[transport] + ":</strong> " + kmText(km) + " on the road, about " +
                    minutesText(minutes) + " of travel" + (estimated ? " (time estimated)" : "") + "."
                : "");
            if (missing) {
                setNotice("The road route could not be loaded just now, so the stops are joined with straight lines. Try again in a moment.");
            }
        });
    }

    /** Put the road figures of each leg next to its "Show Route" button. */
    function updateLegRows(day, route) {
        var box = document.getElementById("day-" + day);
        if (!box || !route || !route.road) return;
        route.road.legs.forEach(function (leg) {
            var holder = box.querySelector("[data-leg-to=\"" + leg.to_item_id + "\"]");
            var estimate = holder ? holder.querySelector(".leg-est") : null;
            if (!estimate) return;
            estimate.textContent = kmText(leg.distance_km) + " · about " + minutesText(leg.travel_time_min) + " " +
                (leg.on_foot ? "on foot" : MODE_PHRASE[transport]);
        });
    }

    function legHtml(leg) {
        var html = "<div class=\"ts-summary-bar\">" +
            "<div class=\"ts-summary-stat\">Distance <span>" + kmText(leg.distance_km) + "</span></div>" +
            "<div class=\"ts-summary-stat\">Time <span>about " + minutesText(leg.travel_time_min) + " " +
                (leg.on_foot ? "on foot" : MODE_PHRASE[transport]) + "</span></div>" +
            (leg.via ? "<span style=\"color:#64748b;font-size:11px;\">via " + esc(leg.via) + "</span>" : "") +
            "</div>";

        if (leg.on_foot) {
            html += "<div class=\"ts-stops\">These two stops are a short walk apart.</div>";
        } else if (transport === "public_transport") {
            html += "<div class=\"ts-warning\">No free timetable service covers public transport in Malaysia. This is the road " +
                "distance with an estimated travel time: check lines and departure times with the operator.</div>";
        } else if (leg.steps && leg.steps.length) {
            html += "<div class=\"ts-walk-steps\">" + leg.steps.map(function (step) {
                return "<div class=\"ts-walk-step\">" + esc(step.text) +
                    (step.distance_m > 0 ? " <span style=\"color:#94a3b8;\">" + kmText(step.distance_m / 1000) + "</span>" : "") + "</div>";
            }).join("") + "</div>";
        }

        if (leg.geometry && leg.geometry.length >= 2) {
            var from = leg.geometry[0];
            var to = leg.geometry[leg.geometry.length - 1];
            var engine = (transport === "walking" || leg.on_foot) ? "fossgis_osrm_foot" : "fossgis_osrm_car";
            html += "<div style=\"margin-top:8px;font-size:11.5px;\"><a target=\"_blank\" rel=\"noopener\" href=\"https://www.openstreetmap.org/directions?engine=" +
                engine + "&route=" + encodeURIComponent(from[0] + "," + from[1] + ";" + to[0] + "," + to[1]) + "\">Open this leg on OpenStreetMap</a></div>";
        }
        return html;
    }

    function closeLegs() {
        document.querySelectorAll(".transit-detail.open").forEach(function (detail) {
            detail.classList.remove("open");
            detail.innerHTML = "";
        });
        document.querySelectorAll("[data-leg-toggle]").forEach(function (button) { button.textContent = "Show Route"; });
        Object.keys(legLines).forEach(function (id) { legLines[id].setStyle({ weight: 5, opacity: 0.85 }); });
    }

    /** Open or close the details of the leg that arrives at a stop, and show that leg on the map. */
    function toggleLeg(itemId) {
        var holder = document.querySelector("#day-" + activeDay + " [data-leg-to=\"" + itemId + "\"]");
        var detail = holder ? holder.querySelector(".transit-detail") : null;
        var button = holder ? holder.querySelector("[data-leg-toggle]") : null;
        if (!detail) return;

        var wasOpen = detail.classList.contains("open");
        closeLegs();
        if (wasOpen) return;

        detail.classList.add("open");
        detail.innerHTML = "<div class=\"ts-loading\"><span class=\"spinner\"></span> Loading the route...</div>";
        if (button) button.textContent = "Hide Route";

        fetchRoute(activeDay, transport).then(function (route) {
            if (!detail.classList.contains("open")) return;
            var leg = null;
            if (route && route.road) {
                route.road.legs.forEach(function (candidate) {
                    if (String(candidate.to_item_id) === String(itemId)) leg = candidate;
                });
            }
            detail.innerHTML = leg ? legHtml(leg) : "<div class=\"ts-warning\">Road details for this leg are not available right now.</div>";
            var line = legLines[itemId];
            if (line) {
                line.setStyle({ weight: 9, opacity: 1 }).bringToFront();
                map.fitBounds(line.getBounds(), { padding: [50, 50], maxZoom: 17 });
            }
        });
    }

    function focusStop(itemId) {
        var marker = markers[itemId];
        if (!map || !marker) return;
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 16));
        marker.openPopup();
    }

    /** Dim the legend chips of the days that are not on the map. */
    function markLegend() {
        document.querySelectorAll(".legend-item[data-map-day]").forEach(function (chip) {
            chip.style.opacity = (mapDay === 0 || Number(chip.dataset.mapDay) === mapDay) ? "1" : "0.4";
        });
    }

    /** Show one day: its timetable below and its route on the map. */
    function showDay(day) {
        activeDay = day;
        mapDay = day;
        closeLegs();
        document.querySelectorAll(".day-box").forEach(function (box) { box.style.display = "none"; });
        var box = document.getElementById("day-" + day);
        if (box) box.style.display = "block";
        document.querySelectorAll(".day-tab").forEach(function (button) {
            var active = Number(button.dataset.dayTab) === day;
            button.classList.toggle("active", active);
            button.style.background = active ? dayColor(day) : "";
            button.style.borderColor = active ? dayColor(day) : "";
            button.style.color = active ? "#fff" : "";
        });
        markLegend();
        showWeather(day);
        draw();
    }

    /** Show every day on the map at once. The timetable stays on the day it was on. */
    function showAllDays() {
        mapDay = 0;
        closeLegs();
        markLegend();
        draw();
    }

    /** Draw the same stops for another way of travelling. Nothing is saved. */
    function setTransport(mode) {
        if (!MODE_NAME[mode]) return;
        transport = mode;
        closeLegs();
        document.querySelectorAll(".transport-btn").forEach(function (button) {
            button.classList.toggle("active", button.dataset.mode === mode);
        });
        var label = document.getElementById("transitModeName");
        if (label) label.textContent = MODE_NAME[mode];
        draw();
    }

    function showWeather(day) {
        var chip = document.getElementById("weatherChip");
        if (!chip) return;
        var entry = weather && weather.days ? weather.days[String(day)] : null;
        chip.classList.remove("wet");
        chip.title = weather ? weather.credit : "";
        if (!entry) {
            chip.textContent = "Weather: --";
        } else if (entry.forecast) {
            chip.textContent = "Day " + day + ": " + entry.forecast.summary + ", " + entry.forecast.temp_min + "-" + entry.forecast.temp_max + " C" +
                (entry.forecast.rain_chance !== null ? ", rain " + entry.forecast.rain_chance + "%" : "");
            chip.title = entry.text + " " + weather.credit;
            chip.classList.toggle("wet", !!entry.forecast.wet);
        } else {
            chip.textContent = "Weather: " + (entry.note || "no forecast");
        }
    }

    function loadWeather() {
        fetch("../api/weather.php?itinerary_id=" + encodeURIComponent(ITINERARY_ID), { credentials: "same-origin" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.status === "ok") {
                    weather = data;
                    showWeather(activeDay);
                }
            })
            .catch(function () {});
    }

    /** A marker for where the traveller is now. It never changes the itinerary or its route. */
    function locateMe() {
        if (!map) return;
        if (!navigator.geolocation) {
            setNotice("This browser cannot share its location.");
            return;
        }
        navigator.geolocation.getCurrentPosition(function (position) {
            var here = [position.coords.latitude, position.coords.longitude];
            if (myMarker) map.removeLayer(myMarker);
            myMarker = L.circleMarker(here, { radius: 9, color: "#fff", weight: 3, fillColor: "#4f46e5", fillOpacity: 1 })
                .bindPopup("<div class=\"iv-popup\"><strong>My location</strong></div>").addTo(map);
            map.setView(here, 14);
        }, function () {
            setNotice("Your location could not be read. Allow location access for this site and try again.");
        }, { timeout: 8000, maximumAge: 60000 });
    }

    function init() {
        var mapEl = document.getElementById("map");
        if (!mapEl) return;
        if (!window.L) {
            var panel = document.getElementById("mapErrorPanel");
            if (panel) panel.classList.add("visible");
            return;
        }
        map = L.map(mapEl).setView([4.2, 108.0], 5);
        L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION }).addTo(map);
        drawn = L.layerGroup().addTo(map);
        // The page layout settles after the map is created, and the sidebar can be folded away:
        // whenever the box changes size the map has to measure itself again.
        if (window.ResizeObserver) new ResizeObserver(function () { map.invalidateSize(); }).observe(mapEl);
        else window.addEventListener("load", function () { map.invalidateSize(); });

        document.addEventListener("click", function (event) {
            var target = event.target;
            if (!target.closest) return;
            var hit;
            if ((hit = target.closest("[data-day-tab]"))) {
                showDay(Number(hit.dataset.dayTab));
            } else if ((hit = target.closest("[data-map-day]"))) {
                var day = Number(hit.dataset.mapDay);
                if (day === 0) showAllDays(); else showDay(day);
            } else if ((hit = target.closest(".transport-btn[data-mode]"))) {
                setTransport(hit.dataset.mode);
            } else if ((hit = target.closest("[data-leg-toggle]"))) {
                toggleLeg(hit.dataset.legToggle);
            } else if (target.closest("#locateButton")) {
                locateMe();
            } else if ((hit = target.closest("tr[data-item-id]")) && !target.closest("a, button")) {
                focusStop(hit.dataset.itemId);
            }
        });

        showDay(1);
        loadWeather();
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();
