// assets/itinerary_edit.js
// The edit controls of itinerary_view.php: per stop Replace, Remove, Move up and Move down
// (a meal row has Replace only), per day "Start this day at", "Add a place" and "Regenerate
// this day", and for the trip "Re-plan rainy days". Every change goes to api/itinerary_edit.php,
// which judges it by the planner's rules. After a change the page reloads and shows what
// changed; a refused change shows its reason next to the control and nothing is changed.
//
// The page provides ITINERARY_EDIT (itineraryId, endpoint, search, states) and
// ITINERARY_EDIT_T (texts in the page's language, from i18n_js()).
(function () {
    'use strict';
    if (typeof ITINERARY_EDIT === 'undefined') return;
    const CFG = ITINERARY_EDIT;
    const TEXT = (typeof ITINERARY_EDIT_T === 'object' && ITINERARY_EDIT_T) ? ITINERARY_EDIT_T : {};
    const NOTICE_KEY = 'itineraryEditNotice:' + CFG.itineraryId;
    const TIMEOUT_MS = 90000;

    function T(key, vars) {
        let text = String(TEXT[key] || key);
        Object.keys(vars || {}).forEach(name => { text = text.split('{' + name + '}').join(String(vars[name])); });
        return text;
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? String(meta.getAttribute('content') || '') : '';
    }

    /** POST one action; resolves with the JSON answer (also for a refusal or an error status). */
    async function post(fields) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
        const body = new URLSearchParams(Object.assign({ itinerary_id: String(CFG.itineraryId), csrf_token: csrfToken() }, fields));
        try {
            const resp = await fetch(CFG.endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken() },
                body: body,
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const raw = await resp.text();
            try {
                return JSON.parse(raw);
            } catch (e) {
                return { ok: false, status: 'error', display: T('edit.err_network') };
            }
        } catch (e) {
            return { ok: false, status: 'error', display: T('edit.err_network') };
        } finally {
            clearTimeout(timer);
        }
    }

    function message(box, text, kind) {
        const el = box ? box.querySelector(':scope > .ed-msg') : null;
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('err', kind === 'err');
        el.classList.toggle('ok', kind === 'ok');
    }

    /** A new action starts: the messages of every other control go, so no old refusal stays on the page. */
    function clearOtherMessages(box) {
        document.querySelectorAll('.ed-msg').forEach(el => {
            if (box && el.parentElement === box) return;
            el.textContent = '';
            el.classList.remove('err', 'ok');
        });
    }

    function busy(box, on) {
        if (!box) return;
        box.querySelectorAll('button, input, select').forEach(el => {
            if (on) {
                el.dataset.edWasDisabled = el.disabled ? '1' : '';
                el.disabled = true;
            } else {
                el.disabled = el.dataset.edWasDisabled === '1';
            }
        });
    }

    /** Run a change: on success remember its sentence and reload; otherwise show the reason. */
    async function change(box, fields) {
        busy(box, true);
        message(box, T('edit.saving'), null);
        const data = await post(fields);
        if (data && data.ok) {
            try { sessionStorage.setItem(NOTICE_KEY, String(data.display || data.message || '')); } catch (e) { /* no storage */ }
            message(box, T('edit.reloading'), 'ok');
            window.location.reload();
            return;
        }
        busy(box, false);
        message(box, (data && (data.display || data.message)) || T('edit.err_network'), 'err');
    }

    function button(text, primary, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'ed-btn' + (primary ? ' ed-primary' : '');
        b.textContent = text;
        b.addEventListener('click', onClick);
        return b;
    }

    function optionRow(title, meta, actionButton) {
        const row = document.createElement('div');
        row.className = 'ed-option';
        const left = document.createElement('div');
        const strong = document.createElement('strong');
        strong.textContent = title;
        left.appendChild(strong);
        if (meta) {
            const span = document.createElement('div');
            span.className = 'ed-option-meta';
            span.textContent = meta;
            left.appendChild(span);
        }
        row.appendChild(left);
        row.appendChild(actionButton);
        return row;
    }

    function panelOf(box, name) {
        return box.querySelector(':scope > [data-ed-panel="' + name + '"]');
    }

    function toggle(panel) {
        if (!panel) return false;
        panel.hidden = !panel.hidden;
        return !panel.hidden;
    }

    // ---- Replace: the suggestions with their planned times, more on request
    async function loadSuggestions(box, offset) {
        const panel = panelOf(box, 'replace');
        const itemId = box.dataset.edItem;
        if (offset === 0) panel.textContent = '';
        const loading = document.createElement('div');
        loading.className = 'ed-option-meta';
        loading.textContent = T('edit.loading');
        panel.appendChild(loading);
        const data = await post({ action: 'suggest', item_id: itemId, offset: String(offset) });
        loading.remove();
        const old = panel.querySelector('[data-ed-more-options]');
        if (old) old.remove();
        if (!data || !data.ok) {
            message(box, (data && (data.display || data.message)) || T('edit.err_network'), 'err');
            return;
        }
        const options = Array.isArray(data.options) ? data.options : [];
        if (!options.length && offset === 0) {
            const none = document.createElement('div');
            none.className = 'ed-option-meta';
            none.textContent = T('edit.suggest_none');
            panel.appendChild(none);
            return;
        }
        options.forEach(o => {
            const name = Number(o.place_id) === 0 ? T('edit.meal_break') : String(o.name || '');
            const meta = [o.category ? String(o.category) : '', T('edit.planned_at', { start: o.start, end: o.end }),
                Number(o.cost || 0) > 0 ? 'RM ' + Number(o.cost).toFixed(2) : ''].filter(Boolean).join(' · ');
            panel.appendChild(optionRow(name, meta, button(T('edit.choose'), true,
                () => change(box, { action: 'replace', item_id: itemId, place_id: String(Number(o.place_id) || 0) }))));
        });
        if (data.more) {
            const more = button(T('edit.more_options'), false, () => loadSuggestions(box, offset + options.length));
            more.dataset.edMoreOptions = '1';
            panel.appendChild(more);
        }
    }

    // ---- Add a place: search the system's places (api/place_search.php), then add
    let searchTimer = null;
    function search(box, input) {
        clearOtherMessages(box);
        const results = box.querySelector('[data-ed-results]');
        clearTimeout(searchTimer);
        const q = input.value.trim();
        if (q.length < 2) {
            results.textContent = T('edit.search_hint');
            return;
        }
        searchTimer = setTimeout(async () => {
            let data = null;
            try {
                const url = CFG.search + '?q=' + encodeURIComponent(q) + (CFG.states ? '&states=' + encodeURIComponent(CFG.states) : '');
                const resp = await fetch(url, { credentials: 'same-origin' });
                data = await resp.json();
            } catch (e) {
                data = null;
            }
            if (input.value.trim() !== q) return;   // a newer search is on its way
            results.textContent = '';
            const list = data && Array.isArray(data.results) ? data.results : [];
            if (!data || data.status !== 'ok') {
                results.textContent = (data && data.message) || T('edit.err_network');
                return;
            }
            if (!list.length) {
                results.textContent = T('edit.no_results');
                return;
            }
            list.forEach(p => {
                const meta = [p.category, p.district, p.state].filter(Boolean).join(' · ');
                results.appendChild(optionRow(String(p.name || ''), meta, button(T('edit.add'), true,
                    () => change(box, { action: 'add', day_no: box.dataset.edDay, place_id: String(Number(p.id) || 0) }))));
            });
        }, 300);
    }

    function onAction(box, action) {
        const day = box.dataset.edDay || '';
        const title = box.dataset.edTitle || '';
        const pos = Number(box.dataset.edPos || 0);
        clearOtherMessages(box);
        message(box, '', null);
        switch (action) {
            case 'replace_open':
                if (toggle(panelOf(box, 'replace'))) loadSuggestions(box, 0);
                break;
            case 'remove':
                if (window.confirm(T('edit.confirm_remove', { title: title, day: day }))) change(box, { action: 'remove', item_id: box.dataset.edItem });
                break;
            case 'move_up':
            case 'move_down':
                if (pos < 1) break;
                change(box, { action: 'move', item_id: box.dataset.edItem, to_day: day, to_position: String(action === 'move_up' ? pos - 1 : pos + 1) });
                break;
            case 'set_day_start': {
                const input = box.querySelector('[data-ed-start]');
                const value = input ? String(input.value || '').slice(0, 5) : '';
                if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) {
                    message(box, T('edit.err_time'), 'err');
                    break;
                }
                change(box, { action: 'set_day_start', day_no: day, start_time: value });
                break;
            }
            case 'clear_day_start':
                change(box, { action: 'set_day_start', day_no: day, start_time: '' });
                break;
            case 'add_open': {
                const panel = panelOf(box, 'add');
                if (toggle(panel)) {
                    const input = panel.querySelector('[data-ed-search]');
                    if (input) input.focus();
                }
                break;
            }
            case 'regen_open':
                toggle(panelOf(box, 'regen'));
                break;
            case 'regenerate_day': {
                const panel = panelOf(box, 'regen');
                const more = panel.querySelector('[data-ed-more]').value;
                const less = panel.querySelector('[data-ed-less]').value;
                if (window.confirm(T('edit.confirm_regenerate', { day: day }))) {
                    change(box, { action: 'regenerate_day', day_no: day, more: more, less: less === more ? '' : less });
                }
                break;
            }
            case 'replan_rain':
                if (window.confirm(T('edit.confirm_replan'))) change(box, { action: 'replan_rain' });
                break;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        // What the last change did, shown once after the reload.
        const notice = document.getElementById('editNotice');
        let saved = '';
        try {
            saved = sessionStorage.getItem(NOTICE_KEY) || '';
            sessionStorage.removeItem(NOTICE_KEY);
        } catch (e) {
            saved = '';
        }
        if (notice && saved) {
            notice.textContent = saved;
            notice.hidden = false;
        }

        document.querySelectorAll('.ed-actions').forEach(box => {
            // The row's own click (focus the stop on the map) is not for these controls.
            box.addEventListener('click', event => {
                event.stopPropagation();
                const control = event.target.closest('[data-ed]');
                if (!control || control.disabled || control.closest('.ed-actions') !== box) return;
                onAction(box, control.dataset.ed);
            });
            const input = box.querySelector('[data-ed-search]');
            if (input) input.addEventListener('input', () => search(box, input));
        });
    });
})();
