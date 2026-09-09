/**
 * alerts.js — výstrahy ČHMÚ (SIVS) na kvalitu ovzduší jako lišta vyjíždějící zleva.
 * Převzato z nosicheck.cz alerts.js; alerts-proxy.php vydává jen kategorii smog.
 *
 * Postup: fetch alerts-proxy.php → jen když nějaká smogová výstraha platí, požádat
 * o polohu (jinak se mapa na polohu nikdy neptá) → s polohou líně načíst
 * orp-areas.js a nechat jen výstrahy, jejichž ORP návštěvníka obsahuje; bez
 * polohy celostátní přehled. Ticho je platná odpověď: se známou polohou a bez
 * výstrahy v jejím ORP nic nevyjede.
 *
 * Lišta ukazuje nejvýš řazenou výstrahu (server už seřadil: stupeň, platí/chystá
 * se), po 5 s se stáhne do záložky, klik otevře plný text ČHMÚ v modalu.
 *
 * ncLoadOrpAreas() / ncOrpAt() používá i app.js pro určení kraje předpovědi.
 */

const NC_ALERT_META = {
    smog:  { icon: '🌫️', label: 'Znečištění ovzduší' },
    other: { icon: '⚠️', label: 'Výstraha' },
};

const NC_ALERT_LEVEL = {
    2: 'Nízký stupeň nebezpečí',
    3: 'Vysoký stupeň nebezpečí',
    4: 'Extrémní stupeň nebezpečí',
};

const NC_ALERT_HIDE_MS = 5000;
const NC_ALERT_GEO_TIMEOUT_MS = 8000;

let ncAlertData = null;
let ncAlertScope = 'cz';
let ncAlertTimer = null;
let ncAlertPinned = false;

// ---------------------------------------------------------------------------
// ORP lookup (www/map/orp-areas.js)
// ---------------------------------------------------------------------------

let ncOrpRings = null;

function ncOrpDecode() {
    if (ncOrpRings || !window.NC_ORP_AREAS) return ncOrpRings;
    const src = window.NC_ORP_AREAS;
    const idx = {};
    for (let i = 0; i < src.alpha.length; i++) idx[src.alpha[i]] = i;

    ncOrpRings = src.a.map(([code, s]) => {
        const vals = [];
        let cur = 0, shift = 0;
        for (let i = 0; i < s.length; i++) {
            const c = idx[s[i]];
            cur |= (c & 31) << shift;
            if (c & 32) { shift += 5; continue; }
            vals.push((cur & 1) ? -((cur + 1) >> 1) : (cur >> 1));
            cur = 0; shift = 0;
        }
        const ring = [];
        let x = vals[0], y = vals[1];
        let minX = x, maxX = x, minY = y, maxY = y;
        ring.push(x, y);
        for (let i = 2; i < vals.length; i += 2) {
            x += vals[i]; y += vals[i + 1];
            ring.push(x, y);
            if (x < minX) minX = x; else if (x > maxX) maxX = x;
            if (y < minY) minY = y; else if (y > maxY) maxY = y;
        }
        return { code, ring, minX, maxX, minY, maxY };
    });
    return ncOrpRings;
}

/** lat/lng → CISORP kód výstražné oblasti ČHMÚ, null mimo ČR. */
function ncOrpAt(lat, lng) {
    const areas = ncOrpDecode();
    if (!areas) return null;
    const t = window.NC_ORP_AREAS.t;
    const px = t.x0 + lng * t.kx;
    const py = t.y0 - lat * t.ky;

    for (const a of areas) {
        if (px < a.minX || px > a.maxX || py < a.minY || py > a.maxY) continue;
        const r = a.ring;
        let inside = false;
        for (let i = 0, j = r.length - 2; i < r.length; j = i, i += 2) {
            const xi = r[i], yi = r[i + 1], xj = r[j], yj = r[j + 1];
            if ((yi > py) !== (yj > py) && px < ((xj - xi) * (py - yi)) / (yj - yi) + xi) {
                inside = !inside;
            }
        }
        if (inside) return a.code;
    }
    return null;
}

/** Načte polygony na vyžádání (35 kB) — jen když je opravdu potřeba. */
function ncLoadOrpAreas() {
    if (window.NC_ORP_AREAS) return Promise.resolve(true);
    if (ncLoadOrpAreas._p) return ncLoadOrpAreas._p;
    ncLoadOrpAreas._p = new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = 'orp-areas.js?v=2.8';
        s.onload = () => resolve(true);
        s.onerror = () => resolve(false);
        document.head.appendChild(s);
    });
    return ncLoadOrpAreas._p;
}

// ---------------------------------------------------------------------------
// Formátování
// ---------------------------------------------------------------------------

