// assets/itinerary_review_hotel_loader.js
// Loads hotel suggestions near the current final kept/confirmed itinerary stop and renders selectable hotel cards.
// Google Places is used for hotel discovery. SerpAPI is used only when the user clicks pricing lookup.
(function () {
    var lastHotelQueryKey = '';
    var hotelLoadTimer = null;

    function installHotelStyles() {
        if (document.getElementById('hotel-review-layout-style')) return;
        var style = document.createElement('style');
        style.id = 'hotel-review-layout-style';
        style.textContent = `
            .hotel-review-section {
                padding: 22px !important;
                margin-bottom: 20px !important;
                border-radius: 18px !important;
            }
            .hotel-review-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 18px;
                margin-bottom: 14px;
            }
            .hotel-review-title h3 {
                margin: 0 0 6px !important;
                font-size: 19px;
                line-height: 1.2;
            }
            .hotel-review-title p {
                margin: 0;
                max-width: 820px;
                color: #64748b;
                font-size: 13px;
                line-height: 1.5;
            }
            .hotel-source-pill {
                flex: 0 0 auto;
                display: inline-flex;
                align-items: center;
                gap: 7px;
                padding: 8px 12px;
                border-radius: 999px;
                background: #eff6ff;
                color: #1d4ed8;
                border: 1px solid #bfdbfe;
                font-size: 12px;
                font-weight: 900;
                white-space: nowrap;
            }
            .hotel-final-stop-box {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 14px;
                padding: 12px 14px;
                border-radius: 14px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                margin-bottom: 12px;
            }
            .hotel-final-stop-label {
                margin-bottom: 3px;
                color: #64748b;
                font-size: 11px;
                font-weight: 900;
                letter-spacing: .04em;
                text-transform: uppercase;
            }
            .hotel-final-stop-name {
                color: #0f172a;
                font-size: 14px;
                font-weight: 900;
            }
            .hotel-final-stop-meta {
                color: #64748b;
                font-size: 12px;
                margin-top: 3px;
            }
            .hotel-pricing-toolbar {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 14px;
                padding: 13px 14px;
                border-radius: 14px;
                background: #fff7ed;
                border: 1px solid #fed7aa;
                margin-bottom: 16px;
            }
            .hotel-pricing-copy {
                min-width: 0;
            }
            .hotel-pricing-copy strong {
                display: block;
                margin-bottom: 3px;
                color: #9a3412;
                font-size: 13px;
                font-weight: 900;
            }
            .hotel-pricing-copy span {
                color: #9a3412;
                font-size: 12px;
                line-height: 1.4;
            }
            .hotel-price-btn {
                flex: 0 0 auto;
                border: 0;
                border-radius: 12px;
                padding: 10px 14px;
                background: linear-gradient(135deg, #f59e0b, #f97316);
                color: #111827;
                box-shadow: 0 8px 18px rgba(249, 115, 22, .18);
                cursor: pointer;
                font-size: 12px;
                font-weight: 950;
                white-space: nowrap;
            }
            .hotel-price-btn:hover {
                transform: translateY(-1px);
                filter: brightness(1.02);
            }
            .hotel-price-btn:disabled {
                cursor: not-allowed;
                opacity: .65;
                transform: none;
            }
            .hotel-grid {
                display: grid !important;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)) !important;
                gap: 12px !important;
                align-items: stretch;
            }
            .hotel-card-review {
                position: relative;
                min-height: 176px;
                padding: 16px !important;
                border: 1px solid #e5e7eb !important;
                border-radius: 15px !important;
                background: #ffffff !important;
                display: flex;
                flex-direction: column;
                gap: 8px;
                transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease, background .15s ease;
            }
            .hotel-card-review:hover {
                transform: translateY(-1px);
                border-color: #93c5fd !important;
                box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
            }
            .hotel-card-review.selected {
                border-color: #22c55e !important;
                background: #f0fdf4 !important;
                box-shadow: 0 10px 24px rgba(34, 197, 94, .12);
            }
            .hotel-name-rv {
                padding-right: 82px;
                color: #0f172a;
                font-size: 15px;
                line-height: 1.25;
                font-weight: 950;
            }
            .hotel-meta-rv {
                color: #64748b;
                font-size: 12px;
                line-height: 1.35;
                min-height: 33px;
            }
            .hotel-price-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin-top: 2px;
            }
            .hotel-price-rv {
                color: #4f46e5;
                font-size: 17px;
                font-weight: 950;
                letter-spacing: -.02em;
            }
            .hotel-price-source-badge {
                display: inline-flex;
                align-items: center;
                width: fit-content;
                max-width: 100%;
                padding: 5px 9px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 900;
                line-height: 1.2;
            }
            .hotel-price-source-badge.estimate {
                background: #fef3c7;
                color: #92400e;
            }
            .hotel-price-source-badge.serpapi {
                background: #dcfce7;
                color: #166534;
            }
            .hotel-match-row {
                margin-top: auto;
                padding-top: 6px;
            }
            .hotel-match-top {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                margin-bottom: 6px;
            }
            .hotel-select-badge {
                position: absolute !important;
                top: 12px !important;
                right: 12px !important;
                display: none;
                padding: 5px 9px;
                border-radius: 999px;
                background: #22c55e;
                color: #052e16;
                font-size: 11px;
                font-weight: 950;
            }
            .hotel-card-review.selected .hotel-select-badge {
                display: inline-flex;
            }
            @media (max-width: 760px) {
                .hotel-review-header,
                .hotel-final-stop-box,
                .hotel-pricing-toolbar {
                    flex-direction: column;
                    align-items: stretch;
                }
                .hotel-source-pill,
                .hotel-price-btn {
                    width: fit-content;
                }
            }
        `;
        document.head.appendChild(style);
    }

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

        if (!target) {
            var confirmBar = document.querySelector('.confirm-bar');
            if (!confirmBar || !confirmBar.parentNode) return null;
            target = document.createElement('div');
            target.className = 'card';
            confirmBar.parentNode.insertBefore(target, confirmBar);
        }

        target.classList.add('hotel-review-section');
        return target;
    }

    function hotelHeaderHtml(sourceText) {
        return '' +
            '<div class="hotel-review-header">' +
            '  <div class="hotel-review-title">' +
            '    <h3>Select Your Hotel</h3>' +
            '    <p>Choose one accommodation option near the current final kept stop. Hotels are discovered through Google Places. SerpAPI is used only when you request live price lookup.</p>' +
            '  </div>' +
            '  <div class="hotel-source-pill">' + escHtml(sourceText || 'Google Places') + '</div>' +
            '</div>';
    }

    function finalStopBoxHtml(lastStop) {
        var place = lastStop && lastStop.title ? lastStop.title : 'Current final kept stop';
        var area = lastStop ? [lastStop.district, lastStop.state].filter(Boolean).join(', ') : '';
        return '' +
            '<div class="hotel-final-stop-box">' +
            '  <div>' +
            '    <div class="hotel-final-stop-label">Hotel search location</div>' +
            '    <div class="hotel-final-stop-name">' + escHtml(place) + '</div>' +
            (area ? '    <div class="hotel-final-stop-meta">' + escHtml(area) + '</div>' : '') +
            '  </div>' +
            '  <div class="hotel-source-pill">Final stop based</div>' +
            '</div>';
    }

    function renderLoading(section, finalStop, lookupPricing) {
        var loadingTitle = lookupPricing ? 'Checking live hotel prices...' : 'Loading nearby hotels...';
        var loadingText = lookupPricing
            ? 'Using SerpAPI for pricing only. Any matched prices will be saved to the database cache.'
            : 'Searching Google Places near your current final kept stop.';

        section.innerHTML =
            hotelHeaderHtml(lookupPricing ? 'SerpAPI pricing' : 'Google Places') +
            finalStopBoxHtml(finalStop || {}) +
            '<div class="hotel-empty-state"><strong>' + loadingTitle + '</strong>' + loadingText + '</div>';
    }

    function renderEmpty(section, data) {
        section.innerHTML =
            hotelHeaderHtml('Google Places') +
            finalStopBoxHtml(data && data.last_stop ? data.last_stop : {}) +
            '<div class="hotel-empty-state">' +
            '<strong>No nearby hotel suggestions are available right now.</strong>' +
            escHtml((data && data.message) || 'Google Places did not return hotel results for this location.') +
            '</div>';
    }

    function renderNeedFinalStop(section) {
        section.innerHTML =
            hotelHeaderHtml('Waiting') +
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
        var pricingTitle = livePricesLoaded ? 'Live pricing available' : 'Prices are estimates';
        var pricingNote = livePricesLoaded
            ? 'Cached SerpAPI price data is shown where a hotel name match was found. Confirm final prices before booking.'
            : 'Use SerpAPI only when you need a pricing check. The result is saved to the database cache to avoid repeated quota usage.';
        var buttonText = livePricesLoaded ? 'Refresh live prices' : 'Check live prices';

        var html =
            hotelHeaderHtml(livePricesLoaded ? 'Google Places + SerpAPI' : 'Google Places') +
            finalStopBoxHtml(lastStop) +
            '<div class="hotel-pricing-toolbar">' +
            '  <div class="hotel-pricing-copy">' +
            '    <strong>' + escHtml(pricingTitle) + '</strong>' +
            '    <span>' + escHtml(pricingNote) + '</span>' +
            '  </div>' +
            '  <button type="button" id="btn-lookup-hotel-prices" class="hotel-price-btn">' + escHtml(buttonText) + '</button>' +
            '</div>' +
            '<div class="hotel-grid">';

        hotels.forEach(function (hotel, index) {
            var id = hotelCardId(hotel, index);
            var meta = scoreMeta(hotel);
            var price = Number(hotel.price_per_night || 0);
            var rating = Number(hotel.rating || 0);
            var distance = hotel.distance_km == null ? null : Number(hotel.distance_km);
            var sourceLabel = priceSourceLabel(hotel);
            var isSerpPrice = hotel.price_source === 'serpapi_google_maps_price';

            html +=
                '<div class="hotel-card-review" id="hotel-card-' + escHtml(id) + '" data-hotel-card-id="' + escHtml(id) + '" data-place-id="' + escHtml(hotel.google_place_id || '') + '" data-hotel-name="' + escHtml(hotel.name || '') + '">' +
                '<span class="hotel-select-badge">Selected</span>' +
                '<div class="hotel-name-rv">' + escHtml(hotel.name || 'Unnamed hotel') + '</div>' +
                '<div class="hotel-meta-rv">' + escHtml(hotel.address || [hotel.district, hotel.state].filter(Boolean).join(', ') || 'Address unavailable') +
                (rating > 0 ? ' · Google rating: ' + rating.toFixed(1) + '/5' : '') +
                (distance !== null && !isNaN(distance) ? ' · ' + distance.toFixed(1) + ' km from final stop' : '') +
                '</div>' +
                '<div class="hotel-price-row">' +
                '  <div class="hotel-price-rv">RM ' + (price > 0 ? Math.round(price).toLocaleString() : 'N/A') + ' / night</div>' +
                '</div>' +
                '<span class="hotel-price-source-badge ' + (isSerpPrice ? 'serpapi' : 'estimate') + '">' + escHtml(sourceLabel) + '</span>' +
                '<div class="hotel-match-row">' +
                '  <div class="hotel-match-top">' +
                '    <span class="match-badge badge-' + meta.color + '">' + meta.label + '</span>' +
                '    <span style="font-size:12px; font-weight:900; color:' + meta.hex + ';">' + meta.pct + '%</span>' +
                '  </div>' +
                '  <div class="match-bar-track" style="height:5px;"><div class="match-bar-fill" style="width:' + meta.pct + '%; background:' + meta.hex + ';"></div></div>' +
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
                priceButton.disabled = true;
                priceButton.textContent = 'Checking prices...';
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
        installHotelStyles();
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
