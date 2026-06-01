// assets/itinerary_review_hotel_loader.js
// Loads hotel suggestions near the final itinerary stop and renders selectable hotel cards.
(function () {
    function escHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function hotelCardId(hotel, index) {
        var raw = hotel.google_place_id || hotel.place_id || hotel.name || ('hotel_' + index);
        return String(raw).replace(/[^a-zA-Z0-9_-]/g, '_');
    }

    function scoreMeta(hotel) {
        var score = Number(hotel.score || 0);
        var pct = Math.round(Math.max(0, Math.min(1, score)) * 100);
        if (!pct && Number(hotel.rating || 0) > 0) {
            pct = Math.round(Math.max(0, Math.min(5, Number(hotel.rating))) / 5 * 100);
        }

        if (pct >= 85) return { pct: pct, label: 'Excellent', color: 'success', hex: '#22c55e' };
        if (pct >= 70) return { pct: pct, label: 'Good', color: 'primary', hex: '#3b82f6' };
        if (pct >= 50) return { pct: pct, label: 'Fair', color: 'warning', hex: '#f59e0b' };
        return { pct: pct || 50, label: 'Low', color: 'danger', hex: '#ef4444' };
    }

    function ensureHotelSection() {
        var target = null;
        document.querySelectorAll('.card').forEach(function (card) {
            if (target) return;
            var heading = card.querySelector('h3');
            if (heading && heading.textContent.trim().toLowerCase() === 'select your hotel') {
                target = card;
            }
        });

        if (target) return target;

        var confirmBar = document.querySelector('.confirm-bar');
        if (!confirmBar || !confirmBar.parentNode) return null;

        target = document.createElement('div');
        target.className = 'card';
        target.style.padding = '18px';
        target.style.marginBottom = '20px';
        confirmBar.parentNode.insertBefore(target, confirmBar);
        return target;
    }

    function renderLoading(section) {
        section.innerHTML =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:14px;">Hotel suggestions are generated near the final stop of your itinerary using Google Places. Price is a planning estimate; confirm only after checking the real booking price.</p>' +
            '<div class="hotel-empty-state"><strong>Loading nearby hotels...</strong>Please wait while the system searches accommodation near your final itinerary stop.</div>';
    }

    function renderEmpty(section, data) {
        var lastStop = data && data.last_stop ? data.last_stop : null;
        var stopText = lastStop && lastStop.title
            ? ' Final stop: <strong>' + escHtml(lastStop.title) + '</strong>.'
            : '';

        section.innerHTML =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:14px;">Hotel suggestions are generated near the final stop of your itinerary using Google Places. Price is a planning estimate; confirm only after checking the real booking price.</p>' +
            '<div class="hotel-empty-state">' +
            '<strong>No nearby hotel suggestions are available right now.</strong>' +
            escHtml((data && data.message) || 'Google Places did not return hotel results for this location.') + stopText +
            ' You can still confirm the itinerary without selecting a hotel.' +
            '</div>';
    }

    function renderHotels(section, data) {
        var hotels = Array.isArray(data.hotels) ? data.hotels : [];
        if (!hotels.length) {
            renderEmpty(section, data);
            return;
        }

        var lastStop = data.last_stop || {};
        var lastStopText = lastStop.title
            ? 'Based on final stop: <strong>' + escHtml(lastStop.title) + '</strong>' + (lastStop.district || lastStop.state ? ' (' + escHtml([lastStop.district, lastStop.state].filter(Boolean).join(', ')) + ')' : '')
            : 'Based on the final stop of your itinerary';

        var html =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:14px;">Hotel suggestions are generated near the final stop of your itinerary using Google Places. Price is a planning estimate; confirm only after checking the real booking price.</p>' +
            '<div style="font-weight:800; font-size:13px; margin-bottom:8px; color:#475569;">' + lastStopText + '</div>' +
            '<div class="hotel-grid">';

        hotels.forEach(function (hotel, index) {
            var id = hotelCardId(hotel, index);
            var meta = scoreMeta(hotel);
            var price = Number(hotel.price_per_night || 0);
            var rating = Number(hotel.rating || 0);
            var distance = hotel.distance_km == null ? null : Number(hotel.distance_km);
            html +=
                '<div class="hotel-card-review" id="hotel-card-' + escHtml(id) + '" data-hotel-card-id="' + escHtml(id) + '" data-place-id="' + escHtml(hotel.google_place_id || '') + '" data-hotel-name="' + escHtml(hotel.name || '') + '">' +
                '<span class="hotel-select-badge">Selected</span>' +
                '<div class="hotel-name-rv">' + escHtml(hotel.name || 'Unnamed hotel') + '</div>' +
                '<div class="hotel-meta-rv">' + escHtml(hotel.address || [hotel.district, hotel.state].filter(Boolean).join(', ') || 'Address unavailable') +
                (rating > 0 ? ' &middot; Google rating: ' + rating.toFixed(1) + '/5' : '') +
                (distance !== null && !isNaN(distance) ? ' &middot; ' + distance.toFixed(1) + ' km from final stop' : '') +
                '</div>' +
                '<div class="hotel-price-rv">Estimated RM ' + (price > 0 ? Math.round(price).toLocaleString() : 'N/A') + ' / night</div>' +
                '<div style="margin-top:8px;">' +
                '<div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">' +
                '<span class="match-badge badge-' + meta.color + '">' + meta.label + '</span>' +
                '<span style="font-size:12px; font-weight:900; color:' + meta.hex + ';">' + meta.pct + '%</span>' +
                '</div>' +
                '<div class="match-bar-track" style="height:5px;"><div class="match-bar-fill" style="width:' + meta.pct + '%; background:' + meta.hex + ';"></div></div>' +
                '</div>' +
                '</div>';
        });

        html += '</div>';
        section.innerHTML = html;

        section.querySelectorAll('.hotel-card-review').forEach(function (card) {
            card.addEventListener('click', function () {
                if (typeof window.selectHotel !== 'function') return;
                window.selectHotel(card.dataset.hotelCardId, card.dataset.placeId || '', card.dataset.hotelName || '');
            });
        });
    }

    async function loadHotels() {
        if (window.__itineraryReviewHotelsLoaded) return;
        window.__itineraryReviewHotelsLoaded = true;

        if (typeof window.ITINERARY_ID === 'undefined') return;
        var section = ensureHotelSection();
        if (!section) return;

        renderLoading(section);

        try {
            var response = await fetch('review_hotels.php?itinerary_id=' + encodeURIComponent(window.ITINERARY_ID), {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            var text = await response.text();
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                data = { status: 'error', message: 'Invalid hotel response from server.' };
            }

            if (data.status === 'success') {
                renderHotels(section, data);
            } else {
                renderEmpty(section, data);
            }
        } catch (e) {
            renderEmpty(section, { message: 'Network error while loading hotel suggestions.' });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadHotels);
    } else {
        loadHotels();
    }
    window.addEventListener('load', loadHotels);
})();
