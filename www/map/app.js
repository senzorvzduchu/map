/**
 * senzorvzduchu.cz — mapa 2.8: jádro (sítě, markery, historie).
 * Sdílený soubor pro index.html i embed.html.
 * 3.0: teplota a vlhkost jako plnohodnotné „látky" v POLLUTANTS (vlastní barvy,
 * otevřený spodní okraj škály, bez interpolace, bez výhledu, ČHMÚ skryto).
 * 3.1: meteorologické stanice ČHMÚ (chmi-meteo-proxy.php, otevřená 10min data) jako
 * referenční síť pro teplotu a vlhkost — záznamy CHMI_M_<WSI> v síti 'chmi' nesou
 * jen temperature/humidity (meta.meteoOnly), takže u látek nejsou vidět ani jako
 * prázdné kolečko; stanice kvality ovzduší ČHMÚ zůstávají u meteo vrstev skryté.
 *
 * Multipollutant model (převzato z nosicheck.cz, rozšířeno o Libčich):
 *  - POLLUTANTS = registr látek (pásma, barvy, poloměr, graf, interpolace)
 *  - fetchery normalizují hodnoty do sensorStore, renderSensor() kreslí
 *    marker podle activePollutant; lišta nahoře přepíná látku
 *  - interpolace v interp.js, vítr ve wind.js, metodika v info.js
 */

// --- Globální stav sdílený moduly ---
let map;
const markers = new Map();       // sensorId -> L.circleMarker
const sensorStore = new Map();   // sensorId -> {id, network, lat, lng, name, values}
let isLoading = false;
let activeChart = null;
let libcichBounds = null;
let chartRange = localStorage.getItem('sv_chart_range') === '5d' ? '5d' : '24h';

// --- Registr látek ---
// Pásma PM10/NO2/O3 = EEA European Air Quality Index (hodinová, µg/m³);
// Pásma PM2.5/PM10/NO2/O3 = EEA EAQI po revizi 2024 (ETC HE 2024/17, hodinové hodnoty pro
// všechny látky). Dřív EAQI 2017 (PM2.5 10/20/25/50/75, PM10 20/40/50/100/150,
// NO2 40/90/120/230/340, O3 50/100/130/240/380) a před 2.8.4 PM2.5 US EPA. Hluk dB(A) dle WHO.
const BAND_COLORS = ['#87C159', '#F4D35E', '#F48C42', '#E64A45', '#8C6AB6', '#7D5963'];
const NO_DATA_COLOR = '#9ca3af';
// Názvy tříd EEA EAQI (Good / Fair / Moderate / Poor / Very poor / Extremely poor)
const BAND_LABELS_AQ = ['Dobrá', 'Uspokojivá', 'Střední', 'Špatná', 'Velmi špatná', 'Extrémně špatná'];

// 3.0: teplota a vlhkost nejsou zdravotní třídy, proto vlastní barvy místo škály
// EAQI (zelená = „dobrá" by u 15 °C nedávala smysl). Teplota modrá → červená,
// hranice 0/10/20/25/30 °C (30 °C ≈ práh výstrah ČHMÚ na vysoké teploty).
// Vlhkost okrová → modrá, hranice 30/50/70/85/95 % (nad ~70 % optické prachové
// senzory nadhodnocují PM, 95+ = mlha/rosa, orosené čidlo visí na 100 %).
const TEMP_COLORS = ['#2C5AA0', '#5AA7E0', '#F4D35E', '#F48C42', '#E64A45', '#8E1B2E'];
const HUM_COLORS  = ['#C9A66B', '#D9D28F', '#9FD1C7', '#5FB3D9', '#2F7FC1', '#1B4F8A'];

// Limitní čáry v grafu = denní limity směrnice (EU) 2024/2881 platné od 2030
// (PM2.5 25, PM10 45, NO2 50 µg/m³ za 24 h; O3 120 µg/m³ max. 8h průměr).
const POLLUTANTS = {
    pm25: {
        label: 'PM2.5', short: 'PM2.5', unit: 'μg/m³', apiType: 'PM2.5',
        bands: [5, 15, 50, 90, 140], bandLabels: BAND_LABELS_AQ,
        heroLabel: 'PM2.5 koncentrace',
        radius: v => 8 + Math.min(v / 10, 10),
        chartMax: 60, limitLine: { value: 25, label: '24h limit 2030' },
        interp: true,
    },
    pm10: {
        label: 'PM10', short: 'PM10', unit: 'μg/m³', apiType: 'PM10',
        bands: [15, 45, 120, 195, 270], bandLabels: BAND_LABELS_AQ,
        heroLabel: 'PM10 koncentrace',
        radius: v => 8 + Math.min(v / 18, 10),
        chartMax: 80, limitLine: { value: 45, label: '24h limit 2030' },
        interp: true,
    },
    no2: {
        label: 'NO₂', short: 'NO₂', unit: 'μg/m³', apiType: 'NO2',
        bands: [10, 25, 60, 100, 150], bandLabels: BAND_LABELS_AQ,
        heroLabel: 'NO₂ koncentrace',
        radius: v => 8 + Math.min(v / 22, 10),
        chartMax: 100, limitLine: { value: 50, label: '24h limit 2030' },
        interp: true,
    },
    o3: {
        label: 'O₃', short: 'O₃', unit: 'μg/m³', apiType: 'O3',
        bands: [60, 100, 120, 160, 180], bandLabels: BAND_LABELS_AQ,
        heroLabel: 'O₃ koncentrace',
        radius: v => 8 + Math.min(v / 36, 10),
        chartMax: 140, limitLine: { value: 120, label: '8h limit 2030' },
        interp: true,
    },
    noise: {
        label: '🔊 Hluk', short: 'Hluk', unit: 'dB(A)', apiType: 'noise',
        bands: [45, 55, 65, 75, 85],
        bandLabels: ['Tiché', 'Klidné', 'Rušné', 'Hlučné', 'Velmi hlučné', 'Nebezpečné'],
        heroLabel: 'Hladina hluku',
        radius: v => 8 + Math.max(0, Math.min((v - 35) / 4, 10)),
        chartMax: 90, limitLine: null,
        interp: false, // hluk je hyperlokální — interpolace by vymýšlela data
    },
    // 3.0: meteo veličiny. `meteo: true` = bez výhledu, ČHMÚ se skryje (na této
    // mapě teplotu nedodává), graf bez gradientu a bez nuly jako spodku osy.
    // `openLow` = první pásmo je „< 0", ne „0 - 0". `decimals` = hero i tooltip.
    temperature: {
        label: '🌡 Teplota', short: 'Teplota', unit: '°C', apiType: 'temperature',
        bands: [0, 10, 20, 25, 30],
        bandLabels: ['Mráz', 'Chladno', 'Mírně', 'Příjemně', 'Teplo', 'Horko'],
        colors: TEMP_COLORS, openLow: true, valid: [-40, 60],
        heroLabel: 'Teplota vzduchu',
        radius: v => 8 + Math.max(0, Math.min((v + 5) / 4, 10)),
        chartMax: null, chartMin: null, limitLine: null,
        interp: false, meteo: true,
    },
    humidity: {
        label: '💧 Vlhkost', short: 'Vlhkost', unit: '%', apiType: 'humidity',
        bands: [30, 50, 70, 85, 95],
        bandLabels: ['Sucho', 'Nízká', 'Střední', 'Vysoká', 'Vlhko', 'Nasyceno'],
        colors: HUM_COLORS, decimals: 0, valid: [0, 100],
        heroLabel: 'Relativní vlhkost',
        radius: v => 8 + Math.max(0, Math.min((v - 20) / 8, 10)),
        chartMax: 100, chartMin: 0, limitLine: null,
        interp: false, meteo: true,
    },
};

const NETWORK_LABELS = {
    community: 'Sensor.Community',
    chmi: 'ČHMÚ (Oficiální)',
    // 3.1: meteorologické stanice ČHMÚ (otevřená data) — jiný zdroj než stanice kvality ovzduší
    chmiMeteo: 'ČHMÚ, meteorologická stanice (oficiální)',
    sck: 'Smart Citizen',
    purpleair: 'PurpleAir',
    libcich: '<a href="https://libcich.cz/" target="_blank" rel="noopener">Projekt Libčich (libcich.cz)</a>',
    // Licence CC BY-SA 4.0 vyžaduje atribuci — proto je součástí popisku zdroje.
    airgradient: '<a href="https://www.airgradient.com/map/" target="_blank" rel="noopener">AirGradient</a> (CC BY-SA 4.0)',
};

// 3.5: `?pollutant=pm25|pm10|no2|o3|noise|temperature|humidity` (zástupci v manifest.json,
// odkazy z webu) má přednost před uloženou volbou; neznámá hodnota se ignoruje.
let activePollutant = new URLSearchParams(location.search).get('pollutant') || localStorage.getItem('sv_pollutant');
if (!POLLUTANTS[activePollutant]) activePollutant = localStorage.getItem('sv_pollutant');
if (!POLLUTANTS[activePollutant]) activePollutant = 'pm25';

