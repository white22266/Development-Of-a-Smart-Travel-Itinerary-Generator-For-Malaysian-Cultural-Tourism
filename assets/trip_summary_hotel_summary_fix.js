// assets/trip_summary_hotel_summary_fix.js
// Cleans and groups selected hotel rows on itinerary/trip_summary.php.
(function () {
    if (window.__tripSummaryHotelSummaryFix) return;
    window.__tripSummaryHotelSummaryFix = true;

    function parseMoney(text) {
        const m = String(text || '').replace(/,/g, '').match(/RM\s*([0-9]+(?:\.\d+)?)/i);
        return m ? Number(m[1]) : 0;
    }

    function cleanHotelMeta(raw) {
        const parts = String(raw || '')
            .split('|')
            .map(part => part.trim())
            .filter(Boolean);

        let nightLabel = '';
        let sourceLabel = '';
        let address = '';
        let priceLabel = '';

        parts.forEach(part => {
            if (/google\s*place\s*id/i.test(part)) return;
            if (/^hotel night\s+\d+\s+of\s+\d+/i.test(part) || /^optional hotel night\s+\d+\s+of\s+\d+/i.test(part)) {
                nightLabel = part.replace(/^optional\s+/i, '');
                return;
            }
            if (/source:/i.test(part)) {
                sourceLabel = part.replace(/^source:\s*/i, '').replace(/_/g, ' ');
                return;
            }
            if (/^(live google places accommodation|google places|serpapi|planning estimate)/i.test(part)) {
                sourceLabel = part.replace(/_/g, ' ');
                return;
            }
            if (/^(estimated\s*)?RM\s*[0-9,.]+\s*\/night/i.test(part)) {
                priceLabel = part.replace(/^Estimated\s*/i, '');
                return;
            }
            if (part.length > address.length) address = part;
        });

        return { nightLabel, sourceLabel, address, priceLabel };
    }

    function findSelectedHotelCard() {
        const cards = Array.from(document.querySelectorAll('.card'));
        return cards.find(card => {
            const h3 = card.querySelector('h3');
            return h3 && h3.textContent.trim().toLowerCase() === 'selected hotel';
        });
    }

    function formatMoney(amount) {
        return 'RM ' + Number(amount || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function buildSummaryRows(rows) {
        const grouped = new Map();

        rows.forEach(row => {
            const nameEl = row.querySelector('.hotel-name');
            const metaEl = row.querySelector('.hotel-meta');
            const priceEl = row.querySelector('.hotel-price');
            const name = nameEl ? nameEl.textContent.trim() : 'Selected hotel';
            const meta = cleanHotelMeta(metaEl ? metaEl.textContent : '');
            const price = parseMoney(priceEl ? priceEl.textContent : '0');
            const addressKey = (meta.address || '').toLowerCase().trim();
            const key = name.toLowerCase().trim() + '|' + addressKey;

            if (!grouped.has(key)) {
                grouped.set(key, {
                    name,
                    nights: 0,
                    total: 0,
                    address: meta.address,
                    source: meta.sourceLabel,
                    priceLabel: meta.priceLabel,
                    nightLabels: []
                });
            }

            const item = grouped.get(key);
            item.nights += 1;
            item.total += price;
            if (!item.address && meta.address) item.address = meta.address;
            if (!item.source && meta.sourceLabel) item.source = meta.sourceLabel;
            if (!item.priceLabel && meta.priceLabel) item.priceLabel = meta.priceLabel;
            if (meta.nightLabel) item.nightLabels.push(meta.nightLabel);
        });

        return Array.from(grouped.values());
    }

    function renderGroupedHotelSummary() {
        const section = findSelectedHotelCard();
        if (!section) return;

        const rows = Array.from(section.querySelectorAll('.hotel-card'));
        if (!rows.length) return;

        const groups = buildSummaryRows(rows);
        if (!groups.length) return;

        const title = section.querySelector('h3');
        if (title) title.textContent = 'Selected Accommodation';

        const meta = section.querySelector('p.meta');
        if (meta) {
            meta.textContent = 'Optional hotel choices saved from itinerary review. Accommodation cost is included in the total estimate.';
        }

        rows.forEach(row => row.remove());

        const wrapper = document.createElement('div');
        wrapper.className = 'selected-accommodation-summary';

        groups.forEach(group => {
            const avg = group.nights > 0 ? group.total / group.nights : group.total;
            const card = document.createElement('div');
            card.className = 'hotel-card hotel-card-grouped';
            card.innerHTML = `
                <div>
                    <div class="hotel-name">${escapeHtml(group.name)}</div>
                    <div class="hotel-meta">
                        ${group.nights} night${group.nights > 1 ? 's' : ''} selected${group.address ? ' · ' + escapeHtml(group.address) : ''}
                    </div>
                    <div class="hotel-meta" style="margin-top:4px;">
                        ${group.source ? 'Source: ' + escapeHtml(titleCase(group.source)) + ' · ' : ''}${formatMoney(avg)}/night × ${group.nights}
                    </div>
                </div>
                <div style="text-align:right;">
                    <div class="hotel-price">${formatMoney(group.total)}</div>
                    <div style="font-size:11px;color:var(--muted);">accommodation total</div>
                </div>
            `;
            wrapper.appendChild(card);
        });

        section.appendChild(wrapper);
    }

    function titleCase(text) {
        return String(text || '')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, ch => ch.toUpperCase())
            .replace(/Google Places Accommodation/i, 'Google Places accommodation')
            .replace(/Serpapi/i, 'SerpAPI');
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderGroupedHotelSummary);
    } else {
        renderGroupedHotelSummary();
    }
})();
