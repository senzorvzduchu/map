/*
 * mine.js — „Moje senzory" (3.6), jen index.html (embed kartu nemá)
 *
 * 1. Sledované senzory: ★ v hlavičce popupu přidá senzor do localStorage (`sv_my_sensors`, max 5).
 *    Karta vlevo dole ukazuje aktuální hodnotu aktivní látky (nebo první dostupné), trend proti
 *    stavu před 3 h (hodinové sloty z get_history.php) a zvonek pro upozornění. Klik na řádek
 *    přiblíží mapu a otevře popup. Při startu se mapa vycentruje na sledované senzory.
 * 2. Deep link `?sensor=<id>` (z notifikace, z odkazu) otevře popup senzoru po načtení dat.
 * 3. Push upozornění (push-api.php, lib/Push.php): pravidlo senzor + látka + práh + hodiny,
 *    volitelně smogové výstrahy ČHMÚ pro ORP senzoru. Odběr vzniká až po kliknutí uživatele
 *    (Notification.requestPermission vyžaduje gesto). iPhone: jen z mapy přidané na plochu.
 * 4. „Větrat?" — orientační rada pro první sledovaný senzor z PM2.5 teď, výhledu ČHMÚ (p50,
 *    ukotveného na senzor) a smogové výstrahy pro jeho ORP. Prahy = pásma EAQI 2024 (15 / 25 / 50).
 *
 * app.js vysílá `sv:data` po každém načtení dat, vkládá ★ přes svMine.starHtml() a po otevření
 * popupu volá svMine.wireStar(). Vše ostatní čte z globálů app.js (map, sensorStore, markers…).
 */