/** Hodnota mimo fyzikálně možný rozsah (`valid` v registru, např. -147 °C z vadného
 *  DHT22 v Sensor.Community) se bere jako žádná hodnota: marker se skryje, štítek
 *  v popupu se nevypíše. collect.php má stejné meze. Látky bez `valid` projdou. */
function plausible(pollutant, value) {
    const cfg = POLLUTANTS[pollutant];
    if (!cfg || !cfg.valid) return true;
    return value >= cfg.valid[0] && value <= cfg.valid[1];
}

function colorFor(pollutant, value) {
    if (value === null || value === undefined || isNaN(value)) return NO_DATA_COLOR;
    const cfg = POLLUTANTS[pollutant];
    const bands = cfg.bands;
    const colors = cfg.colors || BAND_COLORS;
    for (let i = 0; i < bands.length; i++) {
        if (value <= bands[i]) return colors[i];
    }
    return colors[bands.length];
}

/** Escapování řetězců třetích stran (názvy zařízení) před vložením do HTML. */
function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showToast(message, isError = false, ms = 4000) {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = message;
    el.className = 'toast' + (isError ? ' error' : '');
    el.style.display = 'block';
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => { el.style.display = 'none'; }, ms);
}

// Modal (metodika) — společná slupka
function closeModal() {
    const overlay = document.getElementById('modalOverlay');
    if (overlay) overlay.style.display = 'none';
}

// Chart plugin: svislá čára + český den při změně dne mezi body (ne jen v 00:00 —
// noční výpadky ČHMÚ půlnoc často spolknou). Slot "gap" (null z get_history.php)
// vyšeduje, aby výpadek dat byl v grafu vidět.
const midnightPlugin = {
    id: 'midnightMarkers',
    afterDraw(chart) {
        const { ctx, scales: { x, y }, data } = chart;
        const dayMap = { Mon: 'Po', Tue: 'Út', Wed: 'St', Thu: 'Čt', Fri: 'Pá', Sat: 'So', Sun: 'Ne' };
        const n = data.labels.length;
        ctx.save();
        let prevDay = null;
        data.labels.forEach((label, i) => {
            const parts = label.split('|');
            if (parts[2] === 'gap') {
                const xPos = x.getPixelForValue(i);
                const left = i > 0 ? (x.getPixelForValue(i - 1) + xPos) / 2 : x.left;
                const right = i < n - 1 ? (xPos + x.getPixelForValue(i + 1)) / 2 : x.right;
                ctx.fillStyle = 'rgba(128,128,128,0.15)';
                ctx.fillRect(left, y.top, right - left, y.bottom - y.top);
                prevDay = parts[1];
                return;
            }
            if (prevDay !== null && parts[1] !== prevDay) {
                const xPos = x.getPixelForValue(i);
                ctx.strokeStyle = isDarkTheme() ? 'rgba(255,255,255,0.15)' : 'rgba(0,0,0,0.1)';
                ctx.lineWidth = 1;
                ctx.setLineDash([]);
                ctx.beginPath();
                ctx.moveTo(xPos, y.top);
                ctx.lineTo(xPos, y.bottom);
                ctx.stroke();
                ctx.fillStyle = '#aaa';
                ctx.font = '9px Inter, sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText(dayMap[parts[1]] || parts[1], xPos, y.bottom + 13);
            }
            prevDay = parts[1];
        });
        ctx.restore();
    }
};

window.addEventListener('load', function () {
    map = L.map('map', {
        zoomControl: true,
        attributionControl: true,
        doubleClickZoom: true,
        zoomAnimation: true
    }).setView([49.8175, 15.473], 8);

    if (map.doubleClickZoom) map.doubleClickZoom.enable();

    // Double-tap zoom na mobilu (spolehlivé napříč prohlížeči)
    let lastTapTime = 0;
    map.getContainer().addEventListener('touchend', function (e) {
        if (e.changedTouches.length !== 1) return;
        const now = Date.now();
        if (now - lastTapTime < 350) {
            e.preventDefault();
            const touch = e.changedTouches[0];
            const rect = map.getContainer().getBoundingClientRect();
            const point = L.point(touch.clientX - rect.left, touch.clientY - rect.top);
            const latlng = map.containerPointToLatLng(point);
            map.setZoomAround(latlng, map.getZoom() + 1);
        }
        lastTapTime = now;
    }, { passive: false });

    // Popisky map nad interpolačním polem (interpPane 350), pod markery (400)
    map.createPane('labelsPane');
    map.getPane('labelsPane').style.zIndex = 375;
    map.getPane('labelsPane').style.pointerEvents = 'none';

    // Podklad: uložená volba vítězí; první návštěva jde podle režimu rozhraní
    // (tmavý režim → tmavé dlaždice). Embed data-theme nikdy nemá → světlá.
    const storedBasemap = localStorage.getItem('sv_basemap');
    let initialBasemap = storedBasemap in BASEMAPS ? storedBasemap
        : (document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light');
    // localStorage je společné pro index i embed: tmavý podklad uložený přepínačem
    // v index.html nesmí ztmavit embed (ten tlačítko „Tmavá" ani přepínač nemá).
    if (initialBasemap === 'dark' && !document.getElementById('themeToggle')) initialBasemap = 'light';
    setBasemap(initialBasemap);
    document.querySelectorAll('.basemap-btn[data-basemap]').forEach(btn => {
        btn.addEventListener('click', () => {
            setBasemap(btn.dataset.basemap);
            localStorage.setItem('sv_basemap', btn.dataset.basemap);
        });
    });

    // 3.2: přepínač tmavého režimu (🌙/☀️ vedle ℹ, jen index.html). Podklad jde
    // s režimem; pozdější ruční volba podkladu v legendě vítězí (výše).
    const themeBtn = document.getElementById('themeToggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            localStorage.setItem('sv_theme', next);
            applyTheme(next);
            if (next === 'dark' && activeBasemapKey !== 'dark') {
                localStorage.setItem('sv_basemap_prev', activeBasemapKey);
                setBasemap('dark');
                localStorage.setItem('sv_basemap', 'dark');
            } else if (next === 'light' && activeBasemapKey === 'dark') {
                const prev = localStorage.getItem('sv_basemap_prev') || 'light';
                setBasemap(prev);
                localStorage.setItem('sv_basemap', prev);
            }
            // Okraj ČHMÚ markerů a barvy grafu jsou podle režimu → překreslit
            // (stejná cesta jako přepnutí látky; otevřený popup si graf nahraje znovu).
            renderAllSensors();
            if (typeof window.layers.libcich.refreshClusters === 'function') window.layers.libcich.refreshClusters();
        });
        applyTheme(document.documentElement.dataset.theme || 'light'); // sesynchronizovat tlačítko + meta
    }

    // Vrstvy per síť (přepínání v legendě)
    window.layers = {
        community: L.layerGroup().addTo(map),
        chmi:      L.layerGroup().addTo(map),
        sck:       L.layerGroup().addTo(map),
        purpleair: L.layerGroup().addTo(map),
        airgradient: L.layerGroup().addTo(map),
        // Libčich: ~50 senzorů v malém území → clustering IZOLOVANĚ jen na této vrstvě
        libcich:   (typeof L.markerClusterGroup === 'function'
            ? L.markerClusterGroup({
                maxClusterRadius: 60,
                disableClusteringAtZoom: 15,
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false,
                iconCreateFunction: function (cluster) {
                    const vals = cluster.getAllChildMarkers()
                        .map(m => sensorStore.get(m.options.sensorId)?.values[activePollutant])
                        .filter(v => v !== null && v !== undefined && !isNaN(v) && plausible(activePollutant, v));
                    const med = vals.length ? medianOf(vals) : null;
                    return L.divIcon({
                        html: `<div style="background:${colorFor(activePollutant, med)}">${cluster.getChildCount()}</div>`,
                        className: 'libcich-cluster',
                        iconSize: [34, 34]
                    });
                }
            })
            : L.layerGroup()) // fallback když CDN plugin nenaběhne
            .addTo(map),
        // 'wind' = velocity vrstva (wind.js), 'interp' = canvas overlay (interp.js)
    };

    document.querySelectorAll('.legend-source-item[data-layer]').forEach(el => {
        if (['wind', 'interp'].includes(el.dataset.layer)) return;
        el.addEventListener('click', () => {
            const group = window.layers[el.dataset.layer];
            if (!group) return;
            if (map.hasLayer(group)) {
                map.removeLayer(group);
                el.classList.add('disabled');
            } else {
                map.addLayer(group);
                el.classList.remove('disabled');
            }
            if (window.refreshInterp) window.refreshInterp();
        });
    });

    // „Přiblížit na Libčice"
    const zoomLib = document.getElementById('zoomLibcich');
    if (zoomLib) zoomLib.addEventListener('click', (e) => {
        e.stopPropagation();
        if (libcichBounds) map.fitBounds(libcichBounds.pad(0.15));
    });

    document.getElementById('legendHeader').addEventListener('click', toggleLegend);
    if (window.innerWidth <= 768) toggleLegend();

    document.querySelectorAll('.pollutant-chip[data-pollutant]').forEach(btn => {
        btn.addEventListener('click', () => setPollutant(btn.dataset.pollutant));
    });
    updatePollutantChips();
    renderLegendBands();
    layoutLegend();
    window.addEventListener('resize', layoutLegend);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(layoutLegend);

    // Modal: zavření kliknutím mimo / Escape
    const overlay = document.getElementById('modalOverlay');
    if (overlay) overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

    fetchAllData();
    setInterval(fetchAllData, 300000); // 5 minut

    // Dlouho otevřená záložka (typicky mobil: Safari ji drží dny a jen v ní
    // běží tenhle interval) nikdy nenačte nový app.js — 7. 9. 2026 tak iPhone
    // ukazoval poznámku pod grafem ještě den po jejím odstranění. Po 6 h se
    // stránka při návratu do záložky nebo při dalším obnovení dat načte znovu.
    const loadedAt = Date.now();
    const STALE_PAGE_MS = 6 * 3600 * 1000;
    const reloadIfStale = () => {
        if (document.visibilityState !== 'visible') return;
        if (Date.now() - loadedAt < STALE_PAGE_MS) return;
        if (document.querySelector('.leaflet-popup')) return; // uživatel zrovna něco čte
        location.reload();
    };
    document.addEventListener('visibilitychange', reloadIfStale);
    window.addEventListener('pageshow', (e) => { if (e.persisted) reloadIfStale(); });
    setInterval(reloadIfStale, 300000);
});

