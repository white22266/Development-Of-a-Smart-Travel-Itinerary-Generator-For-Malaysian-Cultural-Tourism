// assets/itinerary_map.js
// The map on the itinerary page: one day at a time (or all days), the stops, the route
// between them and the details of every leg. Leaflet draws it on OpenStreetMap tiles. The
// route comes from api/itinerary_route.php, the forecast from api/weather.php and what is
// happening on the road from api/road_conditions.php; "Optimise route" asks
// api/itinerary_optimise.php for the best order of the stops. The live buses and trains are
// drawn by assets/live_buses.js, which the page loads before this file.
//
// The page sets these before loading this file:
//   ITINERARY_ID, TOTAL_DAYS, DAYS_DATA (day -> stops), DAY_COLORS (day -> colour),
//   START_TRANSPORT (car | motorcycle | public_transport | walking), START_DATE ("2026-11-14" or null)
// and may set ITINERARY_MAP_T: the texts of the page's language ({key: text}). Every text has an
// English default in this file, so a page that passes none still works.

(function () {
    "use strict";

    var TILES = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
    var ATTRIBUTION = "&copy; <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\" rel=\"noopener\">OpenStreetMap</a> contributors";
    // Texts of the page's language (ITINERARY_MAP_T, set by the page); the English text is the default.
    var TXT = (typeof ITINERARY_MAP_T !== "undefined" && ITINERARY_MAP_T) || {};

    /** A text of the page's language, or the English default; {name} is replaced from vars. */
    function tr(key, english, vars) {
        var text = TXT[key] || english;
        if (vars) Object.keys(vars).forEach(function (name) { text = text.split("{" + name + "}").join(String(vars[name])); });
        return text;
    }

    var MODE_NAME = { car: 1, motorcycle: 1, public_transport: 1, walking: 1 };

    function modeName(mode) {
        return {
            car: tr("opt.transport.car", "Car"),
            motorcycle: tr("opt.transport.motorcycle", "Motorcycle"),
            public_transport: tr("opt.transport.public_transport", "Public Transport"),
            walking: tr("iv.walk", "Walk")
        }[mode];
    }

    function modePhrase(mode) {
        return {
            car: tr("im.by_car", "by car"),
            motorcycle: tr("im.by_motorcycle", "by motorcycle"),
            public_transport: tr("im.by_public", "by public transport"),
            walking: tr("im.on_foot", "on foot")
        }[mode];
    }

    /** The kind of a ride as the server names it (bus, train, ferry, cable car, ride), in the page's language. */
    function kindName(kind) {
        var known = { bus: "Bus", train: "Train", ferry: "Ferry", "cable car": "Cable car", ride: "Ride" };
        return known[kind] ? tr("im.k_" + kind.replace(" ", "_"), known[kind]) : capital(kind);
    }

    // The weather words of services/WeatherService.php (English) and the keys of their translations.
    var WX_WORDS = {
        "Clear sky": "clear", "Mainly clear": "mainly_clear", "Partly cloudy": "partly_cloudy", "Overcast": "overcast",
        "Fog": "fog", "Light drizzle": "light_drizzle", "Drizzle": "drizzle", "Heavy drizzle": "heavy_drizzle",
        "Freezing drizzle": "freezing_drizzle", "Light rain": "light_rain", "Rain": "rain", "Heavy rain": "heavy_rain",
        "Freezing rain": "freezing_rain", "Light snow": "light_snow", "Snow": "snow", "Heavy snow": "heavy_snow",
        "Snow grains": "snow_grains", "Light showers": "light_showers", "Showers": "showers", "Heavy showers": "heavy_showers",
        "Snow showers": "snow_showers", "Thunderstorm": "thunderstorm", "Thunderstorm with hail": "thunderstorm_hail", "Unknown": "unknown"
    };
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
    var shown = [];          // the points the map was last fitted to
    var myMarker = null;
    var weather = null;
    var buses = null;            // the live layer of buses and trains (assets/live_buses.js)
    var busesFollowMode = false; // it was switched on because the trip goes by public transport
    var conditionLayer = null;   // what the road conditions put on the map
    var conditionMarkers = [];   // item number -> its marker
    var conditions = null;       // the last answer of api/road_conditions.php
    var CONDITION_MARK = { jam: "J", road_closed: "X", lane_closed: "L", roadworks: "R", accident: "A", flooding: "W", breakdown: "B", fire: "F" };

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
        if (minutes < 60) return tr("trip.min", "{n} min", { n: minutes });
        return tr("im.h_min", "{h} h {mm} min", { h: Math.floor(minutes / 60), mm: (minutes % 60 < 10 ? "0" : "") + (minutes % 60) });
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
            "<div class=\"iv-popup-meta\">" + esc(tr("trip.day", "Day {n}", { n: day })) + " &middot; " +
                esc(label === "H" ? tr("im.hotel", "hotel") : tr("im.stop", "stop {n}", { n: label })) + "</div>" +
            (item.address ? "<div>" + esc(item.address) + "</div>" : "") +
            "<div>" + esc(item.start_fmt) + " - " + esc(item.end_fmt) + "</div>" +
            "<div>" + esc(item.cost_label || tr("iv.free", "Free")) + "</div>" +
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

    /** The lines a leg is drawn with: one, or for a public transport connection one per walk and ride. */
    function legPieces(leg, color) {
        if (leg.transit && leg.transit.segments) {
            return leg.transit.segments.filter(function (segment) {
                return segment.geometry && segment.geometry.length >= 2;
            }).map(function (segment) {
                var walk = segment.kind === "walk";
                return { points: segment.geometry, color: walk ? color : (segment.color || color), walk: walk };
            });
        }
        if (!leg.geometry || leg.geometry.length < 2) return [];
        return [{ points: leg.geometry, color: color, walk: !!leg.on_foot, estimated: !!leg.estimated }];
    }

    function legPoints(leg) {
        var points = [];
        legPieces(leg, "").forEach(function (piece) { points = points.concat(piece.points); });
        return points;
    }

    /** An arrow in the middle of a line, pointing the way of travel. A line too short to need one gets none. */
    function arrowOn(points) {
        var length = 0;
        for (var i = 1; i < points.length; i++) length += map.distance(points[i - 1], points[i]);
        if (length < 250) return null;
        var covered = 0;
        for (var j = 1; j < points.length; j++) {
            var step = map.distance(points[j - 1], points[j]);
            if (step > 0 && covered + step >= length / 2) {
                var a = points[j - 1];
                var b = points[j];
                var part = (length / 2 - covered) / step;
                var bearing = Math.atan2((b[1] - a[1]) * Math.cos(a[0] * Math.PI / 180), b[0] - a[0]) * 180 / Math.PI;
                return L.marker([a[0] + (b[0] - a[0]) * part, a[1] + (b[1] - a[1]) * part], {
                    icon: L.divIcon({
                        className: "iv-arrow",
                        html: "<span style=\"transform:rotate(" + bearing.toFixed(0) + "deg)\"></span>",
                        iconSize: [14, 13],
                        iconAnchor: [7, 6]
                    }),
                    interactive: false,
                    keyboard: false
                });
            }
            covered += step;
        }
        return null;
    }

    /** A leg on the map: a white edge under a coloured line (dotted when walked, dashed when only estimated) and an arrow. */
    function legLayer(leg, color) {
        var pieces = legPieces(leg, color);
        if (!pieces.length) return null;
        var group = L.featureGroup();
        var longest = pieces[0];
        pieces.forEach(function (piece) {
            if (!piece.walk) {
                group.addLayer(L.polyline(piece.points, { color: "#fff", weight: 9, opacity: 0.9, interactive: false, isEdge: true }));
            }
            group.addLayer(L.polyline(piece.points, {
                color: piece.color,
                weight: 5,
                opacity: 0.9,
                dashArray: piece.walk ? "2 9" : (piece.estimated ? "10 8" : null)
            }));
            if (piece.points.length > longest.points.length) longest = piece;
        });
        var arrow = arrowOn(longest.points);
        if (arrow) group.addLayer(arrow);
        return group;
    }

    /** Make a leg stand out on the map, or put it back. */
    function emphasise(layer, on) {
        layer.eachLayer(function (line) {
            if (!line.setStyle) return;
            line.setStyle(line.options.isEdge ? { weight: on ? 13 : 9 } : { weight: on ? 8 : 5, opacity: on ? 1 : 0.9 });
        });
        if (on) layer.bringToFront();
    }

    function capital(text) {
        text = String(text || "");
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    /** A leg in one line, as the timetable shows it next to "Show Route". */
    function legSummary(leg) {
        if (leg.transit) {
            var rides = leg.transit.segments.filter(function (segment) { return segment.kind !== "walk"; })
                .map(function (segment) { return kindName(segment.kind) + (segment.line ? " " + segment.line : ""); });
            return rides.join(" + ") + " · " + tr("im.leg_transit", "{time} with walking and waiting, leaves {depart}",
                { time: minutesText(leg.travel_time_min), depart: leg.transit.depart });
        }
        return kmText(leg.distance_km) + " · " + tr("im.about", "about {time}", { time: minutesText(leg.travel_time_min) }) + " " +
            (leg.on_foot ? tr("im.on_foot", "on foot") : modePhrase(transport)) + (leg.estimated ? " " + tr("im.estimate", "(estimate)") : "");
    }

    /** Draws where the day starts and its legs. Returns false when only straight lines could be drawn. */
    function drawRoute(day, route, items, bounds) {
        var color = dayColor(day);
        var start = route && hasPoint(route.start) ? route.start : null;
        // With all days on the map, a later day starts on the pin where the day before ended.
        if (start && !(mapDay === 0 && day > 1)) {
            var title = start.source === "origin" ? tr("iv.starting_location", "Starting location")
                : (start.source === "hotel" ? tr("im.start_hotel", "Start: last night's hotel") : tr("im.start_prev", "Start: where the day before ended"));
            drawn.addLayer(L.marker([start.lat, start.lng], {
                icon: pin(start.source === "hotel" ? "H" : "S", "#111827"),
                title: title,
                zIndexOffset: -100
            }).bindPopup("<div class=\"iv-popup\"><strong>" + esc(title) + "</strong><div>" + esc(start.name) + "</div></div>"));
            bounds.push([start.lat, start.lng]);
        }

        if (route && route.road) {
            route.road.legs.forEach(function (leg) {
                var layer = legLayer(leg, color);
                if (!layer) return;
                drawn.addLayer(layer);
                legLines[leg.to_item_id] = layer;
                legPoints(leg).forEach(function (point) { bounds.push(point); });
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
        shown = bounds;
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
        setSummary("<span class=\"spinner\"></span> " + esc(tr("im.loading_route", "Loading the road route...")));

        Promise.all(days.map(function (day) { return fetchRoute(day, transport); })).then(function (results) {
            if (mine !== drawNo) return;   // the traveller has already moved to another day or mode
            var km = 0;
            var minutes = 0;
            var estimated = false;
            var missing = false;
            var rides = [];
            results.forEach(function (route, index) {
                var day = days[index];
                if (!drawRoute(day, route, itemsByDay[day], bounds)) missing = true;
                if (route && route.road) {
                    km += route.road.distance_km;
                    minutes += route.road.travel_time_min;
                    estimated = estimated || route.road.time_is_estimate;
                }
                if (day === activeDay) updateLegRows(day, route);
                ((route && route.road && route.road.legs) || []).forEach(function (leg) {
                    ((leg.transit && leg.transit.segments) || []).forEach(function (segment) {
                        if (segment.kind !== "walk" && segment.line) rides.push(segment.line);
                    });
                });
            });
            if (buses) buses.pickOut(rides);   // the lines of the traveller's own connections stand out
            fitTo(bounds);

            var what = mapDay === 0 ? tr("im.all_n_days", "All {n} days", { n: TOTAL_DAYS }) : tr("trip.day", "Day {n}", { n: mapDay });
            setSummary(km > 0
                ? "<strong>" + esc(what + " " + modePhrase(transport)) + ":</strong> " +
                    esc(tr("im.road_total", "{km} on the road, about {time} of travel", { km: kmText(km), time: minutesText(minutes) }) +
                        (estimated ? " " + tr("im.time_estimated", "(time estimated)") : "")) + "."
                : "");
            if (missing) {
                setNotice(tr("im.route_missing", "The road route could not be loaded just now, so the stops are joined with straight lines. Try again in a moment."));
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
            estimate.textContent = legSummary(leg);
        });
    }

    function legHtml(leg) {
        var html = "<div class=\"ts-summary-bar\">" +
            "<div class=\"ts-summary-stat\">" + esc(tr("im.distance", "Distance")) + " <span>" + kmText(leg.distance_km) + "</span></div>" +
            "<div class=\"ts-summary-stat\">" + esc(tr("im.time", "Time")) + " <span>" +
                esc((leg.transit ? minutesText(leg.travel_time_min) : tr("im.about", "about {time}", { time: minutesText(leg.travel_time_min) })) + " " +
                (leg.on_foot ? tr("im.on_foot", "on foot") : modePhrase(transport))) + "</span></div>" +
            (leg.via ? "<span style=\"color:#64748b;font-size:11px;\">" + esc(tr("im.via", "via {road}", { road: leg.via })) + "</span>" : "") +
            "</div>";

        if (leg.transit) {
            // A connection from the timetables: every walk and ride with its time.
            html += "<div class=\"ts-walk-steps\">" + leg.transit.segments.map(function (segment) {
                var what;
                if (segment.kind === "walk") {
                    what = esc(tr("im.walk_to", "Walk {dist}to {to}", {
                        dist: segment.distance_km > 0 ? kmText(segment.distance_km) + " " : "",
                        to: segment.to || leg.to
                    }));
                } else {
                    what = "<strong>" + esc(kindName(segment.kind) + (segment.line ? " " + segment.line : "")) + "</strong>" +
                        (segment.towards ? " (" + esc(segment.towards) + ")" : "") +
                        " " + esc(tr("im.from_to", "from {from} to {to}", { from: segment.from, to: segment.to })) +
                        (segment.operator ? " <span style=\"color:#94a3b8;\">" + esc(segment.operator) + "</span>" : "");
                }
                return "<div class=\"ts-walk-step\">" + esc(segment.depart) + " &middot; " + what +
                    " <span style=\"color:#94a3b8;\">" + minutesText(segment.minutes) + "</span></div>";
            }).join("") + "</div>" +
            "<div class=\"ts-stops\">" + esc(tr("im.arrives", "Arrives {time}.", { time: leg.transit.arrive }) +
                (leg.transit.typical ? " " + tr("im.typical", "These are the times now in force for this day of the week; check again closer to the trip.") : "") +
                " " + tr("im.timetables", "Timetables: Transitous and the operators.")) + "</div>";
        } else if (leg.estimated) {
            html += "<div class=\"ts-warning\">" + esc(tr("im.no_timetable", "No timetable connection is known for this leg. This is the road distance with an estimated time: check lines and departures with the local operator, or take a taxi or e-hailing car.")) + "</div>";
        } else if (leg.steps && leg.steps.length) {
            html += "<div class=\"ts-walk-steps\">" + leg.steps.map(function (step) {
                return "<div class=\"ts-walk-step\">" + esc(step.text) +
                    (step.distance_m > 0 ? " <span style=\"color:#94a3b8;\">" + kmText(step.distance_m / 1000) + "</span>" : "") + "</div>";
            }).join("") + "</div>";
        } else if (leg.on_foot) {
            html += "<div class=\"ts-stops\">" + esc(tr("im.short_walk", "These two stops are a short walk apart.")) + "</div>";
        }

        var points = leg.transit ? [] : legPoints(leg);
        if (points.length >= 2) {
            var from = points[0];
            var to = points[points.length - 1];
            var engine = (transport === "walking" || leg.on_foot) ? "fossgis_osrm_foot" : "fossgis_osrm_car";
            html += "<div style=\"margin-top:8px;font-size:11.5px;\"><a target=\"_blank\" rel=\"noopener\" href=\"https://www.openstreetmap.org/directions?engine=" +
                engine + "&route=" + encodeURIComponent(from[0] + "," + from[1] + ";" + to[0] + "," + to[1]) + "\">" + esc(tr("im.open_osm", "Open this leg on OpenStreetMap")) + "</a></div>";
        }
        return html;
    }

    function closeLegs() {
        document.querySelectorAll(".transit-detail.open").forEach(function (detail) {
            detail.classList.remove("open");
            detail.innerHTML = "";
        });
        document.querySelectorAll("[data-leg-toggle]").forEach(function (button) { button.textContent = tr("iv.show_route", "Show Route"); });
        Object.keys(legLines).forEach(function (id) { emphasise(legLines[id], false); });
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
        detail.innerHTML = "<div class=\"ts-loading\"><span class=\"spinner\"></span> " + esc(tr("im.loading_leg", "Loading the route...")) + "</div>";
        if (button) button.textContent = tr("im.hide_route", "Hide Route");

        fetchRoute(activeDay, transport).then(function (route) {
            if (!detail.classList.contains("open")) return;
            var leg = null;
            if (route && route.road) {
                route.road.legs.forEach(function (candidate) {
                    if (String(candidate.to_item_id) === String(itemId)) leg = candidate;
                });
            }
            detail.innerHTML = leg ? legHtml(leg) : "<div class=\"ts-warning\">" + esc(tr("im.leg_missing", "Road details for this leg are not available right now.")) + "</div>";
            var line = legLines[itemId];
            if (line) {
                emphasise(line, true);
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
        clearConditions();
        if (dayDate(day) === isoDate(new Date())) loadConditions();   // on the day itself, without being asked
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
        if (label) label.textContent = modeName(mode);
        draw();
        clearConditions();
        followTransport();
    }

    /** The weather word of the server in the page's language (an unknown word stays as it is). */
    function weatherWord(summary) {
        var key = WX_WORDS[summary];
        return key ? tr("im.wx_" + key, summary) : summary;
    }

    /** The forecast of a day in a sentence, worked out here from the figures so that it is in the page's language. */
    function weatherSentence(forecast) {
        var vars = { summary: weatherWord(forecast.summary), min: forecast.temp_min, max: forecast.temp_max, n: forecast.rain_chance };
        var text = forecast.rain_chance !== null
            ? tr("im.wx_describe_rain", "{summary}, {min} to {max} C, {n}% chance of rain.", vars)
            : tr("im.wx_describe", "{summary}, {min} to {max} C.", vars);
        var advice = forecast.wet ? tr("im.wx_adv_wet", "Bring an umbrella and put indoor places such as museums first.")
            : (forecast.temp_max >= 33 ? tr("im.wx_adv_hot", "It will be hot: keep outdoor stops for the morning or the evening.")
                : tr("im.wx_adv_good", "Good conditions for sightseeing."));
        return text + " " + advice;
    }

    /** The reason why a day has no forecast; the fixed sentences of api/weather.php in the page's language. */
    function weatherNote(note, day) {
        var fixed = {
            "Set a start date to see the forecast.": ["im.wx_n_start", note],
            "No stop with a location on this day.": ["im.wx_n_nostop", note],
            "This day is in the past.": ["im.wx_n_past", note],
            "The weather service could not be reached.": ["im.wx_n_down", note]
        };
        if (fixed[note]) return tr(fixed[note][0], note);
        if (/^Forecast available from /.test(note)) {
            var from = dayDate(day);
            var shown = note.replace(/^Forecast available from /, "").replace(/\.$/, "");
            if (from) {
                var date = new Date(from + "T00:00:00");
                date.setDate(date.getDate() - 15);
                var lang = (document.documentElement.lang || "en");
                var locale = lang === "zh" ? "zh-CN" : (lang === "ms" ? "ms-MY" : "en-GB");
                try { shown = date.toLocaleDateString(locale, { day: "numeric", month: "short", year: "numeric" }); } catch (e) { /* keep the server's date */ }
            }
            return tr("im.wx_n_from", "Forecast available from {date}.", { date: shown });
        }
        return note;
    }

    function weatherCredit(credit) {
        return credit === "Weather data by Open-Meteo.com" ? tr("im.wx_credit", credit) : credit;
    }

    function showWeather(day) {
        var chip = document.getElementById("weatherChip");
        if (!chip) return;
        var entry = weather && weather.days ? weather.days[String(day)] : null;
        chip.classList.remove("wet");
        chip.title = weather ? weatherCredit(weather.credit) : "";
        if (!entry) {
            chip.textContent = tr("im.wx_none", "Weather: --");
        } else if (entry.forecast) {
            var f = entry.forecast;
            chip.textContent = tr("im.wx_chip", "Day {day}: {summary}, {min}-{max} C", { day: day, summary: weatherWord(f.summary), min: f.temp_min, max: f.temp_max }) +
                (f.rain_chance !== null ? tr("im.wx_chip_rain", ", rain {n}%", { n: f.rain_chance }) : "");
            chip.title = weatherSentence(f) + " " + weatherCredit(weather.credit);
            chip.classList.toggle("wet", !!f.wet);
        } else {
            chip.textContent = tr("im.wx_prefix", "Weather: {text}", { text: entry.note ? weatherNote(entry.note, day) : tr("im.wx_no_forecast", "no forecast") });
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
            setNotice(tr("im.geo_unsupported", "This browser cannot share its location."));
            return;
        }
        navigator.geolocation.getCurrentPosition(function (position) {
            var here = [position.coords.latitude, position.coords.longitude];
            if (myMarker) map.removeLayer(myMarker);
            myMarker = L.circleMarker(here, { radius: 9, color: "#fff", weight: 3, fillColor: "#4f46e5", fillOpacity: 1 })
                .bindPopup("<div class=\"iv-popup\"><strong>" + esc(tr("iv.my_location", "My Location")) + "</strong></div>").addTo(map);
            map.setView(here, 14);
        }, function () {
            setNotice(tr("im.geo_denied", "Your location could not be read. Allow location access for this site and try again."));
        }, { timeout: 8000, maximumAge: 60000 });
    }

    /** What the optimiser did, in a sentence of the page's language (the server's English sentence is the fallback). */
    function optimiseMessage(data) {
        if (!data || !Array.isArray(data.reordered_days)) return data && data.message ? data.message : "";
        var days = data.reordered_days;
        var message;
        if (days.length) {
            var list = days.length === 1 ? String(days[0])
                : days.slice(0, -1).join(tr("im.list_sep", ", ")) + tr("im.list_and", " and ") + days[days.length - 1];
            var saved = data.saved_min > 0 ? tr("im.opt_saved", ", with {n} min less travelling", { n: data.saved_min }) : "";
            message = days.length === 1
                ? tr("im.opt_one", "Day {days} is now in a shorter order{saved}. Times are up to date.", { days: list, saved: saved })
                : tr("im.opt_many", "Days {days} are now in a shorter order{saved}. Times are up to date.", { days: list, saved: saved });
        } else {
            message = tr("im.opt_best", "Every day is already in its best order. Times are up to date.");
        }
        if (data.removed && data.removed.length) {
            message += " " + tr("im.opt_removed", "Removed because no order keeps it within the trip's rules: {list}.", { list: data.removed.join("; ") });
        }
        return message;
    }

    /** Ask the server for the best order of every day. The page is loaded again to show the new timetable. */
    function optimiseRoute() {
        var button = document.getElementById("optimiseButton");
        if (!button || button.disabled) return;
        button.disabled = true;
        button.textContent = tr("iv.optimising", "Optimising...");
        var body = new URLSearchParams();
        body.set("itinerary_id", ITINERARY_ID);
        fetch("../api/itinerary_optimise.php", { method: "POST", credentials: "same-origin", body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || data.status !== "ok") throw new Error("not optimised");
                try { window.sessionStorage.setItem("iv-notice", optimiseMessage(data)); } catch (e) { /* the message is only a courtesy */ }
                window.location.reload();
            })
            .catch(function () {
                button.disabled = false;
                button.textContent = tr("iv.optimise", "Optimise route");
                setNotice(tr("im.opt_failed", "The route could not be optimised just now. Nothing was changed."));
            });
    }

    /** Show the map over the whole window, or put it back. */
    function toggleFullscreen() {
        var wrap = document.querySelector(".map-wrap");
        var button = document.getElementById("mapFullscreenButton");
        if (!wrap || !map) return;
        var on = wrap.classList.toggle("fullscreen");
        if (button) button.textContent = on ? tr("iv.fullscreen_close", "Close full screen") : tr("iv.fullscreen", "Full screen");
        document.body.style.overflow = on ? "hidden" : "";
        map.invalidateSize();
        fitTo(shown);
    }

    /** The date of a day of the trip as "2026-11-14", or null when the trip has no start date. */
    function dayDate(day) {
        if (!START_DATE) return null;
        var date = new Date(START_DATE + "T00:00:00");
        if (isNaN(date.getTime())) return null;
        date.setDate(date.getDate() + day - 1);
        return isoDate(date);
    }

    function isoDate(date) {
        return date.getFullYear() + "-" + ("0" + (date.getMonth() + 1)).slice(-2) + "-" + ("0" + date.getDate()).slice(-2);
    }

    /** What the live layer has to say, and the label of its button. */
    function setBusStatus(text) {
        var status = document.getElementById("liveBusStatus");
        if (status) status.textContent = text || "";
        var button = document.getElementById("busesButton");
        if (button && buses) button.textContent = buses.isOn() ? tr("iv.buses_hide", "Hide live buses") : tr("iv.buses", "Live buses");
    }

    /**
     * The live buses belong to public transport: they come on with it, and go off again with
     * another way of travelling unless the traveller switched them on.
     */
    function followTransport() {
        if (!buses) return;
        if (transport === "public_transport" && !buses.isOn()) {
            busesFollowMode = true;
            buses.start();
        } else if (transport !== "public_transport" && busesFollowMode) {
            busesFollowMode = false;
            buses.stop();
        }
    }

    function toggleBuses() {
        if (!buses) return;
        busesFollowMode = false;   // from here on it is the traveller's choice
        if (buses.isOn()) buses.stop(); else buses.start();
    }

    function clearConditions() {
        conditions = null;
        conditionMarkers = [];
        if (conditionLayer) conditionLayer.clearLayers();
        var panel = document.getElementById("roadConditions");
        if (panel) {
            panel.classList.remove("visible");
            panel.innerHTML = "";
        }
    }

    /**
     * What is happening on the day's route right now: traffic, closed roads, weather warnings,
     * fires. Asked for when the traveller presses the button, and by itself on the day of travel.
     */
    function loadConditions() {
        var panel = document.getElementById("roadConditions");
        var button = document.getElementById("conditionsButton");
        if (!panel || !map) return;
        var day = activeDay;
        var mode = transport;
        panel.classList.add("visible");
        panel.innerHTML = "<span class=\"spinner\"></span> " + esc(tr("im.rc_loading", "Checking traffic, warnings and fires..."));
        if (button) button.disabled = true;
        fetch("../api/road_conditions.php?itinerary_id=" + encodeURIComponent(ITINERARY_ID) + "&day=" + encodeURIComponent(day) +
                "&mode=" + encodeURIComponent(mode), { credentials: "same-origin" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (day !== activeDay || mode !== transport) return;
                if (!data || data.status !== "ok") throw new Error("unavailable");
                conditions = data;
                showConditions();
            })
            .catch(function () {
                if (day === activeDay) panel.innerHTML = "<div class=\"ts-warning\">" + esc(tr("im.rc_failed", "Road conditions could not be loaded just now.")) + "</div>";
            })
            .then(function () { if (button) button.disabled = false; });
    }

    function showConditions() {
        var panel = document.getElementById("roadConditions");
        if (!panel || !conditions) return;
        conditionLayer.clearLayers();
        conditionMarkers = [];
        var items = conditions.items || [];
        var checked = new Date(conditions.checked_at);
        var html = "<strong>" + esc(items.length ? tr("im.rc_now", "On the road now") : tr("im.rc_none", "Nothing is reported on this day's route right now")) + "</strong>" +
            " <span style=\"color:#64748b;font-size:11.5px;\">" +
            esc(tr("im.rc_checked", "checked {time}", { time: isNaN(checked.getTime()) ? "" : checked.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) })) +
            (conditions.is_today ? "" : " &middot; " + esc(tr("im.rc_not_forecast", "this is the situation now, not a forecast for the day of travel"))) + "</span>";

        items.forEach(function (item, index) {
            var located = item.lat !== null && item.lng !== null;
            if (located) {
                if (item.line && item.line.length >= 2) {
                    conditionLayer.addLayer(L.polyline(item.line, { color: item.level >= 3 ? "#dc2626" : "#ea580c", weight: 8, opacity: 0.75 }));
                }
                var marker = L.marker([item.lat, item.lng], {
                    icon: L.divIcon({
                        className: "iv-alert level-" + item.level,
                        html: "<span><b>" + esc(CONDITION_MARK[item.kind] || "!") + "</b></span>",
                        iconSize: [24, 24],
                        iconAnchor: [12, 12],
                        popupAnchor: [0, -12]
                    }),
                    title: item.title,
                    zIndexOffset: 500
                }).bindPopup("<div class=\"iv-popup\"><strong>" + esc(item.title) + "</strong><div>" + esc(item.detail) + "</div></div>");
                conditionLayer.addLayer(marker);
                conditionMarkers[index] = marker;
            }
            var where = item.kind === "weather_warning" ? (item.on_route ? tr("im.rc_area", "your area") : tr("im.rc_state", "in the state"))
                : (item.on_route ? tr("im.rc_route", "on your route") : tr("im.rc_away", "{km} away", { km: kmText(item.distance_km) }));
            html += "<div class=\"rc-item level-" + item.level + "\"" + (located ? " data-rc-index=\"" + index + "\"" : "") + ">" +
                "<span class=\"rc-badge\">" + esc(where) + "</span>" +
                "<div><strong>" + esc(item.title) + "</strong><div class=\"rc-detail\">" + esc(item.detail) + "</div></div></div>";
        });

        var notes = [];
        var credits = [];
        Object.keys(conditions.sources || {}).forEach(function (name) {
            var source = conditions.sources[name];
            if (source.note) notes.push(source.note);
            if (source.credit) credits.push(source.credit);
        });
        html += "<div class=\"rc-foot\">" + (notes.length ? esc(notes.join(" ")) + "<br>" : "") +
            (credits.length ? esc(credits.join(" · ")) + ". " : "") +
            esc(tr("im.rc_flood", "River levels and floods: {link}, the official site.")).replace("{link}",
                "<a href=\"https://publicinfobanjir.water.gov.my/\" target=\"_blank\" rel=\"noopener\">Public InfoBanjir</a>") + "</div>";
        panel.innerHTML = html;
    }

    function focusCondition(index) {
        var marker = conditionMarkers[index];
        if (!map || !marker) return;
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 15));
        marker.openPopup();
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
        conditionLayer = L.layerGroup().addTo(map);
        L.control.scale({ imperial: false }).addTo(map);
        if (window.LiveBuses) buses = LiveBuses.create(map, { endpoint: "../api/live_buses.php", onStatus: setBusStatus });
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
            } else if (target.closest("#optimiseButton")) {
                optimiseRoute();
            } else if (target.closest("#conditionsButton")) {
                loadConditions();
            } else if (target.closest("#busesButton")) {
                toggleBuses();
            } else if (target.closest("#mapFullscreenButton")) {
                toggleFullscreen();
            } else if ((hit = target.closest("[data-rc-index]"))) {
                focusCondition(Number(hit.dataset.rcIndex));
            } else if (target.closest("#locateButton")) {
                locateMe();
            } else if ((hit = target.closest("tr[data-item-id]")) && !target.closest("a, button")) {
                focusStop(hit.dataset.itemId);
            }
        });

        document.addEventListener("keydown", function (event) {
            var wrap = document.querySelector(".map-wrap");
            if (event.key === "Escape" && wrap && wrap.classList.contains("fullscreen")) toggleFullscreen();
        });

        showDay(1);
        loadWeather();
        followTransport();

        // What the last "Optimise route" did, said once after the page was loaded again.
        try {
            var notice = window.sessionStorage.getItem("iv-notice");
            if (notice) {
                window.sessionStorage.removeItem("iv-notice");
                setNotice(notice);
            }
        } catch (e) { /* no session storage: no message */ }
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();
