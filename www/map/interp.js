/**
 * senzorvzduchu.cz — interpolační vrstva (IDW, váha podle sítě, unášení větrem).
 * Převzato z nosicheck.cz interp.js; přidána síť Libčich.
 *
 * Odhad aktivní látky mezi senzory metodou inverse distance weighting (mocnina 2).
 * Vliv každého senzoru je navíc násoben důvěrou v jeho síť — referenční ČHMÚ
 * musí převážit i proti několika blízkým amatérským senzorům (poměr ~10:1):
 *   ČHMÚ 1.0 > PurpleAir 0.25 > Smart Citizen 0.15 > Sensor.Community 0.10 = Libčich 0.10
 *
 * Vítr (grid z wind-proxy.php): u každého SENZORU se bilineárně vzorkuje U/V,
 * vzdálenost senzor→buňka je anizotropní — po větru zkrácená faktorem
 * 1 + 0.125·rychlost (max 2×), proti větru 1.5× delší. Bezvětří nebo starý grid
 * (>3 h) = kruhové jádro.
 *
 * Poctivostní pravidla:
 *  - buňky dál než CUTOFF_KM (efektivně) od každého senzoru zůstávají průhledné,
 *    od FADE_KM se vytrácí — nekreslíme „data" tam, kde žádná nejsou
 *  - hluk se nikdy neinterpoluje (POLLUTANTS.noise.interp = false)
 *  - skryté sítě (legenda) se do odhadu nezapočítávají
 *
 * Hrubý grid (1 px = buňka) → malý canvas → L.imageOverlay v panelu pod markery;
 * vyhlazení dělá prohlížeč. Přepočet při posunu/zoomu (debounce), obnově dat
 * a přepnutí látky přes window.refreshInterp().
 */