// Legenda se nesmí překrývat s lištou látek: na širokých obrazovkách zůstává
// nahoře vpravo, jakmile by se dotkly, sjede pod lištu (měříme, neodhadujeme).
function layoutLegend() {
    const bar = document.getElementById('pollutantBar');
    const legend = document.querySelector('.legend');
    if (!bar || !legend) return;
    const b = bar.getBoundingClientRect();
    const l = legend.getBoundingClientRect();
    const overlaps = b.right + 10 > l.left;
    legend.style.top = overlaps ? Math.round(b.bottom + 8) + 'px' : '';
}

// --- Tmavý režim (3.2) ---
// Inline skript v <head> index.html nastaví data-theme před prvním vykreslením
// (bez bliknutí); tady se drží tlačítko, meta theme-color a sledování změny v OS.
// embed.html skript ani tlačítko nemá → zůstává světlý a nic z tohoto se neděje.
function isDarkTheme() {
    return document.documentElement.dataset.theme === 'dark';
}
function applyTheme(theme) {
    document.documentElement.dataset.theme = theme;
    const btn = document.getElementById('themeToggle');
    if (btn) {
        btn.textContent = theme === 'dark' ? '☀️' : '🌙';
        btn.title = theme === 'dark' ? 'Světlý režim' : 'Tmavý režim';
    }
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#1f2937' : '#21275F');
}
// Změnu v OS sledovat jen dokud uživatel nezvolil ručně (a jen tam, kde je tlačítko)
if (window.matchMedia && document.getElementById('themeToggle')) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (!localStorage.getItem('sv_theme')) {
            applyTheme(e.matches ? 'dark' : 'light');
            renderAllSensors();
        }
    });
}

// --- Podkladové mapy ---
// CARTO bez popisků + samostatné popisky v labelsPane, aby interpolační pole
// leželo mezi mapou a jejími popisky. OSM má popisky v dlaždicích (fallback).
// Klíč žije v config.js (mimo git, viz config.example.js).
const CARTO_KEY = (window.SV_CONFIG && window.SV_CONFIG.CARTO_KEY) || '';
if (!CARTO_KEY) console.warn('CARTO_KEY chybí – zkopíruj config.example.js → config.js');
const BASEMAPS = {
    light: {
        base: `https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png?key=${CARTO_KEY}`,
        labels: `https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png?key=${CARTO_KEY}`,
        attribution: '&copy; OpenStreetMap &copy; CARTO', subdomains: 'abcd', maxZoom: 20,
    },
    voyager: {
        base: `https://{s}.basemaps.cartocdn.com/rastertiles/voyager_nolabels/{z}/{x}/{y}{r}.png?key=${CARTO_KEY}`,
        labels: `https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png?key=${CARTO_KEY}`,
        attribution: '&copy; OpenStreetMap &copy; CARTO', subdomains: 'abcd', maxZoom: 20,
    },
    osm: {
        base: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        labels: null,
        attribution: '&copy; OpenStreetMap contributors', subdomains: 'abc', maxZoom: 19,
    },
    // 3.2: tmavé dlaždice (tlačítko „Tmavá" jen v index.html; tmavý režim je volí sám)
    dark: {
        base: `https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}{r}.png?key=${CARTO_KEY}`,
        labels: `https://{s}.basemaps.cartocdn.com/dark_only_labels/{z}/{x}/{y}{r}.png?key=${CARTO_KEY}`,
        attribution: '&copy; OpenStreetMap &copy; CARTO', subdomains: 'abcd', maxZoom: 20,
    },
};
let baseTileLayer = null;
let labelTileLayer = null;
let activeBasemapKey = 'light';

function setBasemap(key) {
    const cfg = BASEMAPS[key] || BASEMAPS.light;
    activeBasemapKey = BASEMAPS[key] ? key : 'light';
    // interp.js míchá multiply na světlých dlaždicích, screen na tmavých (style.css)
    document.body.classList.toggle('basemap-dark', activeBasemapKey === 'dark');
    if (baseTileLayer) map.removeLayer(baseTileLayer);
    if (labelTileLayer) { map.removeLayer(labelTileLayer); labelTileLayer = null; }

    baseTileLayer = L.tileLayer(cfg.base, {
        attribution: cfg.attribution, subdomains: cfg.subdomains, maxZoom: cfg.maxZoom
    }).addTo(map);
    if (cfg.labels) {
        labelTileLayer = L.tileLayer(cfg.labels, {
            pane: 'labelsPane', subdomains: cfg.subdomains, maxZoom: cfg.maxZoom
        }).addTo(map);
    }
    document.querySelectorAll('.basemap-btn[data-basemap]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.basemap === activeBasemapKey);
    });
}

// --- Přepínání látky ---
function setPollutant(key) {
    if (!POLLUTANTS[key] || key === activePollutant) return;
    activePollutant = key;
    localStorage.setItem('sv_pollutant', key);
    updatePollutantChips();
    renderLegendBands();
    renderAllSensors();
    if (typeof window.layers.libcich.refreshClusters === 'function') window.layers.libcich.refreshClusters();
    if (window.refreshInterp) window.refreshInterp();
}

function updatePollutantChips() {
    document.querySelectorAll('.pollutant-chip[data-pollutant]').forEach(btn => {
        const active = btn.dataset.pollutant === activePollutant;
        btn.classList.toggle('active', active);
        // 3.0: osm čipů se na mobil nevejde, lišta se posouvá — aktivní čip
        // (např. Vlhkost z popupu nebo z localStorage) nesmí zůstat mimo displej.
        if (active && btn.scrollIntoView) btn.scrollIntoView({ block: 'nearest', inline: 'center' });
    });
}

function renderLegendBands() {
    const cfg = POLLUTANTS[activePollutant];
    document.getElementById('legendTitle').textContent = `${cfg.short} (${cfg.unit})`;
    const holder = document.getElementById('legendBands');
    let html = '';
    const colors = cfg.colors || BAND_COLORS;
    let prev = 0;
    cfg.bands.forEach((b, i) => {
        // Teplota jde pod nulu: první řádek je „< 0", ne „0 - 0".
        const range = (i === 0 && cfg.openLow) ? `< ${b}` : `${prev} - ${b}`;
        html += `<div class="legend-item"><div class="legend-color" style="background:${colors[i]}"></div><span>${range} (${cfg.bandLabels[i]})</span></div>`;
        prev = b;
    });
    html += `<div class="legend-item"><div class="legend-color" style="background:${colors[cfg.bands.length]}"></div><span>${prev}+ (${cfg.bandLabels[cfg.bands.length]})</span></div>`;
    holder.innerHTML = html;
}

// --- Pomocné ---
function toggleLegend() {
    const content = document.getElementById('legendContent');
    const toggle = document.getElementById('legendToggle');
    content.classList.toggle('collapsed');
    toggle.textContent = content.classList.contains('collapsed') ? '▲' : '▼';
}