(function () {
    'use strict';
    const card = document.getElementById('mine');
    if (!card) return;

    const KEY = 'sv_my_sensors';
    const COLLAPSED_KEY = 'sv_mine_collapsed';
    const MAX = 5;
    const ORDER = ['pm25', 'pm10', 'no2', 'o3', 'noise', 'temperature', 'humidity'];
    const PUSH_POLLUTANTS = { pm25: 'PM2.5', pm10: 'PM10', no2: 'NO2', o3: 'O3' };
    const PRESETS = {
        pm25: [[15, 'uspokojivá → střední'], [25, 'limit EU 2030 (24 h)'], [50, 'špatná']],
        pm10: [[45, 'limit EU 2030 (24 h)'], [120, 'špatná']],
        no2: [[25, 'uspokojivá → střední'], [60, 'špatná'], [100, 'velmi špatná']],
        o3: [[100, 'střední'], [120, 'limit (8 h)'], [160, 'velmi špatná']],
    };

    let list = load();
    const trends = new Map();     // id → { key, delta, at }
    let initialDone = false;
    let lastTick = 0;
    const push = { supported: false, sub: null, rules: [], endpointChecked: false };
    const vent = { at: 0, forecast: null, forecastAt: 0, alerts: null, alertsAt: 0, html: '' };

    // --- úložiště ----------------------------------------------------------------------
    function load() {
        try {
            const v = JSON.parse(localStorage.getItem(KEY) || '[]');
            return Array.isArray(v) ? v.filter(s => s && typeof s.id === 'string').slice(0, MAX) : [];
        } catch (e) { return []; }
    }
    function save() { try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* soukromý režim */ } }
    function isPinned(id) { return list.some(s => s.id === id); }

    function toggle(id, btn) {
        const rec = sensorStore.get(id);
        if (isPinned(id)) {
            list = list.filter(s => s.id !== id);
            save();
            showToast('Senzor odebrán ze sledovaných.');
            if (push.sub) removeRule(id, true);
        } else {
            if (!rec) return;
            if (list.length >= MAX) { showToast(`Sledovat lze nejvýš ${MAX} senzorů.`, true); return; }
            list.push({ id: rec.id, name: rec.name, network: rec.network, lat: rec.lat, lng: rec.lng });
            save();
            showToast('Senzor přidán do sledovaných (karta vlevo dole).');
        }
        if (btn) setStar(btn, isPinned(id));
        vent.at = 0; // jiný první senzor → rada znovu
        render();
    }

    function setStar(btn, on) {
        btn.classList.toggle('on', on);
        btn.textContent = on ? '★' : '☆';
        btn.title = on ? 'Přestat sledovat' : 'Sledovat tento senzor (karta Moje senzory)';
    }

    /** HTML hvězdičky do hlavičky popupu (app.js renderSensor). */
    function starHtml(id) {
        const on = isPinned(id);
        return `<button type="button" class="popup-star${on ? ' on' : ''}" data-id="${id}" aria-label="Sledovat senzor"`
            + ` title="${on ? 'Přestat sledovat' : 'Sledovat tento senzor (karta Moje senzory)'}">${on ? '★' : '☆'}</button>`;
    }

    /** Po otevření popupu (app.js wirePopupChart): klik na ★ nesmí probublat k mapě (zavřela by popup). */
    function wireStar() {
        const star = document.querySelector('.leaflet-popup .popup-star');
        if (!star || star.dataset.wired) return;
        star.dataset.wired = '1';
        star.addEventListener('click', (e) => { e.stopPropagation(); e.preventDefault(); toggle(star.dataset.id, star); });
    }

    // --- hodnoty a trend ------------------------------------------------------------------
    function valueFor(rec) {
        const keys = [activePollutant].concat(ORDER.filter(k => k !== activePollutant));
        for (const k of keys) {
            const v = rec.values[k];
            if (v !== undefined && v !== null && !isNaN(v) && plausible(k, v)) return { key: k, value: v };
        }
        return null;
    }

    function fmtVal(key, v) {
        const cfg = POLLUTANTS[key];
        return v.toFixed(cfg.decimals ?? (v >= 10 ? 0 : 1)).replace('.', ',');
    }

    async function loadTrend(id, key) {
        const t = trends.get(id);
        if (t && t.key === key && Date.now() - t.at < 5 * 60 * 1000) return;
        trends.set(id, { key, delta: null, at: Date.now() });
        try {
            const res = await fetch(`/historygraph/get_history.php?id=${encodeURIComponent(id)}&type=${encodeURIComponent(POLLUTANTS[key].apiType)}&hours=24&step=hour`);
            if (!res.ok) return;
            const d = await res.json();
            const arr = Array.isArray(d.data) ? d.data : [];
            let last = -1;
            for (let i = arr.length - 1; i >= 0; i--) { if (arr[i] !== null) { last = i; break; } }
            if (last < 3) return;
            let prev = null;
            for (let i = last - 3; i >= Math.max(0, last - 5); i--) { if (arr[i] !== null) { prev = arr[i]; break; } }
            if (prev === null) return;
            trends.set(id, { key, delta: arr[last] - prev, base: prev, at: Date.now() });
            renderRows();
        } catch (e) { /* bez trendu */ }
    }

    function trendArrow(id, key, v) {
        const t = trends.get(id);
        if (!t || t.key !== key || t.delta === null) return ['', ''];
        const cfg = POLLUTANTS[key];
        const minAbs = cfg.meteo ? 1 : (key === 'noise' ? 3 : 2);
        const rel = Math.abs(t.base) > 0 ? Math.abs(t.delta) / Math.abs(t.base) : 1;
        if (Math.abs(t.delta) < minAbs || rel < 0.15) return ['→', 'beze změny za 3 h'];
        return t.delta > 0 ? ['↗', `+${fmtVal(key, Math.abs(t.delta))} za 3 h`] : ['↘', `−${fmtVal(key, Math.abs(t.delta))} za 3 h`];
    }

    // --- karta ----------------------------------------------------------------------------
    function render() {
        if (!list.length) { card.hidden = true; return; }
        card.hidden = false;
        card.classList.toggle('collapsed', localStorage.getItem(COLLAPSED_KEY) === '1');
        renderRows();
        renderVent();
    }

    function renderRows() {
        const body = document.getElementById('mineRows');
        if (!body) return;
        body.innerHTML = list.map(s => {
            const rec = sensorStore.get(s.id);
            const val = rec ? valueFor(rec) : null;
            const stale = !!(rec && rec.meta && rec.meta.stale);
            let valHtml = '<span class="mine-val mine-val-none">bez dat</span>';
            let dot = 'var(--aqi-no-data)';
            let arrow = '', arrowTitle = '';
            if (val) {
                const cfg = POLLUTANTS[val.key];
                dot = stale ? 'var(--aqi-no-data)' : colorFor(val.key, val.value);
                valHtml = `<span class="mine-pol">${cfg.short}</span><span class="mine-val">${fmtVal(val.key, val.value)} <small>${cfg.unit}</small></span>`;
                [arrow, arrowTitle] = trendArrow(s.id, val.key, val.value);
                if (rec) loadTrend(s.id, val.key);
            }
            const rule = ruleFor(s.id);
            const bell = push.supported || !push.endpointChecked
                ? `<button type="button" class="mine-bell${rule ? ' on' : ''}" data-id="${s.id}" title="${rule ? ruleText(rule) : 'Nastavit upozornění'}" aria-label="Upozornění">${rule ? '🔔' : '🔕'}</button>`
                : '';
            return `<div class="mine-row" data-id="${s.id}" title="${escapeHtml(s.name)} — zobrazit na mapě">`
                + `<span class="mine-dot" style="background:${dot}"></span>`
                + `<span class="mine-name">${escapeHtml(s.name)}</span>`
                + valHtml
                + `<span class="mine-trend" title="${arrowTitle}">${arrow}</span>`
                + bell + `</div>`;
        }).join('');
    }

    function focus(id) {
        const s = list.find(x => x.id === id) || sensorStore.get(id);
        if (!s) return;
        const rec = sensorStore.get(id);
        // Senzor bez aktivní látky je na mapě skrytý → přepnout na látku, kterou má
        if (rec && !markers.get(id)) {
            const v = valueFor(rec);
            if (v && v.key !== activePollutant) setPollutant(v.key);
        }
        const zoom = Math.max(map.getZoom(), s.network === 'libcich' ? 15 : 13);
        map.flyTo([s.lat, s.lng], zoom, { duration: 0.6 });
        map.once('moveend', () => {
            const m = markers.get(id);
            if (!m || !m.openPopup) return;
            // autoPan podle aktuální lišty a legendy (jako klik na marker v app.js)
            const p = m.getPopup ? m.getPopup() : null;
            if (p && typeof popupOptions === 'function') Object.assign(p.options, popupOptions());
            m.openPopup();
        });
    }

    // --- start: vycentrovat / deep link ----------------------------------------------------
    function onFirstData() {
        if (initialDone) return;
        initialDone = true;
        const q = new URLSearchParams(location.search);
        const target = q.get('sensor');
        if (target && sensorStore.has(target)) { focus(target); return; }
        if (!list.length) return;
        if (list.length === 1) {
            map.setView([list[0].lat, list[0].lng], Math.max(map.getZoom(), 12));
        } else {
            map.fitBounds(L.latLngBounds(list.map(s => [s.lat, s.lng])), { padding: [60, 60], maxZoom: 13 });
        }
    }

    // --- push: stav ------------------------------------------------------------------------------
    function ruleFor(id) { return push.rules.find(r => r.sensorId === id) || null; }
    function ruleText(r) {
        const label = Object.keys(PUSH_POLLUTANTS).find(k => PUSH_POLLUTANTS[k] === r.pollutant);
        const short = label ? POLLUTANTS[label].short : r.pollutant;
        return `Upozornit: ${short} nad ${String(r.threshold).replace('.', ',')} µg/m³ po ${r.hours} h` + (r.smog ? ' + smog ČHMÚ' : '');
    }
    function isIosBrowserTab() {
        return /iP(hone|ad|od)/.test(navigator.userAgent) && !matchMedia('(display-mode: standalone)').matches;
    }

    async function initPush() {
        push.supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        if (!push.supported) { push.endpointChecked = true; renderRows(); return; }
        try {
            const reg = await navigator.serviceWorker.ready;
            push.sub = await reg.pushManager.getSubscription();
            if (push.sub) {
                const res = await fetch('/push-api.php?action=rules', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: push.sub.endpoint }) });
                if (res.ok) push.rules = (await res.json()).rules || [];
            }
        } catch (e) { /* bez SW (http) → bez push */ }
        push.endpointChecked = true;
        renderRows();
    }

    function b64uToU8(s) {
        const pad = '='.repeat((4 - s.length % 4) % 4);
        const raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, c => c.charCodeAt(0));
    }

    async function ensureSubscription() {
        if (Notification.permission === 'denied') throw new Error('Upozornění jsou v prohlížeči zakázaná. Povolte je v nastavení webu a zkuste znovu.');
        if (Notification.permission !== 'granted') {
            const p = await Notification.requestPermission();
            if (p !== 'granted') throw new Error('Bez povolení upozornění to nejde.');
        }
        if (push.sub) return push.sub;
        const reg = await navigator.serviceWorker.ready;
        const res = await fetch('/push-api.php?action=vapid');
        if (!res.ok) throw new Error('Server upozornění neodpovídá.');
        const { publicKey } = await res.json();
        push.sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToU8(publicKey) });
        return push.sub;
    }

    async function saveRule(id, pollutantKey, threshold, hours, smog) {
        const s = list.find(x => x.id === id);
        if (!s) return;
        const sub = await ensureSubscription();
        let orp = null;
        if (smog && typeof ncLoadOrpAreas === 'function' && await ncLoadOrpAreas()) orp = ncOrpAt(s.lat, s.lng);
        if (smog && orp === null) throw new Error('Pro tento senzor nelze určit oblast výstrah (mimo ČR).');
        const body = {
            endpoint: sub.endpoint, keys: { p256dh: keyOf(sub, 'p256dh'), auth: keyOf(sub, 'auth') },
            sensorId: id, sensorName: s.name, pollutant: PUSH_POLLUTANTS[pollutantKey], threshold, hours, smog, orp,
        };
        const res = await fetch('/push-api.php?action=subscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) throw new Error('Uložení se nezdařilo' + (data.error ? ` (${data.error})` : '') + '.');
        push.rules = data.rules || [];
        renderRows();
    }

    function keyOf(sub, name) {
        const k = sub.getKey(name);
        let s = '';
        new Uint8Array(k).forEach(b => { s += String.fromCharCode(b); });
        return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    async function removeRule(id, silent) {
        if (!push.sub) return;
        try {
            const res = await fetch('/push-api.php?action=unsubscribe', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: push.sub.endpoint, sensorId: id }) });
            const data = await res.json().catch(() => ({}));
            push.rules = data.rules || [];
            if (!push.rules.length) { try { await push.sub.unsubscribe(); } catch (e) { /* nevadí */ } push.sub = null; }
            if (!silent) showToast('Upozornění zrušeno.');
        } catch (e) { if (!silent) showToast('Zrušení se nezdařilo.', true); }
        renderRows();
    }

    async function sendTest() {
        if (!push.sub) return;
        try {
            const res = await fetch('/push-api.php?action=test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: push.sub.endpoint }) });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.ok) showToast('Zkušební zpráva odeslána.');
            else showToast('Zkušební zpráva se nezdařila' + (data.error ? ` (${data.error})` : '') + '.', true);
        } catch (e) { showToast('Zkušební zpráva se nezdařila.', true); }
    }

    // --- push: formulář -------------------------------------------------------------------------
    function openSheet(id) {
        const s = list.find(x => x.id === id);
        const rec = sensorStore.get(id);
        if (!s) return;
        const box = document.getElementById('modalBox');
        const overlay = document.getElementById('modalOverlay');
        if (!box || !overlay) return;
        const rule = ruleFor(id);

        if (!push.supported || isIosBrowserTab()) {
            box.innerHTML = `<h2>Upozornění · ${escapeHtml(s.name)}</h2>
                <p class="mine-help">${isIosBrowserTab()
                    ? 'Na iPhonu fungují upozornění jen z mapy přidané na plochu: v Safari zvolte Sdílet → Přidat na plochu, mapu otevřete z plochy a nastavte upozornění tam.'
                    : 'Tento prohlížeč push upozornění nepodporuje.'}</p>
                <div class="btn-row"><button class="btn btn-primary" id="mineClose">Zavřít</button></div>`;
            overlay.style.display = 'flex';
            document.getElementById('mineClose').addEventListener('click', closeModal);
            return;
        }

        const avail = Object.keys(PUSH_POLLUTANTS).filter(k => rec && rec.values[k] !== undefined && rec.values[k] !== null);
        if (!avail.length) avail.push('pm25');
        let curKey = rule ? (Object.keys(PUSH_POLLUTANTS).find(k => PUSH_POLLUTANTS[k] === rule.pollutant) || avail[0]) : (avail.includes(activePollutant) ? activePollutant : avail[0]);
        let curThreshold = rule ? rule.threshold : PRESETS[curKey][1][0];
        let curHours = rule ? rule.hours : 2;
        let curSmog = rule ? rule.smog : true;

        const presetsHtml = () => PRESETS[curKey].map(([v, l]) =>
            `<button type="button" class="mine-preset${v === curThreshold ? ' on' : ''}" data-v="${v}"><strong>${v}</strong> <span>${l}</span></button>`).join('');

        box.innerHTML = `<h2>Upozornění · ${escapeHtml(s.name)}</h2>
            <p class="mine-help">Zpráva přijde, když hodinový průměr drží nad prahem po zvolenou dobu, a znovu, až klesne zpět. Nejvýš jedna za 6 h.</p>
            <div class="mine-form">
                <label>Látka</label>
                <div class="mine-seg" id="mineKeys">${avail.map(k => `<button type="button" data-k="${k}"${k === curKey ? ' class="on"' : ''}>${POLLUTANTS[k].short}</button>`).join('')}</div>
                <label>Práh (µg/m³)</label>
                <div class="mine-presets" id="minePresets">${presetsHtml()}</div>
                <div class="mine-custom"><span>Vlastní:</span><input type="number" id="mineCustom" min="1" max="1000" step="1" value="${curThreshold}"></div>
                <label>Po dobu</label>
                <div class="mine-seg" id="mineHours">${[1, 2, 3].map(h => `<button type="button" data-h="${h}"${h === curHours ? ' class="on"' : ''}>${h} h</button>`).join('')}</div>
                <label class="mine-check"><input type="checkbox" id="mineSmog"${curSmog ? ' checked' : ''}> Smogové výstrahy ČHMÚ pro oblast senzoru</label>
            </div>
            <div class="mine-err" id="mineErr" hidden></div>
            <div class="btn-row">
                <button class="btn btn-primary" id="mineSave">${rule ? 'Uložit změnu' : 'Zapnout upozornění'}</button>
                ${rule ? '<button class="btn btn-secondary" id="mineRemove">Zrušit</button>' : ''}
            </div>
            ${rule ? '<div class="mine-links"><a href="#" id="mineTest">Poslat zkušební zprávu</a> · <a href="#" id="mineClose">Zavřít</a></div>' : '<div class="mine-links"><a href="#" id="mineClose">Zavřít</a></div>'}`;
        overlay.style.display = 'flex';

        const err = document.getElementById('mineErr');
        const showErr = (m) => { err.textContent = m; err.hidden = !m; };
        document.getElementById('mineKeys').addEventListener('click', (e) => {
            const b = e.target.closest('button'); if (!b) return;
            curKey = b.dataset.k; curThreshold = PRESETS[curKey][1] ? PRESETS[curKey][1][0] : PRESETS[curKey][0][0];
            document.querySelectorAll('#mineKeys button').forEach(x => x.classList.toggle('on', x === b));
            document.getElementById('minePresets').innerHTML = presetsHtml();
            document.getElementById('mineCustom').value = curThreshold;
        });
        document.getElementById('minePresets').addEventListener('click', (e) => {
            const b = e.target.closest('button'); if (!b) return;
            curThreshold = Number(b.dataset.v);
            document.querySelectorAll('#minePresets button').forEach(x => x.classList.toggle('on', x === b));
            document.getElementById('mineCustom').value = curThreshold;
        });
        document.getElementById('mineCustom').addEventListener('input', (e) => {
            curThreshold = Number(e.target.value);
            document.querySelectorAll('#minePresets button').forEach(x => x.classList.toggle('on', Number(x.dataset.v) === curThreshold));
        });
        document.getElementById('mineHours').addEventListener('click', (e) => {
            const b = e.target.closest('button'); if (!b) return;
            curHours = Number(b.dataset.h);
            document.querySelectorAll('#mineHours button').forEach(x => x.classList.toggle('on', x === b));
        });
        document.getElementById('mineSave').addEventListener('click', async () => {
            curSmog = document.getElementById('mineSmog').checked;
            if (!(curThreshold >= 1 && curThreshold <= 1000)) { showErr('Práh musí být 1–1000.'); return; }
            const btn = document.getElementById('mineSave');
            btn.disabled = true; showErr('');
            try {
                await saveRule(id, curKey, curThreshold, curHours, curSmog);
                closeModal();
                showToast('Upozornění zapnuto. Zvonek v kartě pošle zkušební zprávu.');
            } catch (ex) { showErr(ex.message || 'Nepodařilo se uložit.'); btn.disabled = false; }
        });
        const rm = document.getElementById('mineRemove');
        if (rm) rm.addEventListener('click', async () => { await removeRule(id, false); closeModal(); });
        const test = document.getElementById('mineTest');
        if (test) test.addEventListener('click', (e) => { e.preventDefault(); sendTest(); });
        document.getElementById('mineClose').addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
    }

    // --- větrání -------------------------------------------------------------------------------------
    const fmtHour = new Intl.DateTimeFormat('cs-CZ', { timeZone: 'Europe/Prague', hour: '2-digit', minute: '2-digit' });

    async function smogForOrp(lat, lng) {
        if (typeof ncLoadOrpAreas !== 'function' || !await ncLoadOrpAreas()) return null;
        const orp = ncOrpAt(lat, lng);
        if (orp === null) return null;
        if (!vent.alerts || Date.now() - vent.alertsAt > 10 * 60 * 1000) {
            try {
                const res = await fetch('/alerts-proxy.php');
                vent.alerts = res.ok ? ((await res.json()).alerts || []) : [];
            } catch (e) { vent.alerts = []; }
            vent.alertsAt = Date.now();
        }
        return vent.alerts.find(a => (a.orp || []).includes(orp)) || null;
    }

    /** Rada z PM2.5 teď, výhledu (p50 od t0 po step) a smogové výstrahy. Čistá funkce kvůli testování. */
    function ventAdvice(pm, forecast, smog, now) {
        if (smog) return { lvl: 4, verdict: 'Ne', detail: `ČHMÚ vyhlásil: ${smog.event || 'smogová situace'}. Omezte větrání i pobyt venku.` };
        if (pm === null) return { lvl: 0, verdict: '—', detail: 'Senzor teď nehlásí PM2.5.' };
        const val = `PM2.5 ${pm.toFixed(pm >= 10 ? 0 : 1).replace('.', ',')} µg/m³`;
        let next = null; // [{t, v}] dalších 12 h
        if (forecast && Array.isArray(forecast.p50) && forecast.t0 && forecast.step) {
            next = [];
            for (let i = 0; i < forecast.p50.length; i++) {
                const t = forecast.t0 + i * forecast.step;
                if (t <= now) continue;
                if (t > now + 12 * 3600) break;
                if (forecast.p50[i] !== null) next.push({ t, v: forecast.p50[i] });
            }
            if (!next.length) next = null;
        }
        const firstWhere = (pred, hours) => { const h = next ? next.find(p => p.t <= now + hours * 3600 && pred(p.v)) : null; return h ? fmtHour.format(new Date(h.t * 1000)) : null; };
        if (pm <= 15) {
            const worse = firstWhere(v => v > 25, 6);
            return { lvl: 1, verdict: 'Ano', detail: `${val}, pásmo ${pm <= 5 ? 'dobrá' : 'uspokojivá'}.` + (worse ? ` Výhled ČHMÚ: od ${worse} se zhorší, větrejte teď.` : '') };
        }
        if (pm <= 25) {
            const better = firstWhere(v => v <= 15, 6);
            const worse = firstWhere(v => v > 50, 6);
            return { lvl: 2, verdict: 'Ano, krátce', detail: `${val}, pásmo střední.` + (better ? ` Výhled ČHMÚ: pod 15 od ${better}.` : (worse ? ` Výhled ČHMÚ: od ${worse} se výrazně zhorší.` : '')) };
        }
        if (pm <= 50) {
            const better = firstWhere(v => v <= 25, 12);
            return { lvl: 3, verdict: 'Jen krátce', detail: `${val}, pásmo střední, nad limitem 25.` + (better ? ` Výhled ČHMÚ: pod 25 od ${better}.` : '') };
        }
        const better = firstWhere(v => v <= 25, 12);
        return { lvl: 4, verdict: 'Ne', detail: `${val}, pásmo ${pm <= 90 ? 'špatná' : 'velmi špatná'}.` + (better ? ` Výhled ČHMÚ: pod 25 od ${better}.` : ' Výhled ČHMÚ nečeká zlepšení do 12 h.') };
    }

    async function renderVent() {
        const el = document.getElementById('mineVent');
        if (!el) return;
        const primary = list[0];
        const rec = primary ? sensorStore.get(primary.id) : null;
        if (!rec) { el.hidden = true; return; }
        el.hidden = false;
        if (Date.now() - vent.at < 5 * 60 * 1000 && vent.html) { el.innerHTML = vent.html; return; }
        vent.at = Date.now();
        const stale = !!(rec.meta && rec.meta.stale);
        const pm = (!stale && rec.values.pm25 !== undefined && rec.values.pm25 !== null && plausible('pm25', rec.values.pm25)) ? rec.values.pm25 : null;
        let forecast = null, smog = null;
        try {
            if (pm !== null && !POLLUTANTS.pm25.meteo) {
                if (!vent.forecast || vent.forecastId !== rec.id || Date.now() - vent.forecastAt > 30 * 60 * 1000) {
                    vent.forecast = await loadForecast(rec, 'PM2.5');
                    vent.forecastAt = Date.now(); vent.forecastId = rec.id;
                }
                forecast = vent.forecast;
            }
            smog = await smogForOrp(rec.lat, rec.lng);
        } catch (e) { /* bez výhledu */ }
        const a = ventAdvice(pm, forecast, smog, Math.floor(Date.now() / 1000));
        vent.html = `<div class="mine-vent lvl-${a.lvl}" title="Orientační rada podle PM2.5 u senzoru ${escapeHtml(rec.name)}, výhledu ČHMÚ pro kraj a smogových výstrah. Pásma EAQI 2024.">`
            + `<span class="mine-vent-q">Větrat?</span> <strong>${a.verdict}</strong>`
            + `<span class="mine-vent-d">${a.detail}</span></div>`;
        el.innerHTML = vent.html;
    }

    // --- události ------------------------------------------------------------------------------
    document.addEventListener('sv:data', () => {
        onFirstData();
        vent.at = 0;
        render();
        // Vyhodnocení push pravidel na serveru (nejvýš 1× za 5 min; server sám throttluje)
        if (Date.now() - lastTick > 5 * 60 * 1000) {
            lastTick = Date.now();
            fetch('/push-api.php?action=tick', { keepalive: true }).catch(() => { });
        }
    });

    card.addEventListener('click', (e) => {
        const bell = e.target.closest('.mine-bell');
        if (bell) { e.stopPropagation(); openSheet(bell.dataset.id); return; }
        const row = e.target.closest('.mine-row');
        if (row) { focus(row.dataset.id); return; }
        if (e.target.closest('.mine-header')) setCollapsed(!card.classList.contains('collapsed'));
    });

    function setCollapsed(v) {
        card.classList.toggle('collapsed', v);
        try { localStorage.setItem(COLLAPSED_KEY, v ? '1' : '0'); } catch (x) { /* */ }
    }

    window.addEventListener('load', () => { render(); initPush(); });

    window.svMine = { starHtml, wireStar, toggle, isPinned, focus, ventAdvice, list: () => list.slice() };
})();
