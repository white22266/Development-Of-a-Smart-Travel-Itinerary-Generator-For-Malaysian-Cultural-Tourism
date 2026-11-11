// assets/place_picker.js
// A small map for choosing where a place is: search by name or address, click the map, or drag
// the pin. It uses Leaflet with OpenStreetMap map tiles and this app's own search endpoint
// (api/geo_search.php), so no API key is needed.
//
//   PlacePicker.attach({
//       map: "pinMap",                  // id of the element that becomes the map
//       search: "addressInput",         // id of the text field to search from (optional)
//       lat: "latInput", lng: "lngInput",
//       searchUrl: "../api/geo_search.php",
//       onPick: function (place) {}     // {lat, lng, address, stateIso, district} and, after a
//   });                                 // search, also {name, label}
//
//   PlacePicker.attachSearch(inputElement, searchUrl, onChoose)   // the search box on its own

(function () {
    "use strict";

    var MALAYSIA = { center: [4.2, 108.0], zoom: 5 };
    var TILES = "https://tile.openstreetmap.org/{z}/{x}/{y}.png";
    var ATTRIBUTION = "&copy; <a href=\"https://www.openstreetmap.org/copyright\" target=\"_blank\" rel=\"noopener\">OpenStreetMap</a> contributors";

    function addStyles() {
        if (document.getElementById("place-picker-styles")) return;
        var style = document.createElement("style");
        style.id = "place-picker-styles";
        style.textContent =
            ".place-picker-results{display:none;position:absolute;left:0;right:0;z-index:1200;margin-top:4px;background:#fff;" +
            "border:1px solid rgba(15,23,42,.14);border-radius:12px;box-shadow:0 12px 30px rgba(2,6,23,.14);max-height:260px;overflow:auto}" +
            ".place-picker-result{display:block;width:100%;text-align:left;padding:9px 12px;border:0;border-bottom:1px solid rgba(15,23,42,.06);" +
            "background:#fff;font:inherit;cursor:pointer}" +
            ".place-picker-result:hover,.place-picker-result:focus{background:#f1f5f9;outline:none}" +
            ".place-picker-result strong{display:block;font-size:13px}" +
            ".place-picker-result span{display:block;font-size:12px;color:#64748b}" +
            ".place-picker-empty{padding:10px 12px;font-size:12px;color:#64748b}";
        document.head.appendChild(style);
    }

    function attachSearch(input, searchUrl, onChoose) {
        addStyles();
        var holder = input.parentNode;
        if (getComputedStyle(holder).position === "static") holder.style.position = "relative";
        var list = document.createElement("div");
        list.className = "place-picker-results";
        list.setAttribute("role", "listbox");
        input.setAttribute("autocomplete", "off");
        input.insertAdjacentElement("afterend", list);

        var timer = null;
        var lastQuery = "";
        var request = 0;

        function close() {
            list.style.display = "none";
            list.innerHTML = "";
        }

        function show(results) {
            list.innerHTML = "";
            if (!results.length) {
                var empty = document.createElement("div");
                empty.className = "place-picker-empty";
                empty.textContent = "No place found for that. Try the town or a nearby landmark.";
                list.appendChild(empty);
            }
            results.forEach(function (result) {
                var row = document.createElement("button");
                row.type = "button";
                row.className = "place-picker-result";
                var name = document.createElement("strong");
                name.textContent = result.name;
                var detail = document.createElement("span");
                detail.textContent = result.detail;
                row.appendChild(name);
                row.appendChild(detail);
                row.addEventListener("click", function () {
                    close();
                    lastQuery = result.label;
                    onChoose(result);
                });
                list.appendChild(row);
            });
            list.style.display = "block";
        }

        function search() {
            var query = input.value.trim();
            if (query.length < 3) {
                lastQuery = "";
                close();
                return;
            }
            if (query === lastQuery) return;
            lastQuery = query;
            var mine = ++request;
            fetch(searchUrl + "?q=" + encodeURIComponent(query), { credentials: "same-origin" })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (mine === request) show((data && data.results) || []);   // ignore answers to older searches
                })
                .catch(close);
        }

        input.addEventListener("input", function () {
            clearTimeout(timer);
            timer = setTimeout(search, 400);
        });
        input.addEventListener("keydown", function (event) {
            if (event.key === "Escape") close();
            if (event.key === "Enter" && list.style.display === "block") {
                event.preventDefault();   // choose the first result instead of sending the form
                var first = list.querySelector(".place-picker-result");
                if (first) first.click();
            }
        });
        document.addEventListener("click", function (event) {
            if (event.target !== input && !list.contains(event.target)) close();
        });
    }

    function attach(options) {
        var mapEl = document.getElementById(options.map);
        var searchEl = options.search ? document.getElementById(options.search) : null;
        var latEl = document.getElementById(options.lat);
        var lngEl = document.getElementById(options.lng);
        if (!mapEl || !window.L) return null;

        var map = L.map(mapEl).setView(MALAYSIA.center, MALAYSIA.zoom);
        L.tileLayer(TILES, { maxZoom: 19, attribution: ATTRIBUTION }).addTo(map);
        var marker = null;

        // Ask the server what is at the pin, then tell the page.
        function describe(lat, lng, known) {
            var place = Object.assign({ lat: lat, lng: lng, address: "", stateIso: "", district: "" }, known || {});
            fetch(options.searchUrl + "?lat=" + encodeURIComponent(lat) + "&lng=" + encodeURIComponent(lng), { credentials: "same-origin" })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    place.address = data.address || "";
                    place.stateIso = data.state_iso || "";
                    place.district = data.district || "";
                })
                .catch(function () {})
                .then(function () {
                    if (options.onPick) options.onPick(place);
                });
        }

        function setPin(lat, lng, zoom) {
            lat = Number(lat);
            lng = Number(lng);
            if (!isFinite(lat) || !isFinite(lng)) return;
            if (latEl) latEl.value = lat.toFixed(7);
            if (lngEl) lngEl.value = lng.toFixed(7);
            if (!marker) {
                marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                marker.on("dragend", function () {
                    var position = marker.getLatLng();
                    setPin(position.lat, position.lng);
                    describe(position.lat, position.lng);
                });
            } else {
                marker.setLatLng([lat, lng]);
            }
            map.setView([lat, lng], zoom || Math.max(map.getZoom(), 15));
        }

        map.on("click", function (event) {
            setPin(event.latlng.lat, event.latlng.lng);
            describe(event.latlng.lat, event.latlng.lng);
        });

        // Coordinates typed by hand move the pin.
        [latEl, lngEl].forEach(function (el) {
            if (!el) return;
            el.addEventListener("change", function () {
                var lat = parseFloat(latEl.value);
                var lng = parseFloat(lngEl.value);
                if (isFinite(lat) && isFinite(lng)) setPin(lat, lng);
            });
        });

        // Start at the coordinates already in the form (an existing place being edited).
        if (latEl && lngEl && isFinite(parseFloat(latEl.value)) && isFinite(parseFloat(lngEl.value))) {
            setPin(parseFloat(latEl.value), parseFloat(lngEl.value), 15);
        }

        if (searchEl) {
            attachSearch(searchEl, options.searchUrl, function (result) {
                searchEl.value = result.label;
                setPin(result.lat, result.lng, 16);
                describe(result.lat, result.lng, { name: result.name, label: result.label });
            });
        }

        // A map created while its box was hidden or still being laid out has to measure itself again.
        setTimeout(function () { map.invalidateSize(); }, 250);

        return {
            map: map,
            setPin: setPin,
            refresh: function () { map.invalidateSize(); }
        };
    }

    window.PlacePicker = { attach: attach, attachSearch: attachSearch };
})();