function haversineKm(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function medianOf(arr) {
    const s = [...arr].sort((a, b) => a - b);
    const m = Math.floor(s.length / 2);
    return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
}

// --- Blokace senzorů (ruční z admin/bans.php + automatické příznaky plochého průběhu) ---
// Blokovaný senzor se nedostane do sensorStore, takže chybí na mapě i v interpolaci.
// Selhání fetche = bez filtrování (mapa musí běžet dál).
let sensorBans = new Set();

async function fetchSensorBans() {
    try {
        const res = await fetch('../sensor-bans.php', { cache: 'no-cache' });
        if (!res.ok) return;
        const data = await res.json();
        sensorBans = new Set([...(data.banned || []), ...(data.auto || []).map(f => f.sensor_id)]);
        // Odstranit senzory zablokované od minulého cyklu (marker + store)
        sensorBans.forEach(id => {
            const rec = sensorStore.get(id);
            if (!rec) return;
            const marker = markers.get(id);
            if (marker) {
                const group = window.layers[rec.network] || map;
                if (group.hasLayer(marker)) group.removeLayer(marker);
                markers.delete(id);
            }
            sensorStore.delete(id);
        });
    } catch (e) { /* ponechat předchozí seznam */ }
}

// --- Stahování dat ---
async function fetchAllData() {
    if (isLoading) return;
    isLoading = true;
    const updateEl = document.getElementById('lastUpdate');
    const dotEl = document.querySelector('.status-dot');
    updateEl.textContent = 'Načítám...';

    await fetchSensorBans(); // před fetchery — upsertSensor podle něj filtruje

    // Každý fetcher vrací true/false — celkový výpadek je vidět
    const results = await Promise.all([
        fetchSensorCommunityData(),
        fetchChmiData(),
        fetchChmiMeteoData(),
        fetchChmiOtherData(),
        fetchSmartCitizenData(),
        fetchPurpleAirData(),
        fetchLibcichData(),
        fetchAirGradientData(),
    ]);

    const failures = results.filter(ok => !ok).length;
    if (failures >= results.length) {
        updateEl.textContent = 'Chyba načítání';
        dotEl.classList.add('error');
    } else {
        const now = new Date();
        updateEl.textContent = now.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' })
            + (failures > 0 ? ` (${failures} zdroj${failures === 1 ? '' : 'e'} nedostupn${failures === 1 ? 'ý' : 'é'})` : '');
        dotEl.classList.toggle('error', failures > 0);
    }
    isLoading = false;
    if (window.refreshInterp) window.refreshInterp();
}

/**
 * Uloží normalizované hodnoty senzoru a (pře)kreslí jeho marker.
 * meta (volitelné): { stale: bool, ageMin: number } — poslední měření starší než
 * limit sítě; marker se kreslí šedě, popup ukáže stáří a interpolace ho vynechá.
 */
function upsertSensor(id, network, lat, lng, name, values, meta) {
    if (sensorBans.has(id)) return; // ruční blokace nebo automatický příznak
    sensorStore.set(id, { id, network, lat, lng, name, values, meta: meta || null });
    renderSensor(sensorStore.get(id));
}

function renderAllSensors() {
    sensorStore.forEach(rec => renderSensor(rec));
}

async function fetchSensorCommunityData() {
    try {
        const response = await fetch('../sc-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();

        // 1. průchod: teplota/vlhkost podle location ID (jiný čip než prachový)
        const locationEnv = new Map();
        data.forEach(sensor => {
            let temp = null, hum = null;
            sensor.sensordatavalues.forEach(v => {
                if (v.value_type === 'temperature') temp = parseFloat(v.value);
                if (v.value_type === 'humidity') hum = parseFloat(v.value);
            });
            if (temp !== null || hum !== null) {
                locationEnv.set(sensor.location.id, { temperature: temp, humidity: hum });
            }
        });

        // 2. průchod: platné prachové senzory
        const scSensors = [];
        data.forEach(sensor => {
            if (sensor.location.indoor == 1) return;
            // Vadné jednotky řeší seznam blokací (sensor-bans.php) — upsertSensor filtruje.
            if (!sensor.location.latitude || !sensor.location.longitude) return;

            const values = {};
            sensor.sensordatavalues.forEach(v => {
                const num = parseFloat(v.value);
                if (isNaN(num)) return;
                if (v.value_type === 'P2') values.pm25 = num;
                else if (v.value_type === 'P1') values.pm10 = num;
                else if (v.value_type === 'P0') values.pm1 = num;
                else if (v.value_type === 'temperature') values.temperature = num;
                else if (v.value_type === 'humidity') values.humidity = num;
                else if (v.value_type === 'pressure') values.pressure = num;
            });
            if (values.pm25 === undefined && values.pm10 === undefined) return;

            const env = locationEnv.get(sensor.location.id);
            if (env) {
                if (values.temperature === undefined && env.temperature !== null) values.temperature = env.temperature;
                if (values.humidity === undefined && env.humidity !== null) values.humidity = env.humidity;
            }

            scSensors.push({
                sensorId: 'SC_' + sensor.sensor.id,
                lat: parseFloat(sensor.location.latitude),
                lng: parseFloat(sensor.location.longitude),
                values
            });
        });

        // 3. průchod: filtr odlehlých hodnot PM2.5 proti sousedům, pak uložit
        scSensors.forEach(s => {
            const pm25 = s.values.pm25;
            if (pm25 !== undefined) {
                if (pm25 > 500) {
                    console.warn(`[SC Outlier] ${s.sensorId}: ${pm25} μg/m³ exceeds absolute ceiling`);
                    return;
                }
                const neighbors = scSensors.filter(n => {
                    if (n.sensorId === s.sensorId || n.values.pm25 === undefined) return false;
                    const d = haversineKm(s.lat, s.lng, n.lat, n.lng);
                    return d >= 0.1 && d <= 30;
                });
                if (neighbors.length >= 2) {
                    const med = medianOf(neighbors.map(n => n.values.pm25));
                    const threshold = Math.max(60, med * 5);
                    if (pm25 > threshold) {
                        console.warn(`[SC Outlier] ${s.sensorId}: ${pm25} μg/m³ vs neighbor median ${med.toFixed(1)} (threshold ${threshold.toFixed(1)})`);
                        return;
                    }
                }
            }
            upsertSensor(s.sensorId, 'community', s.lat, s.lng,
                `Senzor #${s.sensorId.replace('SC_', '')}`, s.values);
        });
        return true;
    } catch (e) { console.error('SC Data Error', e); return false; }
}

async function fetchSmartCitizenData() {
    try {
        const response = await fetch('../sck-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const devices = await response.json();

        devices.forEach(device => {
            if (device.exposure !== 'outdoor') return;
            if (device.state !== 'has_published') return;
            if (!device.latitude || !device.longitude) return;

            const data = device.data || {};
            const values = {};
            const grab = (key, target) => {
                if (data[key] != null && !isNaN(parseFloat(data[key]))) values[target] = parseFloat(data[key]);
            };
            grab('89', 'pm25'); if (values.pm25 === undefined) grab('7', 'pm25');
            grab('87', 'pm1');
            grab('88', 'pm10');
            grab('53', 'noise');
            grab('55', 'temperature');
            grab('56', 'humidity');
            if (values.pm25 === undefined && values.noise === undefined) return;

            upsertSensor('SCK_' + device.id, 'sck',
                parseFloat(device.latitude), parseFloat(device.longitude),
                device.name || ('Smart Citizen #' + device.id), values);
        });
        return true;
    } catch (e) { console.error('SCK Data Error', e); return false; }
}

async function fetchPurpleAirData() {
    try {
        const response = await fetch('../pa-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const sensors = await response.json();

        sensors.forEach(sensor => {
            if (sensor.pm25 === null || sensor.pm25 === undefined) return;
            if (!sensor.latitude || !sensor.longitude) return;

            const pm25 = parseFloat(sensor.pm25);
            if (isNaN(pm25) || pm25 > 500) return;

            const values = { pm25 };
            if (sensor.pm10 != null && !isNaN(parseFloat(sensor.pm10))) values.pm10 = parseFloat(sensor.pm10);
            if (sensor.temperature != null) values.temperature = parseFloat(sensor.temperature);
            if (sensor.humidity != null) values.humidity = parseFloat(sensor.humidity);

            upsertSensor('PA_' + sensor.id, 'purpleair',
                parseFloat(sensor.latitude), parseFloat(sensor.longitude),
                sensor.name || ('PurpleAir #' + sensor.id), values);
        });
        return true;
    } catch (e) { console.error('PA Data Error', e); return false; }
}

/**
 * AirGradient — ag-proxy.php (CZ podmnožina veřejného světového feedu, cache 3 min).
 * Surové PM z Plantower bez korekce (veřejné API korigované pole nemá). Proxy už
 * vypustila senzory offline/starší 2 h; 30 min–2 h přijdou s online=false → šedý marker.
 */
async function fetchAirGradientData() {
    try {
        const response = await fetch('../ag-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();
        const sensors = Array.isArray(data.sensors) ? data.sensors : [];

        sensors.forEach(sensor => {
            if (!sensor.latitude || !sensor.longitude) return;
            const values = {};
            const pm25 = parseFloat(sensor.pm25);
            if (!isNaN(pm25) && pm25 <= 500) values.pm25 = pm25;
            const pm10 = parseFloat(sensor.pm10);
            if (!isNaN(pm10) && pm10 <= 1000) values.pm10 = pm10;
            if (values.pm25 === undefined && values.pm10 === undefined) return;
            if (sensor.pm1 != null) values.pm1 = parseFloat(sensor.pm1);
            if (sensor.temperature != null) values.temperature = parseFloat(sensor.temperature);
            if (sensor.humidity != null) values.humidity = parseFloat(sensor.humidity);
            if (sensor.co2 != null) values.co2 = parseFloat(sensor.co2);

            const meta = sensor.online === false ? { stale: true, ageMin: sensor.age_min } : null;
            upsertSensor('AG_' + sensor.id, 'airgradient',
                parseFloat(sensor.latitude), parseFloat(sensor.longitude),
                sensor.name || ('AirGradient #' + sensor.id), values, meta);
        });
        return true;
    } catch (e) { console.error('AG Data Error', e); return false; }
}

async function fetchLibcichData() {
    try {
        const response = await fetch('../libcich-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const sensors = await response.json();
        if (!Array.isArray(sensors)) throw new Error('not an array');

        const pts = [];
        sensors.forEach(sensor => {
            if (sensor.pm25 === null || sensor.pm25 === undefined) return;
            if (!sensor.latitude || !sensor.longitude) return;

            const pm25 = parseFloat(sensor.pm25);
            if (isNaN(pm25) || pm25 < 0 || pm25 > 500) return;

            const values = { pm25 };
            if (sensor.pm10 != null && !isNaN(parseFloat(sensor.pm10))) values.pm10 = parseFloat(sensor.pm10);
            if (sensor.pm1 != null && !isNaN(parseFloat(sensor.pm1))) values.pm1 = parseFloat(sensor.pm1);
            if (sensor.temperature != null) values.temperature = parseFloat(sensor.temperature);
            if (sensor.humidity != null) values.humidity = parseFloat(sensor.humidity);

            const lat = parseFloat(sensor.latitude);
            const lng = parseFloat(sensor.longitude);
            pts.push([lat, lng]);

            upsertSensor('LIBCICH_' + sensor.id, 'libcich', lat, lng, sensor.name || sensor.id, values);
        });

        if (pts.length) libcichBounds = L.latLngBounds(pts);
        if (typeof window.layers.libcich.refreshClusters === 'function') {
            window.layers.libcich.refreshClusters();
        }
        return true;
    } catch (e) { console.error('Libcich Data Error', e); return false; }
}

async function fetchChmiData() {
    const proxyBase = '../chmi-proxy.php';
    try {
        const metaRes = await fetch(`${proxyBase}?type=metadata`);
        if (!metaRes.ok) throw new Error(`Metadata HTTP Error: ${metaRes.status}`);
        const metaJson = await metaRes.json();

        const registryMap = new Map();
        const stations = new Map();
        const localities = metaJson?.data?.Localities || [];

        localities.forEach(locality => {
            if (locality.Active === false) return;
            const code = locality.LocalityCode;
            const name = locality.Name || code;
            const lat = locality.Localization?.LatAsNumber;
            const lon = locality.Localization?.LonAsNumber;
            if (!code || !lat || !lon) return;

            if (!stations.has(code)) {
                stations.set(code, { lat: parseFloat(lat), lon: parseFloat(lon), name: name, measurements: {} });
            }

            (locality.MeasuringPrograms || []).forEach(program => {
                (program.Measurements || []).forEach(m => {
                    const regId = m.IdRegistration;
                    let compCode = m.ComponentCode;
                    if (!regId || !compCode) return;
                    if (compCode === 'PM2_5') compCode = 'PM2.5';
                    if (['PM2.5', 'PM10', 'NO2', 'O3'].includes(compCode)) {
                        registryMap.set(String(regId), { stationCode: code, compCode: compCode });
                    }
                });
            });
        });

        const csvRes = await fetch(`${proxyBase}?type=data`);
        if (!csvRes.ok) throw new Error(`CSV HTTP Error: ${csvRes.status}`);
        const csvText = await csvRes.text();

        csvText.split('\n').forEach((line, index) => {
            if (index === 0 || !line.trim()) return;
            const cols = line.split(',').map(c => c.trim());
            if (cols.length < 4) return;

            const idReg = cols[0];
            const valType = parseInt(cols[2]);
            const value = parseFloat(cols[3].replace(/\r/g, ''));
            if ((valType !== 8 && valType !== 9) || isNaN(value)) return;

            const mapping = registryMap.get(idReg);
            if (mapping) {
                const st = stations.get(mapping.stationCode);
                if (st) st.measurements[mapping.compCode] = value;
            }
        });

        stations.forEach((station, code) => {
            const m = station.measurements;
            const values = {};
            if (m['PM2.5'] !== undefined) values.pm25 = m['PM2.5'];
            if (m['PM10'] !== undefined) values.pm10 = m['PM10'];
            if (m['NO2'] !== undefined) values.no2 = m['NO2'];
            if (m['O3'] !== undefined) values.o3 = m['O3'];
            if (Object.keys(values).length === 0) return;

            upsertSensor('CHMI_' + code, 'chmi', station.lat, station.lon, station.name, values);
        });
        return true;
    } catch (e) { console.error('Critical Error in fetchChmiData:', e); return false; }
}

/**
 * 3.1: meteorologické stanice ČHMÚ — chmi-meteo-proxy.php (otevřená 10min data,
 * podmnožina ~60 stanic, cache 15 min). Záznam nese JEN teplotu a vlhkost, proto
 * meta.meteoOnly: renderSensor ho u látek nekreslí ani jako prázdné kolečko
 * (to je jen pro stanice kvality ovzduší). Soubor ČHMÚ vychází hodinově, běžné
 * stáří je 10–85 min; proxy dá stale=true až po 2 h → šedý marker jako u AirGradientu.
 */
async function fetchChmiMeteoData() {
    try {
        const response = await fetch('../chmi-meteo-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();
        const stations = Array.isArray(data.stations) ? data.stations : [];

        stations.forEach(st => {
            if (!st.wsi || !st.lat || !st.lng) return;
            const values = {};
            const t = parseFloat(st.temperature);
            if (!isNaN(t)) values.temperature = t;
            const h = parseFloat(st.humidity);
            if (!isNaN(h)) values.humidity = h;
            if (values.temperature === undefined && values.humidity === undefined) return;

            const meta = { meteoOnly: true, elevation: st.elevation ?? null };
            if (st.stale) { meta.stale = true; meta.ageMin = st.age_min; }
            upsertSensor('CHMI_M_' + st.wsi, 'chmi', parseFloat(st.lat), parseFloat(st.lng),
                st.name || ('ČHMÚ ' + st.wsi), values, meta);
        });
        return true;
    } catch (e) { console.error('CHMI Meteo Data Error', e); return false; }
}

/**
 * 3.4: referenční stanice JINÝCH provozovatelů než ČHMÚ (zdravotní ústavy, kraje, města,
 * podniky — např. Ostrava-Radvanice ZÚ, Brno-Svatoplukova). Otevřená data ČHMÚ je
 * nemají; chmi-other-proxy.php je bere z hodinových vrstev map na chmi.cz (CC BY 4.0).
 * Zůstávají v síti 'chmi' (ID CHMI_<kód>, černý kroužek, váha 1.0), jen nesou
 * meta.owner do popupu. Kódy se s opendata nepřekrývají. Hodnota starší než 3 h
 * přijde se stale=true → šedý marker a hláška „Stanice neaktualizuje" jako u AirGradientu.
 */
async function fetchChmiOtherData() {
    try {
        const response = await fetch('../chmi-other-proxy.php');
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const data = await response.json();
        const stations = Array.isArray(data.stations) ? data.stations : [];

        stations.forEach(st => {
            if (!st.code || !st.lat || !st.lon) return;
            const values = {};
            ['pm25', 'pm10', 'no2', 'o3'].forEach(key => {
                const v = parseFloat(st.values && st.values[key]);
                if (!isNaN(v)) values[key] = v;
            });
            if (Object.keys(values).length === 0) return;

            const meta = { owner: st.owner || null };
            if (st.stale) { meta.stale = true; meta.ageMin = st.age_min; }
            upsertSensor('CHMI_' + st.code, 'chmi', parseFloat(st.lat), parseFloat(st.lon),
                st.name || ('ČHMÚ ' + st.code), values, meta);
        });
        return true;
    } catch (e) { console.error('CHMI Other Data Error', e); return false; }
}

// --- Markery (podle aktivní látky) ---
/**
 * Popup: pevná šířka (minWidth = maxWidth) — Leaflet jinak šířku odvozuje
 * z obsahu, takže bublina byla v pohledu „24 h + výhled" (dlouhá poznámka)
 * širší než v „5 dní" a při přepínání skákala; na mobilu se navíc otevírala
 * užší a po načtení grafu se roztáhla přes okraj displeje i s ×. AutoPan
 * drží bublinu pod lištou látek a legendou. Výška se řeší v CSS (vnitřní
 * scroll, hlavička sticky, box grafu s pevnou výškou).
 */
function popupOptions() {
    const mobile = window.innerWidth <= 768;
    const bar = document.getElementById('pollutantBar');
    const legend = document.querySelector('.legend');
    const barBottom = bar ? Math.ceil(bar.getBoundingClientRect().bottom) : 56;
    // Na desktopu drží autoPan bublinu i mimo legendu vpravo (dřív se schovávala pod ní);
    // na mobilu by to šířka displeje nedovolila — tam legenda sedí pod lištou,
    // takže se odsazuje shora pod ni (jinak sbalená legenda zakryje ×).
    const legendRect = legend ? legend.getBoundingClientRect() : null;
    const rightPad = (!mobile && legendRect) ? Math.ceil(legendRect.width) + 24 : 12;
    const topPad = (mobile && legendRect) ? Math.max(barBottom, Math.ceil(legendRect.bottom)) : barBottom;
    const width = mobile ? Math.min(360, window.innerWidth - 24) : 300;
    return {
        minWidth: width,
        maxWidth: width,
        autoPanPaddingTopLeft: L.point(12, topPad + 12),
        autoPanPaddingBottomRight: L.point(rightPad, mobile ? 48 : 16),
        keepInView: false,
    };
}

const VALUE_LABELS = {
    pm25: ['PM2.5', 'μg/m³'], pm10: ['PM10', 'μg/m³'], pm1: ['PM1', 'μg/m³'],
    no2: ['NO₂', 'μg/m³'], o3: ['O₃', 'μg/m³'], noise: ['Hluk', 'dB(A)'],
    // třetí prvek = počet desetinných míst ve štítku (výchozí 1); „45 %" a „426 ppm", ne „45.0 %"
    temperature: ['Teplota', '°C'], humidity: ['Vlhkost', '%', 0], pressure: ['Tlak', 'hPa', 0],
    co2: ['CO₂', 'ppm', 0],
};

function renderSensor(rec) {
    const cfg = POLLUTANTS[activePollutant];
    const rawVal = rec.values[activePollutant];
    const val = (rawVal === undefined || rawVal === null || isNaN(rawVal) || !plausible(activePollutant, rawVal)) ? null : rawVal;
    const isChmi = rec.network === 'chmi';
    // 3.1: meteostanice ČHMÚ (CHMI_M_) má jen teplotu/vlhkost — u látek se nekreslí vůbec
    const isChmiMeteo = isChmi && !!(rec.meta && rec.meta.meteoOnly);
    const group = window.layers[rec.network] || map;
    let marker = markers.get(rec.id);

    // Stanice ČHMÚ kvality ovzduší, které aktivní látku neměří, zůstávají viditelné
    // (prázdné kolečko) — oficiální síť má být vidět. Komunitní senzory bez hodnoty
    // se skryjí. Hluk ČHMÚ nedodává → skrýt; u teploty a vlhkosti se stanice kvality
    // ovzduší skryjí (hodnotu nemají) a místo nich jsou vidět meteostanice (3.1).
    const showHollow = isChmi && !isChmiMeteo && val === null && activePollutant !== 'noise' && !cfg.meteo;
    if (val === null && !showHollow) {
        if (marker && group.hasLayer(marker)) group.removeLayer(marker);
        return;
    }

    // Staré měření (meta.stale, zatím jen AirGradient): hodnota se ukáže, ale marker
    // je šedý a popup říká, jak je stará — nekreslíme barvu, která už neplatí.
    const isStale = !!(rec.meta && rec.meta.stale);
    const color = val === null ? 'transparent' : (isStale ? NO_DATA_COLOR : colorFor(activePollutant, val));
    const radius = val === null ? 8 : cfg.radius(val);

    let popupHtml = `<div class="popup-header">${escapeHtml(rec.name)}</div>`;
    popupHtml += `<div class="popup-content-body">`;

    popupHtml += `<div class="popup-hero">`;
    if (val !== null) {
        popupHtml += `
            <div class="popup-pm-value" style="color: ${color}">
                ${val.toFixed(cfg.decimals ?? 1)} <span style="font-size:16px; color:var(--text-muted)">${cfg.unit}</span>
            </div>
            <div class="popup-detail main-label">${cfg.heroLabel}</div>`;
        if (isStale) {
            const age = rec.meta.ageMin;
            const ageText = (age === undefined || age === null) ? 'déle než 30 min'
                : (age >= 60 ? `před ${Math.floor(age / 60)} h ${age % 60} min` : `před ${age} min`);
            popupHtml += `<div class="popup-age">${isChmi ? 'Stanice' : 'Senzor'} neaktualizuje — poslední měření ${ageText}</div>`;
        }
    } else {
        popupHtml += `<div class="popup-detail" style="font-style:italic; color:var(--text-faint); justify-content:center;">${cfg.short} zde neměřeno</div>`;
    }
    popupHtml += `</div>`;

    // dB(A) nikdo nepředpovídá — stejný důvod, proč se hluk neinterpoluje.
    // Teplotu/vlhkost výhled ČHMÚ pro kraje také nemá (jen látky).
    const shortLabel = (activePollutant === 'noise' || cfg.meteo) ? '24 hodin' : '24 h + výhled';
    popupHtml += `
        <div class="chart-range" id="range_${rec.id}">
            <button type="button" class="chart-range-btn${chartRange === '24h' ? ' active' : ''}" data-range="24h">${shortLabel}</button>
            <button type="button" class="chart-range-btn${chartRange === '5d' ? ' active' : ''}" data-range="5d">5 dní</button>
        </div>
        <div class="chart-container loading">
            <canvas id="chart_${rec.id}"></canvas>
            <div id="loading_${rec.id}" class="chart-status">Načítám historii…</div>
        </div>
        <div id="quality_${rec.id}" class="popup-quality">
            ⚠ Senzor vykazuje dlouhodobě konstantní/nízkou hodnotu – možné ucpání, nebo porucha ventilátoru
        </div>`;

    // Ostatní veličiny jako štítky vedle sebe (název hned u hodnoty). Řádek
    // „název … hodnota" přes celou šířku bubliny se na mobilu špatně četl.
    // Látky s pásmy dostanou tečku v barvě pásma a klik na ně přepne mapu
    // i popup na tu látku (stejné jako klik v liště); teplota apod. bez tečky.
    const chips = [];
    Object.keys(rec.values).forEach(key => {
        if (key === activePollutant) return; // už je v hero
        const meta = VALUE_LABELS[key];
        const num = rec.values[key];
        if (!meta || num === null || num === undefined || isNaN(num) || !plausible(key, num)) return;
        const shown = num.toFixed(meta[2] === undefined ? 1 : meta[2]);
        if (POLLUTANTS[key]) {
            chips.push(`<button type="button" class="popup-chip popup-chip-link" data-pollutant="${key}" title="Přepnout na ${meta[0]}">`
                + `<span class="popup-chip-dot" style="background:${colorFor(key, num)}"></span>`
                + `${meta[0]} <strong>${shown}</strong> ${meta[1]}</button>`);
        } else {
            chips.push(`<span class="popup-chip">${meta[0]} <strong>${shown}</strong> ${meta[1]}</span>`);
        }
    });
    if (chips.length) popupHtml += `<div class="popup-others" id="others_${rec.id}">${chips.join('')}</div>`;

    // 3.1: meteostanice ČHMÚ uvádí jiný zdroj než stanice kvality ovzduší + nadmořskou výšku
    let sourceLabel = NETWORK_LABELS[rec.network] || 'Sensor.Community';
    if (isChmiMeteo) {
        sourceLabel = NETWORK_LABELS.chmiMeteo;
        const elev = rec.meta.elevation;
        if (elev !== null && elev !== undefined && !isNaN(elev)) sourceLabel += `, ${Math.round(elev)} m n. m.`;
    } else if (isChmi && rec.meta && rec.meta.owner) {
        // 3.4: stanice jiného provozovatele zobrazovaná ČHMÚ — provozovatel do popupu
        sourceLabel += `, provozovatel stanice: ${escapeHtml(rec.meta.owner)}`;
    }
    popupHtml += `<div class="popup-source">Zdroj: ${sourceLabel}</div></div>`;

    const isPurpleAir = rec.network === 'purpleair';
    const isLibcich = rec.network === 'libcich';
    const isAirGradient = rec.network === 'airgradient';
    const borderWeight = (isChmi || isPurpleAir || isLibcich || isAirGradient) ? 2 : 1;
    // Černý kroužek ČHMÚ by na tmavých dlaždicích zmizel → světlý (3.2)
    const borderColor = showHollow ? '#999999'
        : (isChmi ? (isDarkTheme() ? '#e5e7eb' : '#000000') : (isPurpleAir ? '#7B2D8E' : (isLibcich ? '#0E7C86'
        : (isAirGradient ? '#1E40AF' : 'white'))));
    const fillOpacity = showHollow ? 0 : 0.8;

    // Obsah popupu jako DOM uzel, ne řetězec: popup.update() (autoPan po
    // načtení grafu) pak uzel jen znovu připojí a graf s posluchači přežije.
    const popupNode = document.createElement('div');
    popupNode.innerHTML = popupHtml;

    if (marker) {
        marker.setRadius(radius);
        marker.setStyle({ fillColor: color, weight: borderWeight, color: borderColor, fillOpacity: fillOpacity });
        marker.setPopupContent(popupNode);
        if (!group.hasLayer(marker)) group.addLayer(marker);
    } else {
        marker = L.circleMarker([rec.lat, rec.lng], {
            radius: radius, fillColor: color, color: borderColor, weight: borderWeight, opacity: 1, fillOpacity: fillOpacity,
            sensorId: rec.id
        });
        group.addLayer(marker);
        if (isChmi) marker.bringToFront();
        // Registrováno PŘED bindPopup, takže běží dřív než Leafletův otevírací
        // handler: první autoPan tak už počítá s aktuální polohou lišty a legendy
        // a se šířkou pro aktuální displej (bindPopup proběhl při načtení dat).
        marker.on('click', function () {
            const p = marker.getPopup();
            if (p) Object.assign(p.options, popupOptions());
        });
        marker.bindPopup(popupNode, popupOptions());
        markers.set(rec.id, marker);
    }

    marker.off('popupopen');
    marker.on('popupopen', function () {
        wirePopupChart(rec.id);
    });
    // Přepnutí látky přestaví HTML pod otevřeným popupem — graf je nutné nahrát znovu
    if (marker.isPopupOpen && marker.isPopupOpen()) {
        wirePopupChart(rec.id);
    }
}

/** Nahraje graf do čerstvě vykresleného popupu a zapojí přepínač rozsahu. */
function wirePopupChart(sensorId) {
    // Odsazení autoPan podle AKTUÁLNÍ polohy lišty a legendy (při bindPopup
    // ještě nemusela být rozložená); použije se při update() po načtení grafu.
    const m = markers.get(sensorId);
    const p = m && m.getPopup ? m.getPopup() : null;
    if (p) Object.assign(p.options, popupOptions());
    loadHistory(sensorId, chartRange);
    // Štítek jiné látky → přepnout mapu i popup na ni (setPollutant popup
    // překreslí a graf nahraje znovu, protože je otevřený).
    // Klik se nesmí probublat k mapě: překreslení popupu tlačítko odpojí z DOM
    // dřív, než událost dojde na kontejner mapy, Leaflet ji pak nepozná jako
    // klik uvnitř popupu a popup zavře (closePopupOnClick).
    const others = document.getElementById(`others_${sensorId}`);
    if (others) others.addEventListener('click', function (e) {
        const btn = e.target.closest('.popup-chip-link');
        if (!btn) return;
        e.stopPropagation();
        setPollutant(btn.dataset.pollutant);
    });
    const bar = document.getElementById(`range_${sensorId}`);
    if (!bar) return;
    // HTML popupu vzniká při vykreslení markeru, takže nese stav přepínače
    // z té doby: po volbě „5 dní" u jednoho senzoru měl další senzor graf
    // správně 5denní, ale modře svítilo „24 h + výhled" — a klik na něj se
    // kvůli kontrole `active` níže ignoroval. Podbarvení se proto srovná
    // podle skutečného rozsahu při každém otevření.
    bar.querySelectorAll('.chart-range-btn').forEach(b => b.classList.toggle('active', b.dataset.range === chartRange));
    bar.addEventListener('click', function (e) {
        const btn = e.target.closest('.chart-range-btn');
        if (!btn || btn.classList.contains('active')) return;
        chartRange = btn.dataset.range;
        localStorage.setItem('sv_chart_range', chartRange);
        bar.querySelectorAll('.chart-range-btn').forEach(b => b.classList.toggle('active', b === btn));
        loadHistory(sensorId, chartRange);
    });
}

// --- Předpověď ČHMÚ (kraj) ---
// CISORP prefix (kód výstražné oblasti z orp-areas.js) → kraj předpovědi (CZ-NUTS3).
// Zrcadlí KRAJ_BY_CISORP_PREFIX v lib/Forecast.php — záměrně duplikováno.
const FORECAST_KRAJ = {
    11: '19', 21: '27', 31: '35', 32: '43', 41: '51', 42: '60', 51: '78',
    52: '86', 53: '94', 61: '108', 62: '116', 71: '124', 72: '141', 81: '132',
};

/** Poloha senzoru → kód kraje předpovědi přes ORP polygony (alerts.js). */
async function forecastAreaFor(rec) {
    if (!rec || typeof ncLoadOrpAreas !== 'function' || typeof ncOrpAt !== 'function') return null;
    if (!await ncLoadOrpAreas()) return null;
    const cisorp = ncOrpAt(rec.lat, rec.lng);
    if (cisorp === null) return null; // mimo ČR
    return FORECAST_KRAJ[Math.floor(cisorp / 100)] || null;
}

/** Předpověď pro senzor, ukotvená na serveru. Nikdy nevyhazuje — bez předpovědi je normální stav. */
async function loadForecast(rec, apiType) {
    try {
        const area = await forecastAreaFor(rec);
        if (!area) return null;
        const res = await fetch(`/forecast-proxy.php?area=${encodeURIComponent(area)}`
            + `&type=${encodeURIComponent(apiType)}&sensor=${encodeURIComponent(rec.id)}`);
        if (!res.ok) return null;
        const fc = await res.json();
        return (fc && Array.isArray(fc.p50) && fc.p50.length) ? fc : null;
    } catch (e) {
        return null;
    }
}

const SV_DAY_CS = { Mon: 'po', Tue: 'út', Wed: 'st', Thu: 'čt', Fri: 'pá', Sat: 'so', Sun: 'ne' };
const svFmtHour = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Prague', hour: '2-digit', minute: '2-digit', hour12: false });
const svFmtDay = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Prague', weekday: 'short' });

/** Unix s → stejný popisek "14:00|Fri" jako get_history.php, plus příznak "fc". */
function forecastLabel(ts) {
    const d = new Date(ts * 1000);
    return `${svFmtHour.format(d)}|${svFmtDay.format(d)}|fc`;
}

/** Vystínuje polovinu grafu s předpovědí a nakreslí dělicí čáru „teď". */
function forecastZonePlugin(fromIndex) {
    return {
        id: 'forecastZone',
        beforeDatasetsDraw(chart) {
            const { ctx, scales: { x, y } } = chart;
            if (fromIndex <= 0 || fromIndex >= chart.data.labels.length) return;
            const edge = (x.getPixelForValue(fromIndex - 1) + x.getPixelForValue(fromIndex)) / 2;
            ctx.save();
            ctx.fillStyle = 'rgba(125,125,125,0.09)';
            ctx.fillRect(edge, y.top, x.right - edge, y.bottom - y.top);
            ctx.strokeStyle = 'rgba(125,125,125,0.6)';
            ctx.lineWidth = 1;
            ctx.setLineDash([]);
            ctx.beginPath();
            ctx.moveTo(edge, y.top);
            ctx.lineTo(edge, y.bottom);
            ctx.stroke();
            ctx.fillStyle = 'rgba(125,125,125,0.85)';
            ctx.font = '9px Inter, sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('VÝHLED', edge + 4, y.top + 9);
            ctx.textAlign = 'right';
            ctx.fillText('teď', edge - 4, y.top + 9);
            ctx.restore();
        }
    };
}

/**
 * Obsah popupu po otevření roste (načte se graf, poznámka, varování) — autoPan
 * ale běží jen při otevření, takže bublina odrostla nahoru pod lištu a × zmizelo.
 * Po každé změně obsahu se proto přepočítá rozložení i posun mapy.
 */
function refreshPopupLayout(sensorId) {
    const m = markers.get(sensorId);
    if (m && m.isPopupOpen && m.isPopupOpen()) {
        const p = m.getPopup();
        if (p && p.update) p.update();
    }
}

// --- Graf historie: 24 h + výhled ČHMÚ (výchozí) | 5 dní (surové body) ---
let historySeq = 0; // rychlé přepínání pohledů: platí jen poslední požadavek
async function loadHistory(sensorId, range) {
    range = range === '5d' ? '5d' : '24h';
    const statusEl = document.getElementById(`loading_${sensorId}`);
    if (!statusEl) return;
    const seq = ++historySeq;

    const cfg = POLLUTANTS[activePollutant];
    const chartCanvas = document.getElementById(`chart_${sensorId}`);
    const box = chartCanvas.parentElement;
    // Box grafu drží pevnou výšku i během načítání, takže se popup při
    // otevření ani při přepnutí pohledu nepřeskládává (mapa neposkakuje).
    // `empty` = bez dat, box se stáhne na jeden řádek se zprávou.
    const showStatus = (text, empty) => {
        box.classList.add('loading');
        box.classList.toggle('empty', !!empty);
        statusEl.textContent = text;
        statusEl.style.display = '';
    };
    if (activeChart) {
        activeChart.destroy();
        activeChart = null;
    }
    showStatus('Načítám historii…', false);

    // 24h pohled: hodinové sloty, aby historie a předpověď sdílely jednu časovou
    // mřížku — osa x je kategorická, 5min body vedle hodinových by budoucnost
    // roztáhly dvanáctkrát.
    const hourly = range === '24h';
    const rec = sensorStore.get(sensorId);
    const histUrl = `/historygraph/get_history.php?id=${encodeURIComponent(sensorId)}&type=${encodeURIComponent(cfg.apiType)}`
        + (hourly ? '&hours=24&step=hour' : '');

    try {
        const [response, fcRaw] = await Promise.all([
            fetch(histUrl),
            (hourly && activePollutant !== 'noise' && !cfg.meteo) ? loadForecast(rec, cfg.apiType) : Promise.resolve(null),
        ]);
        if (!response.ok) throw new Error('API Error');
        const data = await response.json();
        if (seq !== historySeq) return; // mezitím přišel novější požadavek

        const qualityEl = document.getElementById(`quality_${sensorId}`);
        if (data.quality && qualityEl) {
            if (data.quality.stale) {
                const hrs = data.quality.latest_age_sec ? Math.floor(data.quality.latest_age_sec / 3600) : null;
                qualityEl.innerHTML = '⚠ Zdroj momentálně neaktualizuje data'
                    + (hrs ? ` (poslední hodnota je stará ${hrs} h)` : '')
                    + ' – graf nemusí ukazovat aktuální stav.';
                qualityEl.style.display = 'block';
            } else if (data.quality.plateau_high || data.quality.stuck) {
                qualityEl.style.display = 'block';
            } else {
                qualityEl.style.display = 'none';
            }
        }

        if (!data.labels || data.labels.length === 0) {
            showStatus('Historie zatím není k dispozici.', true);
            return;
        }

        // --- Napojit předpověď na konec měřené řady ---------------------------
        const labels = data.labels.slice();
        const measured = data.data.slice();
        const nHist = labels.length;
        let fc = null;

        if (fcRaw && hourly && data.t0) {
            // Zahodit hodiny předpovědi, které měřená část už pokrývá, a vzít
            // jich tolik, kolik je měřená část široká — symetrický graf 24+24.
            const firstFree = data.t0 + nHist * data.step;
            const skip = Math.max(0, Math.round((firstFree - fcRaw.t0) / fcRaw.step));
            const take = Math.min(nHist, fcRaw.p50.length - skip);
            if (take > 0) {
                fc = {
                    ...fcRaw,
                    t0: fcRaw.t0 + skip * fcRaw.step,
                    p25: fcRaw.p25.slice(skip, skip + take),
                    p50: fcRaw.p50.slice(skip, skip + take),
                    p75: fcRaw.p75.slice(skip, skip + take),
                };
                for (let i = 0; i < take; i++) {
                    labels.push(forecastLabel(fc.t0 + i * fc.step));
                    measured.push(null);
                }
            }
        }

        // Hodinový režim vrací vždy 24 slotů včetně prázdných — „bez dat" se musí
        // poznat podle hodnot, jinak by senzor bez historie nakreslil prázdný graf.
        if (!fc && data.data.every(v => v === null)) {
            showStatus(hourly ? 'Posledních 24 h bez měření.' : 'Historie zatím není k dispozici.', true);
            return;
        }

        const nulls = (count) => new Array(count).fill(null);
        const pad = (arr) => nulls(nHist).concat(arr);

        box.classList.remove('loading', 'empty');
        statusEl.style.display = 'none';

        const ctx = chartCanvas.getContext('2d');
        // Výplň zelená → žlutá → červená přes skutečnou plochu grafu. Dřív byl
        // přechod na pevných 180 px: na mobilu (graf 150 px, legenda nahoře) tak
        // spodek plochy ležel už ve žluté a zelená se nikdy nevykreslila.
        let gradientCache = null;
        const areaGradient = (context) => {
            const { chartArea } = context.chart;
            if (!chartArea) return 'rgba(244, 211, 94, 0.5)';
            if (!gradientCache || gradientCache.top !== chartArea.top || gradientCache.bottom !== chartArea.bottom) {
                const g = context.chart.ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                g.addColorStop(0, 'rgba(135, 193, 89, 0.5)');
                g.addColorStop(0.3, 'rgba(244, 211, 94, 0.5)');
                g.addColorStop(0.6, 'rgba(230, 74, 69, 0.5)');
                gradientCache = { top: chartArea.top, bottom: chartArea.bottom, g };
            }
            return gradientCache.g;
        };

        // Gradient zelená→červená říká „nahoře je špatně" — u teploty a vlhkosti
        // neplatí, proto jednobarevná slabá výplň.
        // 3.2: v tmavém režimu brandová modř na tmavém plátně nejde vidět → světlá indigo.
        const dark = isDarkTheme();
        const lineColor = dark ? '#A5B4FC' : '#21275F';
        const datasets = [{
            label: cfg.short,
            data: measured,
            borderColor: lineColor,
            backgroundColor: cfg.meteo ? (dark ? 'rgba(165, 180, 252, 0.12)' : 'rgba(33, 39, 95, 0.08)') : areaGradient,
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 4,
            fill: true,
            tension: 0.4,
            order: 2
        }];

        const bandLabel = 'Rozpětí v kraji';
        const forecastLabelText = 'Výhled';
        if (fc) {
            // p25 první, p75 druhé: fill '-1' na p75 vystínuje pás mezi nimi.
            datasets.push({
                label: bandLabel, data: pad(fc.p25),
                borderWidth: 0, pointRadius: 0, pointHoverRadius: 0,
                fill: false, tension: 0.35, order: 4
            });
            datasets.push({
                label: bandLabel + '_hi', data: pad(fc.p75),
                borderWidth: 0, pointRadius: 0, pointHoverRadius: 0,
                backgroundColor: 'rgba(33, 39, 95, 0.12)',
                fill: '-1', tension: 0.35, order: 4
            });
            datasets.push({
                label: forecastLabelText, data: pad(fc.p50),
                borderColor: lineColor,
                borderWidth: 2,
                borderDash: [5, 4],
                pointRadius: 0,
                pointHoverRadius: 4,
                fill: false,
                tension: 0.35,
                order: 3
            });
        }

        const limitLabel = cfg.limitLine ? cfg.limitLine.label : null;
        if (cfg.limitLine) {
            datasets.push({
                label: limitLabel,
                data: new Array(labels.length).fill(cfg.limitLine.value),
                borderColor: 'rgba(100, 100, 100, 0.8)',
                borderWidth: 1,
                borderDash: [10, 5],
                pointRadius: 0,
                pointHoverRadius: 0,
                fill: false,
                tension: 0,
                order: 1
            });
        }
        const dayMap = { Mon: 'Po', Tue: 'Út', Wed: 'St', Thu: 'Čt', Fri: 'Pá', Sat: 'So', Sun: 'Ne' };

        activeChart = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { bottom: 16 } },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: !!limitLabel,
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 20,
                            usePointStyle: false,
                            font: { size: 10 },
                            ...(dark ? { color: '#9ca3af' } : {}),
                            filter: item => item.text === limitLabel
                        }
                    },
                    tooltip: {
                        filter: function (tooltipItem) {
                            const label = tooltipItem.dataset.label;
                            // Null řádky by byly prázdné — a půlka grafu je null na
                            // každé straně „teď". Výjimka: výpadek dat nese titulek
                            // „Chybí data". Pás je popsán v řádku výhledu, mlčí.
                            const raw = tooltipItem.chart.data.labels[tooltipItem.dataIndex] || '';
                            const keepNull = raw.split('|')[2] === 'gap';
                            return (keepNull || tooltipItem.parsed.y !== null)
                                && label !== limitLabel && label !== bandLabel && label !== bandLabel + '_hi';
                        },
                        callbacks: {
                            title: function (tooltipItems) {
                                if (!tooltipItems.length) return '';
                                const item = tooltipItems[0];
                                const raw = item.chart.data.labels[item.dataIndex]; // "14:30|Mon" | "…|gap" | "…|fc"
                                const parts = raw.split('|');
                                if (parts[2] === 'gap') {
                                    const range = parts[0].replace(/\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b/g, d => dayMap[d]);
                                    return 'Chybí data: ' + range;
                                }
                                const when = (dayMap[parts[1]] || parts[1]) + ' ' + parts[0];
                                return parts[2] === 'fc' ? when + ' · výhled' : when;
                            },
                            label: function (context) {
                                if (context.parsed.y === null) return '';
                                const v = context.parsed.y.toFixed(cfg.decimals ?? 1) + ' ' + cfg.unit;
                                if (context.dataset.label === forecastLabelText && fc) {
                                    return 'Model: ' + v + (fc.anchored ? ' · přepočteno' : '');
                                }
                                return (context.dataset.label || '') + ': ' + v;
                            }
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    y: {
                        // Hluk a teplota: nula jako spodek osy by průběh slisovala.
                        beginAtZero: activePollutant !== 'noise' && !cfg.meteo,
                        ...(cfg.chartMin !== null && cfg.chartMin !== undefined ? { suggestedMin: cfg.chartMin } : {}),
                        ...(cfg.chartMax !== null && cfg.chartMax !== undefined ? { suggestedMax: cfg.chartMax } : {}),
                        grid: { color: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)' },
                        ticks: { font: { size: 9 }, ...(dark ? { color: '#9ca3af' } : {}) },
                        // Bez titulku osy: jednotka je v hero i v každém tooltipu
                        // a otočený popisek stojí ~14 px z úzkého grafu v popupu.
                        title: { display: false }
                    }
                }
            },
            plugins: fc ? [midnightPlugin, forecastZonePlugin(nHist)] : [midnightPlugin]
        });
    } catch (e) {
        console.error(e);
        if (seq === historySeq) showStatus('Chyba načítání grafu.', true);
    } finally {
        // I bez dat / při chybě: obsah se změnil, autoPan musí × udržet na displeji.
        if (seq === historySeq) refreshPopupLayout(sensorId);
    }
}