function ncAlertTime(iso) {
    const d = new Date(iso);
    if (isNaN(d)) return '';
    const time = d.toLocaleTimeString('cs-CZ', { hour: 'numeric', minute: '2-digit' });
    const now = new Date();
    const days = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate())
        - new Date(now.getFullYear(), now.getMonth(), now.getDate())) / 86400000);
    if (days === 0) return time;
    if (days === 1) return 'zítra ' + time;
    return d.toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric' }) + '. ' + time;
}

function ncAlertValidity(a) {
    const parts = [];
    if (!a.inforce && a.onset) parts.push('od ' + ncAlertTime(a.onset));
    if (a.expires) parts.push('do ' + ncAlertTime(a.expires));
    return parts.join(' ');
}

function ncAlertRegions(a) {
    const n = (a.regions || []).length;
    if (n === 0) return '';
    if (n === 1) return a.regions[0];
    if (n <= 4) return n + ' kraje';
    if (n >= 14) return 'celou ČR';
    return n + ' krajů';
}

// ---------------------------------------------------------------------------
// Lišta
// ---------------------------------------------------------------------------

function ncAlertRender(list, scope) {
    const el = document.getElementById('ncAlert');
    if (!el || !list.length) return;

    const a = list[0];
    const meta = NC_ALERT_META[a.cat] || NC_ALERT_META.other;
    const more = list.length - 1;
    const where = scope === 'local' ? 'Ve vašem okolí' : ncAlertRegions(a);
    const when = ncAlertValidity(a);

    el.className = 'nc-alert lvl-' + a.level + ' is-smog';
    el.innerHTML = `
        <div class="nc-alert-card" role="status" aria-live="polite" tabindex="0">
            <span class="nc-alert-icon" aria-hidden="true">${meta.icon}</span>
            <span class="nc-alert-body">
                <span class="nc-alert-kicker">${escapeHtml(NC_ALERT_LEVEL[a.level] || 'Výstraha')} · ČHMÚ</span>
                <span class="nc-alert-title">${escapeHtml(a.event)}</span>
                <span class="nc-alert-meta">${escapeHtml([where, when].filter(Boolean).join(' · '))}</span>
                ${more > 0 ? `<span class="nc-alert-more">+${more} další${more >= 5 ? 'ch' : ''}</span>` : ''}
            </span>
            <button class="nc-alert-x" type="button" aria-label="Zavřít výstrahu">×</button>
        </div>
        <button class="nc-alert-tab" type="button" aria-label="Zobrazit výstrahu ČHMÚ" aria-expanded="false">
            <span aria-hidden="true">${meta.icon}</span>${list.length > 1 ? `<b>${list.length}</b>` : ''}
        </button>`;

    const card = el.querySelector('.nc-alert-card');
    const tab = el.querySelector('.nc-alert-tab');

    el.querySelector('.nc-alert-x').addEventListener('click', (e) => {
        e.stopPropagation();
        ncAlertCollapse();
    });
    card.addEventListener('click', ncAlertOpenDetail);
    card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ncAlertOpenDetail(); }
        if (e.key === 'Escape') ncAlertCollapse();
    });
    tab.addEventListener('click', ncAlertExpand);

    ['mouseenter', 'focusin'].forEach((ev) => card.addEventListener(ev, () => {
        ncAlertPinned = true;
        clearTimeout(ncAlertTimer);
    }));
    ['mouseleave', 'focusout'].forEach((ev) => card.addEventListener(ev, () => {
        ncAlertPinned = false;
        ncAlertArmRetract();
    }));

    el.hidden = false;
    // Vynutit reflow, aby se výchozí stav mimo obrazovku zapsal před přepnutím
    // třídy — requestAnimationFrame v tabu na pozadí nikdy nevystřelí.
    void card.offsetWidth;
    ncAlertExpand();
}

function ncAlertExpand() {
    const el = document.getElementById('ncAlert');
    if (!el) return;
    el.classList.add('open');
    el.classList.remove('retracted');
    el.querySelector('.nc-alert-tab').setAttribute('aria-expanded', 'true');
    ncAlertArmRetract();
}

function ncAlertCollapse() {
    const el = document.getElementById('ncAlert');
    if (!el) return;
    clearTimeout(ncAlertTimer);
    el.classList.remove('open');
    el.classList.add('retracted');
    el.querySelector('.nc-alert-tab').setAttribute('aria-expanded', 'false');
}

function ncAlertArmRetract() {
    clearTimeout(ncAlertTimer);
    if (ncAlertPinned) return;
    ncAlertTimer = setTimeout(ncAlertCollapse, NC_ALERT_HIDE_MS);
}

// ---------------------------------------------------------------------------
// Detail (sdílený modal, closeModal() z app.js)
// ---------------------------------------------------------------------------

