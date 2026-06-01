// assets/itinerary_review_hotel_loader.js
// Loads hotel suggestions near the current final kept/confirmed itinerary stop and renders selectable hotel cards.
// Google Places is used for hotel discovery. SerpAPI is used only when the user clicks pricing lookup.
(function () {
    var lastHotelQueryKey = '';
    var hotelLoadTimer = null;

    function getItineraryId() {
        if (typeof window.ITINERARY_ID !== 'undefined') return window.ITINERARY_ID;
        try {
            var params = new URLSearchParams(window.location.search);
            return params.get('itinerary_id') || '';
        } catch (e) {
            return '';
        }
    }

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

    function hasSerpApiPrices(hotels) {
        return Array.isArray(hotels) && hotels.some(function (hotel) {
            return hotel && hotel.price_source === 'serpapi_google_maps_price';
        });
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

    function renderLoading(section, finalStop, lookupPricing) {
        var finalText = finalStop && finalStop.title
            ? '<div style="font-weight:800; font-size:13px; margin-bottom:8px; color:#475569;">Based on current final kept stop: <strong>' + escHtml(finalStop.title) + '</strong></div>'
            : '';
        var loadingTitle = lookupPricing ? 'Checking hotel prices...' : 'Loading nearby hotels...';
        var loadingText = lookupPricing
            ? 'SerpAPI is used only for this pricing lookup. Successful prices will be saved to the database cache.'
            : 'Please wait while the system searches accommodation near your current final stop.';

        section.innerHTML =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:14px;">Hotel suggestions are generated near the current final kept stop using Google Places. SerpAPI is only used when you click the pricing lookup button.</p>' +
            finalText +
            '<div class="hotel-empty-state"><strong>' + loadingTitle + '</strong>' + loadingText + '</div>';
    }

    function renderEmpty(section, data) {
        var lastStop = data && data.last_stop ? data.last_stop : null;
        var stopText = lastStop && lastStop.title
            ? ' Final stop: <strong>' + escHtml(lastStop.title) + '</strong>.'
            : '';

        section.innerHTML =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:14px;">Hotel suggestions are generated near the current final kept stop using Google Places. SerpAPI is only used for pricing lookup.</p>' +
            '<div class="hotel-empty-state">' +
            '<strong>No nearby hotel suggestions are available right now.</strong>' +
            escHtml((data && data.message) || 'Google Places did not return hotel results for this location.') + stopText +
            '</div>';
    }

    function renderNeedFinalStop(section) {
        section.innerHTML =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:14px;">Hotel suggestions are generated near the current final kept stop of your itinerary using Google Places.</p>' +
            '<div class="hotel-empty-state"><strong>No final kept stop selected.</strong>Please keep at least one itinerary stop before choosing a hotel.</div>';
    }

    function clearSelectedHotel() {
        if (typeof window.selectedHotelPlaceId !== 'undefined') window.selectedHotelPlaceId = '';
        if (typeof window.selectedHotelName !== 'undefined') window.selectedHotelName = '';
        var statHotel = document.getElementById('stat-hotel');
        if (statHotel) statHotel.textContent = 'None';
        document.querySelectorAll('.hotel-card-review').forEach(function (c) { c.classList.remove('selected'); });
    }

    function priceSourceLabel(hotel) {
        if ((hotel.price_source || '') === 'serpapi_google_maps_price') {
            return hotel.price_label ? 'SerpAPI price: ' + hotel.price_label : 'SerpAPI cached price';
        }
        return 'Planning estimate';
    }

    function renderHotels(section, data) {
        var hotels = Array.isArray(data.hotels) ? data.hotels : [];
        if (!hotels.length) {
            renderEmpty(section, data);
            clearSelectedHotel();
            return;
        }

        var lastStop = data.last_stop || {};
        var livePricesLoaded = hasSerpApiPrices(hotels);
        var pricingNote = livePricesLoaded
            ? 'Some prices are loaded from cached SerpAPI pricing data.'
            : 'Prices shown now are planning estimates. Click the button below only when you want to use SerpAPI quota to check prices.';

        var lastStopText = lastStop.title
            ? 'Based on current final kept stop: <strong>' + escHtml(lastStop.title) + '</strong>' + (lastStop.district || lastStop.state ? ' (' + escHtml([lastStop.district, lastStop.state].filter(Boolean).join(', ')) + ')' : '')
            : 'Based on the current final kept stop of your itinerary';

        var html =
            '<h3 style="margin-bottom:6px;">Select Your Hotel</h3>' +
            '<p class="meta" style="margin-top:0; margin-bottom:10px;">Hotel suggestions are generated near the current final kept stop using Google Places. SerpAPI is used only for pricing lookup and successful pricing is saved to the database cache.</p>' +
            '<div style="font-weight:800; font-size:13px; margin-bottom:8px; color:#475569;">' + lastStopText + '</div>' +
            '<div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px;">' +
            '<button type="button" id="btn-lookup-hotel-prices" class="btn btn-secondary" style="padding:9px 12px; border-radius:10px; font-size:12px; font-weight:900;">Lookup live prices with SerpAPI</button>' +
            '<span class="meta" style="font-size:12px;">' + escHtml(pricingNote) + '</span>' +
            '</div>' +
            '<div class="hotel-grid">';

        hotels.forEach(function (hotel, index) {
            var id = hotelCardId(hotel, index);
            var meta = scoreMeta(hotel);
            var price = Number(hotel.price_per_night || 0);
            var rating = Number(hotel.rating || 0);
            var distance = hotel.distance_km == null ? null : Number(hotel.distance_km);
            var sourceLabel = priceSourceLabel(hotel);
            var sourceClass = hotel.price_source === 'serpapi_google_maps_price' ? 'badge-success' : 'badge-warning';

            html +=
                '<div class="hotel-card-review" id="hotel-card-' + escHtml(id) + '" data-hotel-card-id="' + escHtml(id) + '" data-place-id="' + escHtml(hotel.google_place_id || '') + '" data-hotel-name="' + escHtml(hotel.name || '') + '">' +
                '<span class="hotel-select-badge">Selected</span>' +
                '<div class="hotel-name-rv">' + escHtml(hotel.name || 'Unnamed hotel') + '</div>' +
                '<div class="hotel-meta-rv">' + escHtml(hotel.address || [hotel.district, hotel.state].filter(Boolean).join(', ') || 'Address unavailable') +
                (rating > 0 ? ' &middot; Google rating: ' + rating.toFixed(1) + '/5' : '') +
                (distance !== null && !isNaN(distance) ? ' &middot; ' + distance.toFixed(1) + ' km from final stop' : '') +
                '</div>' +
                '<div class="hotel-price-rv">RM ' + (price > 0 ? Math.round(price).toLocaleString() : 'N/A') + ' / night</div>' +
                '<div style="margin-top:5px;"><span class="match-badge ' + sourceClass + '">' + escHtml(sourceLabel) + '</span></div>' +
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
        clearSelectedHotel();

        section.querySelectorAll('.hotel-card-review').forEach(function (card) {
            card.addEventListener('click', function () {
                if (typeof window.selectHotel !== 'function') return;
                window.selectHotel(card.dataset.hotelCardId, card.dataset.placeId || '', card.dataset.hotelName || '');
            });
        });

        var priceButton = document.getElementById('btn-lookup-hotel-prices');
        if (priceButton) {
            priceButton.addEventListener('click', function () {
                loadHotels(true, true);
            });
        }
    }

    function getActiveFinalStop() {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.place-card'));
        var activeCards = cards.filter(function (card) {
            var id = card.dataset.itemId;
            var status = id && window.itemStatus ? window.itemStatus[id] : card.dataset.status;
            return status !== 'rejected' && card.dataset.status !== 'rejected';
        });

        if (!activeCards.length) return null;

        activeCards.sort(function (a, b) {
            var da = Number(a.dataset.day || 0);
            var db = Number(b.dataset.day || 0);
            if (da !== db) return da - db;
            var ia = Number(a.dataset.itemId || 0);
            var ib = Number(b.dataset.itemId || 0);
            return ia - ib;
        });

        var card = activeCards[activeCards.length - 1];
        var itemId = card.dataset.itemId || '';
        var replacement = itemId && window.replacementMap ? window.replacementMap[itemId] : null;
        var titleEl = card.querySelector('.place-title');

        return {
            itemId: itemId,
            placeId: replacement && replacement.place_id ? replacement.place_id : (card.dataset.placeId || ''),
            title: titleEl ? titleEl.textContent.trim() : '',
            card: card
        };
    }

    function hotelQueryKey(finalStop, lookupPricing) {
        if (!finalStop) return 'none';
        return [getItineraryId(), finalStop.itemId || '', finalStop.placeId || '', lookupPricing ? 'pricing' : 'normal'].join('|');
    }

    async function loadHotels(force, lookupPricing) {
        lookupPricing = lookupPricing === true;
        var itineraryId = getItineraryId();
        if (!itineraryId) return;
        var section = ensureHotelSection();
        if (!section) return;

        var finalStop = getActiveFinalStop();
        if (!finalStop) {
            lastHotelQueryKey = 'none';
            clearSelectedHotel();
            renderNeedFinalStop(section);
            return;
        }

        var key = hotelQueryKey(finalStop, lookupPricing);
        if (!force && key === lastHotelQueryKey) return;
        lastHotelQueryKey = key;
        clearSelectedHotel();
        renderLoading(section, finalStop, lookupPricing);

        var url = 'review_hotels.php?itinerary_id=' + encodeURIComponent(itineraryId)
            + '&item_id=' + encodeURIComponent(finalStop.itemId || '')
            + '&place_id=' + encodeURIComponent(finalStop.placeId || '')
            + (lookupPricing ? '&lookup_pricing=1' : '');

        try {
            var response = await fetch(url, {
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
                clearSelectedHotel();
            }
        } catch (e) {
            renderEmpty(section, { message: 'Network error while loading hotel suggestions.', last_stop: { title: finalStop.title } });
            clearSelectedHotel();
        }
    }

    function scheduleHotelReload(force) {
        clearTimeout(hotelLoadTimer);
        hotelLoadTimer = setTimeout(function () { loadHotels(force, false); }, 180);
    }

    function requireHotelBeforeConfirm() {
        if (typeof window.confirmReview !== 'function' || window.confirmReview.__hotelRequired) return;
        var originalConfirmReview = window.confirmReview;
        var wrappedConfirmReview = function () {
            var finalStop = getActiveFinalStop();
            if (!finalStop) {
                alert('Please keep at least one place before confirming the itinerary.');
                return;
            }
            if (!window.selectedHotelPlaceId) {
                alert('Please select a hotel before confirming the itinerary.');
                var section = ensureHotelSection();
                if (section) section.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            return originalConfirmReview();
        };
        wrappedConfirmReview.__hotelRequired = true;
        window.confirmReview = wrappedConfirmReview;
    }

    function wrapActionFunctions() {
        if (typeof window.acceptPlace === 'function' && !window.acceptPlace.__hotelReloadWrapped) {
            var originalAccept = window.acceptPlace;
            var wrappedAccept = function (itemId) {
                var result = originalAccept(itemId);
                scheduleHotelReload(true);
                return result;
            };
            wrappedAccept.__hotelReloadWrapped = true;
            window.acceptPlace = wrappedAccept;
        }

        if (typeof window.rejectPlace === 'function' && !window.rejectPlace.__hotelReloadWrapped) {
            var originalReject = window.rejectPlace;
            var wrappedReject = function (itemId) {
                var result = originalReject(itemId);
                scheduleHotelReload(true);
                return result;
            };
            wrappedReject.__hotelReloadWrapped = true;
            window.rejectPlace = wrappedReject;
        }

        if (typeof window.replacePlace === 'function' && !window.replacePlace.__hotelReloadWrapped) {
            var originalReplace = window.replacePlace;
            var wrappedReplace = async function () {
                var result = await originalReplace.apply(this, arguments);
                scheduleHotelReload(true);
                return result;
            };
            wrappedReplace.__hotelReloadWrapped = true;
            window.replacePlace = wrappedReplace;
        }

        if (typeof window.resetAll === 'function' && !window.resetAll.__hotelReloadWrapped) {
            var originalReset = window.resetAll;
            var wrappedReset = function () {
                var result = originalReset();
                scheduleHotelReload(true);
                return result;
            };
            wrappedReset.__hotelReloadWrapped = true;
            window.resetAll = wrappedReset;
        }

        requireHotelBeforeConfirm();
    }

    function init() {
        wrapActionFunctions();
        scheduleHotelReload(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    window.addEventListener('load', init);
})();
