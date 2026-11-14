// assets/live_buses.js
// A layer for a Leaflet map that shows where the buses and trains are right now: the vehicles
// in the part of the map on show, renewed every half minute. Pressing a vehicle says which
// route it is on and can draw that route's line.
//
// The positions come from api/live_buses.php (open data of the operators through
// data.gov.my; see services/BusTrackingService.php).
//
//   var buses = LiveBuses.create(map, { endpoint: "../api/live_buses.php", onStatus: function (text) { ... } });
//   buses.start();  buses.stop();  buses.isOn();
//   buses.pickOut(["782", "KJL"]);   // make the vehicles of these routes stand out

var LiveBuses = (function () {
    "use strict";

    var REFRESH_MS = 30000;
    var CSS = ".bus-pin{position:relative}" +
        ".bus-pin b{display:block;min-width:26px;padding:1px 5px;border-radius:9px;border:2px solid #fff;" +
            "box-shadow:0 1px 4px rgba(2,6,23,.45);color:#fff;font:800 10.5px system-ui,sans-serif;text-align:center;white-space:nowrap}" +
        ".bus-pin i{position:absolute;left:50%;top:50%;width:0;height:0;margin:-19px 0 0 -5px;border-left:5px solid transparent;" +
            "border-right:5px solid transparent;border-bottom:8px solid #0f172a;transform-origin:5px 19px}" +
        ".bus-pin.picked b{font-size:12px;padding:2px 7px;border-color:#0f172a;box-shadow:0 0 0 3px #fde047,0 1px 5px rgba(2,6,23,.5)}" +
        ".bus-pin.old b{opacity:.6}" +
        ".bus-popup{font-size:12px;line-height:1.45;max-width:240px}.bus-popup strong{display:block;font-size:13px}" +
        ".bus-popup .muted{color:#64748b}.bus-popup button{margin-top:6px;padding:4px 9px;border-radius:7px;border:1px solid #cbd5e1;" +
            "background:#fff;font:700 11.5px system-ui,sans-serif;cursor:pointer}";

    function esc(value) {
        return String(value === null || value === undefined ? "" : value)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    /** Only a colour the server sent as #rrggbb goes into a style; anything else gets the default. */
    function colour(vehicle) {
        if (/^#[0-9a-f]{6}$/i.test(vehicle.color || "")) return vehicle.color;
        return vehicle.kind === "train" ? "#7c3aed" : "#1d4ed8";
    }

    // Texts of the page's language (LIVE_BUSES_T, set by the page); the English text is the default.
    var TXT = (typeof LIVE_BUSES_T !== "undefined" && LIVE_BUSES_T) || {};

    function tr(key, english, vars) {
        var text = TXT[key] || english;
        if (vars) Object.keys(vars).forEach(function (name) { text = text.split("{" + name + "}").join(String(vars[name])); });
        return text;
    }

    function ago(seconds) {
        return seconds < 90
            ? tr("lb.ago_s", "{n} s ago", { n: Math.round(seconds) })
            : tr("lb.ago_min", "{n} min ago", { n: Math.round(seconds / 60) });
    }

    /** The fixed sentences of services/BusTrackingService.php (English) in the page's language. */
    function serverNote(note) {
        var known = {
            "Zoom in to see the buses and trains.": ["lb.n_zoom", note],
            "No operator publishes live positions for this part of Malaysia yet.": ["lb.n_none_here", note],
            "The live positions could not be loaded just now.": ["lb.failed", note],
            "No bus or train is reporting its position in this part of the map right now.": ["lb.n_empty", note],
            "No part of the map was given.": ["lb.n_nomap", note]
        };
        return known[note] ? tr(known[note][0], note) : note;
    }

    function create(map, options) {
        options = options || {};
        var layer = L.layerGroup();
        var lineLayer = L.layerGroup();
        var markers = {};        // "feed|vehicle id" -> marker
        var picked = {};         // route numbers to make stand out, in upper case
        var on = false;
        var timer = null;
        var moveTimer = null;
        var loadNo = 0;

        if (!document.getElementById("live-buses-style")) {
            var style = document.createElement("style");
            style.id = "live-buses-style";
            style.textContent = CSS;
            document.head.appendChild(style);
        }

        function say(text) {
            if (options.onStatus) options.onStatus(text);
        }

        function isPicked(vehicle) {
            return !!picked[String(vehicle.route || "").toUpperCase()];
        }

        function icon(vehicle) {
            var label = String(vehicle.route || (vehicle.kind === "train" ? "KTM" : "bus")).slice(0, 7);
            var arrow = vehicle.bearing === null || vehicle.bearing === undefined ? ""
                : "<i style=\"transform:rotate(" + Number(vehicle.bearing) + "deg)\"></i>";
            return L.divIcon({
                className: "bus-pin" + (isPicked(vehicle) ? " picked" : "") + (vehicle.age_s > 120 ? " old" : ""),
                html: arrow + "<b style=\"background:" + colour(vehicle) + "\">" + esc(label) + "</b>",
                iconSize: [34, 18],
                iconAnchor: [17, 9],
                popupAnchor: [0, -10]
            });
        }

        function popup(vehicle) {
            var box = document.createElement("div");
            box.className = "bus-popup";
            box.innerHTML = "<strong>" + esc(vehicle.operator) + (vehicle.route ? " " + esc(vehicle.route) : "") + "</strong>" +
                (vehicle.name ? "<div>" + esc(vehicle.name) + "</div>" : "") +
                (vehicle.towards ? "<div>" + esc(tr("lb.towards", "towards {place}", { place: vehicle.towards })) + "</div>" : "") +
                "<div class=\"muted\">" + (vehicle.speed_kmh !== null && vehicle.speed_kmh !== undefined ? esc(vehicle.speed_kmh) + " km/h &middot; " : "") +
                    esc(tr("lb.reported", "reported {ago}", { ago: ago(vehicle.age_s) })) + " &middot; " + esc(vehicle.id) + "</div>";
            var button = document.createElement("button");
            button.type = "button";
            button.textContent = tr("lb.show_route", "Show this route");
            button.addEventListener("click", function () { showLine(vehicle, button); });
            box.appendChild(button);
            return box;
        }

        /** Draw the line of a vehicle's route. One line at a time. */
        function showLine(vehicle, button) {
            button.disabled = true;
            fetch(options.endpoint + "?feed=" + encodeURIComponent(vehicle.feed) + "&trip=" + encodeURIComponent(vehicle.trip || "") +
                    "&route=" + encodeURIComponent(vehicle.route_id || ""), { credentials: "same-origin" })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || data.status !== "ok" || !data.points || data.points.length < 2) throw new Error("no line");
                    lineLayer.clearLayers();
                    lineLayer.addLayer(L.polyline(data.points, { color: "#fff", weight: 8, opacity: 0.85, interactive: false }));
                    lineLayer.addLayer(L.polyline(data.points, { color: colour({ color: data.color, kind: vehicle.kind }), weight: 4, opacity: 0.95 })
                        .bindTooltip((data.route ? data.route + " " : "") + (data.name || ""), { sticky: true }));
                    button.textContent = tr("lb.route_shown", "Route shown");
                })
                .catch(function () {
                    button.textContent = tr("lb.no_line", "No line is published for this route");
                });
        }

        /** Ask for the vehicles in view and move the markers to where they are now. */
        function load() {
            if (!on) return;
            var mine = ++loadNo;
            var view = map.getBounds();
            var box = [view.getWest(), view.getSouth(), view.getEast(), view.getNorth()].map(function (n) { return n.toFixed(4); }).join(",");
            fetch(options.endpoint + "?bbox=" + encodeURIComponent(box), { credentials: "same-origin" })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!on || mine !== loadNo) return;
                    if (!data || data.status !== "ok") throw new Error("unavailable");
                    var seen = {};
                    (data.vehicles || []).forEach(function (vehicle) {
                        var key = vehicle.feed + "|" + vehicle.id;
                        seen[key] = true;
                        var marker = markers[key];
                        if (marker) {
                            marker.setLatLng([vehicle.lat, vehicle.lng]);
                            marker.setIcon(icon(vehicle));
                            if (!marker.isPopupOpen()) marker.setPopupContent(popup(vehicle));
                        } else {
                            marker = L.marker([vehicle.lat, vehicle.lng], { icon: icon(vehicle), title: vehicle.operator + " " + vehicle.route, zIndexOffset: 300 })
                                .bindPopup(popup(vehicle));
                            markers[key] = marker;
                            layer.addLayer(marker);
                        }
                        marker.setZIndexOffset(isPicked(vehicle) ? 600 : 300);
                    });
                    Object.keys(markers).forEach(function (key) {
                        if (!seen[key]) {
                            layer.removeLayer(markers[key]);
                            delete markers[key];
                        }
                    });
                    var count = (data.vehicles || []).length;
                    say(count
                        ? tr(count === 1 ? "lb.in_view_one" : "lb.in_view_many", count === 1 ? "{n} vehicle in view ({operators}), positions renewed every half minute." : "{n} vehicles in view ({operators}), positions renewed every half minute.",
                            { n: count, operators: (data.operators || []).join(", ") }) + " " + data.credit + "."
                        : (data.note ? serverNote(data.note) : tr("lb.n_empty", "No vehicles in view.")));
                })
                .catch(function () {
                    if (on && mine === loadNo) say(tr("lb.failed", "The live positions could not be loaded just now."));
                });
        }

        function onMove() {
            window.clearTimeout(moveTimer);
            moveTimer = window.setTimeout(load, 700);
        }

        function start() {
            if (on) return;
            on = true;
            layer.addTo(map);
            lineLayer.addTo(map);
            map.on("moveend", onMove);
            timer = window.setInterval(function () { if (!document.hidden) load(); }, REFRESH_MS);
            say(tr("lb.looking", "Looking for buses and trains..."));
            load();
        }

        function stop() {
            if (!on) return;
            on = false;
            loadNo++;
            window.clearInterval(timer);
            window.clearTimeout(moveTimer);
            map.off("moveend", onMove);
            layer.clearLayers();
            lineLayer.clearLayers();
            map.removeLayer(layer);
            map.removeLayer(lineLayer);
            markers = {};
            say("");
        }

        /** Make the vehicles of these route numbers stand out, for example the lines of the traveller's own connection. */
        function pickOut(routes) {
            picked = {};
            (routes || []).forEach(function (route) {
                if (route) picked[String(route).toUpperCase()] = true;
            });
            if (on) load();
        }

        return { start: start, stop: stop, isOn: function () { return on; }, pickOut: pickOut };
    }

    return { create: create };
})();