function ncAlertOpenDetail() {
    if (!ncAlertData || !ncAlertData.length) return;
    ncAlertPinned = true;
    clearTimeout(ncAlertTimer);

    const rows = ncAlertData.map((a) => {
        const meta = NC_ALERT_META[a.cat] || NC_ALERT_META.other;
        const when = ncAlertValidity(a);
        const regions = (a.regions || []).join(', ');
        return `
            <div class="nc-alert-item lvl-${a.level}">
                <div class="nc-alert-item-head">
                    <span aria-hidden="true">${meta.icon}</span>
                    <strong>${escapeHtml(a.event)}</strong>
                    <span class="nc-alert-chip">${escapeHtml(NC_ALERT_LEVEL[a.level] || '')}</span>
                </div>
                <div class="nc-alert-item-when">${escapeHtml(
                    [a.inforce ? 'právě platí' : 'chystá se', when].filter(Boolean).join(' · '))}</div>
                ${a.desc ? `<p>${escapeHtml(a.desc)}</p>` : ''}
                ${a.instr ? `<p class="nc-alert-instr"><strong>Doporučení:</strong> ${escapeHtml(a.instr)}</p>` : ''}
                ${regions ? `<p class="nc-alert-where"><strong>Oblast:</strong> ${escapeHtml(regions)}</p>` : ''}
            </div>`;
    }).join('');

    const issued = ncAlertData[0] && ncAlertData[0].issued
        ? new Date(ncAlertData[0].issued).toLocaleString('cs-CZ',
            { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' })
        : null;

    document.getElementById('modalBox').innerHTML = `
        <h2>Výstrahy ČHMÚ – kvalita ovzduší</h2>
        <p class="nc-alert-scope">${ncAlertScope === 'local'
            ? 'Zobrazeny výstrahy platné pro <strong>vaši polohu</strong> (obec s rozšířenou působností podle ČHMÚ).'
            : 'Zobrazen <strong>celostátní přehled</strong>. Povolte polohu a uvidíte jen to, co platí u vás.'}</p>
        ${rows}
        <p class="nc-alert-src">
            Zdroj: <a href="https://vystrahy-cr.chmi.cz/" target="_blank" rel="noopener">Systém integrované
            výstražné služby ČHMÚ</a>${issued ? ' · vydáno ' + escapeHtml(issued) : ''}.
            Mapa zobrazuje jen výstrahy na kvalitu ovzduší (smog, prach, ozon, NO₂, SO₂), tak jak je ČHMÚ vydal.
        </p>
        <div class="btn-row"><button class="btn btn-primary" id="ncAlertCloseBtn">Rozumím</button></div>`;

    document.getElementById('modalOverlay').style.display = 'flex';
    document.getElementById('ncAlertCloseBtn').addEventListener('click', () => {
        closeModal();
        ncAlertPinned = false;
        ncAlertCollapse();
    });
}

// ---------------------------------------------------------------------------
// Start
// ---------------------------------------------------------------------------

/**
 * Poloha jen když je k čemu: ptáme se až ve chvíli, kdy nějaká smogová výstraha
 * platí. Odmítnutí / timeout = celostátní přehled. Výsledek zveřejňujeme ve
 * window.ncUserPos pro případné další použití, ptáme se nejvýš jednou.
 */
function ncAlertPosition() {
    if (window.ncUserPos !== undefined) return Promise.resolve(window.ncUserPos);
    if (!navigator.geolocation) { window.ncUserPos = null; return Promise.resolve(null); }
    return new Promise((resolve) => {
        const done = (v) => { window.ncUserPos = v; resolve(v); };
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const la = pos.coords.latitude, lo = pos.coords.longitude;
                done((la < 48.0 || la > 51.5 || lo < 11.5 || lo > 19.5) ? null : { lat: la, lng: lo });
            },
            () => done(null),
            { maximumAge: 600000, timeout: NC_ALERT_GEO_TIMEOUT_MS }
        );
    });
}

async function ncAlertInit() {
    let data;
    try {
        const res = await fetch('../alerts-proxy.php', { cache: 'no-cache' });
        if (!res.ok) return;
        data = await res.json();
    } catch (e) {
        return;   // výstrahy jsou bonus, mapa se kvůli nim nikdy nerozbije
    }

    const all = data.alerts || [];
    if (!all.length) return;
    if (!data.generated || (Date.now() - Date.parse(data.generated)) > 3 * 3600 * 1000) {
        console.warn('ČHMÚ alert cache stale — banner hidden');
        return;
    }

    const pos = await ncAlertPosition();
    let list = all;
    let scope = 'cz';

    if (pos && await ncLoadOrpAreas()) {
        const orp = ncOrpAt(pos.lat, pos.lng);
        if (orp !== null) {
            list = all.filter((a) => (a.orp || []).includes(orp));
            scope = 'local';
        }
    }
    if (!list.length) return;

    ncAlertData = list;
    ncAlertScope = scope;
    ncAlertRender(list, scope);
}

window.addEventListener('load', () => {
    if (document.getElementById('ncAlert')) ncAlertInit();
});