(function () {
    const TRUST = { chmi: 1.0, purpleair: 0.25, sck: 0.15, community: 0.10, libcich: 0.10 };
    const CELL_PX = 10;
    const CUTOFF_KM = 25;
    const FADE_KM = 10;
    const BASE_ALPHA = 110;
    const UPWIND_PENALTY = 1.5;
    const STRETCH_PER_MS = 0.125;
    const STRETCH_MAX = 2.0;
    const CALM_MS = 0.5;

    // Embed (iframe na cizím webu) má interpolaci ve výchozím stavu VYPNUTOU a vlastní
    // klíč v localStorage, aby se volba v iframe a na plné mapě neovlivňovaly.
    const IS_EMBED = /embed\.html$/i.test(location.pathname);
    const STORE_KEY = IS_EMBED ? 'sv_interp_embed' : 'sv_interp';
    let enabled = IS_EMBED
        ? localStorage.getItem(STORE_KEY) === '1'
        : localStorage.getItem(STORE_KEY) !== '0';
    let overlay = null;
    let debounceT = null;
    let windGrid = null;

    async function loadWindGrid() {
        try {
            const res = await fetch('../wind-proxy.php', { cache: 'no-cache' });
            if (!res.ok) return;
            const data = await res.json();
            const u = data.find(r => r.header?.parameterNumber === 2);
            const v = data.find(r => r.header?.parameterNumber === 3);
            const h = u?.header;
            if (!u || !v || !h?.refTime) return;
            if (Date.now() - Date.parse(h.refTime) > 3 * 3600 * 1000) return; // starý → kruhové jádro
            windGrid = { u: u.data, v: v.data, nx: h.nx, ny: h.ny, la1: h.la1, lo1: h.lo1, dx: h.dx, dy: h.dy };
        } catch (e) { /* bez větru → kruhové jádro */ }
    }

    /** Bilineární U/V v bodě; null mimo grid. */
    function windAt(lat, lng) {
        const g = windGrid;
        if (!g) return null;
        const fx = (lng - g.lo1) / g.dx;
        const fy = (g.la1 - lat) / g.dy;
        if (fx < 0 || fy < 0 || fx > g.nx - 1 || fy > g.ny - 1) return null;
        const x0 = Math.floor(fx), y0 = Math.floor(fy);
        const x1 = Math.min(x0 + 1, g.nx - 1), y1 = Math.min(y0 + 1, g.ny - 1);
        const tx = fx - x0, ty = fy - y0;
        const at = (a, y, x) => a[y * g.nx + x];
        const bil = a => at(a, y0, x0) * (1 - tx) * (1 - ty) + at(a, y0, x1) * tx * (1 - ty)
                       + at(a, y1, x0) * (1 - tx) * ty + at(a, y1, x1) * tx * ty;
        return { u: bil(g.u), v: bil(g.v) };
    }

    window.addEventListener('load', function () {
        map.createPane('interpPane');
        map.getPane('interpPane').style.zIndex = 350; // pod overlayPane (400) = pod markery
        map.getPane('interpPane').style.pointerEvents = 'none';

        const row = document.getElementById('interpToggleRow');
        row.classList.toggle('disabled', !enabled);
        row.addEventListener('click', () => {
            enabled = !enabled;
            localStorage.setItem(STORE_KEY, enabled ? '1' : '0');
            row.classList.toggle('disabled', !enabled);
            rebuild();
        });

        map.on('moveend zoomend', scheduleRebuild);

        loadWindGrid().then(scheduleRebuild);
        setInterval(() => loadWindGrid(), 3600 * 1000);
    });

    function scheduleRebuild() {
        clearTimeout(debounceT);
        debounceT = setTimeout(rebuild, 250);
    }
    window.refreshInterp = scheduleRebuild;

    function removeOverlay() {
        if (overlay) { map.removeLayer(overlay); overlay = null; }
    }

    function rebuild() {
        const cfg = POLLUTANTS[activePollutant];
        const row = document.getElementById('interpToggleRow');

        row.classList.toggle('unavailable', !cfg.interp);
        if (!enabled || !cfg.interp) { removeOverlay(); return; }

        const pts = [];
        sensorStore.forEach(rec => {
            const v = rec.values[activePollutant];
            if (v === undefined || v === null || isNaN(v)) return;
            const group = window.layers[rec.network];
            if (group && !map.hasLayer(group)) return; // síť vypnutá v legendě
            const p = { lat: rec.lat, lng: rec.lng, v: v, trust: TRUST[rec.network] || 0.1, wu: 0, wv: 0, s: 1 };
            const w = windAt(rec.lat, rec.lng);
            if (w) {
                const speed = Math.hypot(w.u, w.v);
                if (speed > CALM_MS) {
                    p.wu = w.u / speed;
                    p.wv = w.v / speed;
                    p.s = Math.min(STRETCH_MAX, 1 + STRETCH_PER_MS * speed);
                }
            }
            pts.push(p);
        });
        if (pts.length < 3) { removeOverlay(); return; }

        const size = map.getSize();
        const bounds = map.getBounds();
        const cols = Math.max(2, Math.ceil(size.x / CELL_PX));
        const rows = Math.max(2, Math.ceil(size.y / CELL_PX));

        const canvas = document.createElement('canvas');
        canvas.width = cols;
        canvas.height = rows;
        const ctx = canvas.getContext('2d');
        const img = ctx.createImageData(cols, rows);
        const px = img.data;

        const cosLat = Math.cos(map.getCenter().lat * Math.PI / 180);
        const KM_LAT = 110.574, KM_LNG = 111.320 * cosLat;

        for (let gy = 0; gy < rows; gy++) {
            for (let gx = 0; gx < cols; gx++) {
                const ll = map.containerPointToLatLng([
                    (gx + 0.5) * size.x / cols,
                    (gy + 0.5) * size.y / rows
                ]);
                let wSum = 0, vSum = 0, minD2 = Infinity;
                for (let i = 0; i < pts.length; i++) {
                    const p = pts[i];
                    const rx = (ll.lng - p.lng) * KM_LNG;
                    const ry = (ll.lat - p.lat) * KM_LAT;
                    let d2;
                    if (p.s > 1) {
                        const along = rx * p.wu + ry * p.wv;   // >0 = buňka je po větru
                        const cross2 = rx * rx + ry * ry - along * along;
                        const a = along >= 0 ? along / p.s : along * UPWIND_PENALTY;
                        d2 = a * a + cross2;
                    } else {
                        d2 = rx * rx + ry * ry;
                    }
                    if (d2 < minD2) minD2 = d2;
                    const w = p.trust / (d2 + 0.05);
                    wSum += w;
                    vSum += w * p.v;
                }
                const minD = Math.sqrt(minD2);
                if (minD > CUTOFF_KM || wSum === 0) continue;

                const value = vSum / wSum;
                const hex = colorFor(activePollutant, value);
                const fade = minD <= FADE_KM ? 1 : 1 - (minD - FADE_KM) / (CUTOFF_KM - FADE_KM);

                const o = (gy * cols + gx) * 4;
                px[o]     = parseInt(hex.slice(1, 3), 16);
                px[o + 1] = parseInt(hex.slice(3, 5), 16);
                px[o + 2] = parseInt(hex.slice(5, 7), 16);
                px[o + 3] = Math.round(BASE_ALPHA * fade);
            }
        }
        ctx.putImageData(img, 0, 0);

        const url = canvas.toDataURL();
        removeOverlay();
        overlay = L.imageOverlay(url, bounds, {
            pane: 'interpPane',
            interactive: false,
            className: 'interp-overlay'
        }).addTo(map);
    }
})();
