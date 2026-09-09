/**
 * senzorvzduchu.cz — animovaná větrná vrstva (leaflet-velocity + Open-Meteo grid
 * z wind-proxy.php). Vypnutá ve výchozím stavu; zapíná se řádkem „Vítr" v legendě.
 * Když je grid starší než 3 h nebo plugin z CDN nenaběhl, vrstva se nenabízí.
 */

let windLayer = null;
let windDataLoaded = false;

async function loadWindLayer() {
    if (typeof L.velocityLayer !== 'function') {
        console.warn('leaflet-velocity plugin not loaded — wind layer disabled');
        return false;
    }
    try {
        const res = await fetch('../wind-proxy.php', { cache: 'no-cache' });
        if (!res.ok) return false;
        const data = await res.json();

        const refTime = data?.[0]?.header?.refTime;
        if (!refTime || (Date.now() - Date.parse(refTime)) > 3 * 3600 * 1000) {
            console.warn('Wind grid stale or missing refTime — hiding layer');
            return false;
        }

        windLayer = L.velocityLayer({
            displayValues: true,
            displayOptions: {
                velocityType: 'vítr',
                position: window.matchMedia('(max-width: 768px)').matches ? 'bottomleft' : 'bottomright',
                emptyString: 'bez dat',
                speedUnit: 'm/s',
                directionString: 'Směr',
                speedString: 'Rychlost',
            },
            data: data,
            maxVelocity: 20,
            velocityScale: 0.008,
            particleMultiplier: 1 / 250,
            colorScale: ['#93C5FD', '#60A5FA', '#3B82F6', '#1D4ED8', '#7C3AED'],
        });
        windDataLoaded = true;
        return true;
    } catch (e) {
        console.error('Wind layer error', e);
        return false;
    }
}

window.addEventListener('load', () => {
    const row = document.getElementById('windToggleRow');
    if (!row) return;

    row.classList.add('disabled');
    let windOn = false;
    let initTried = false;

    row.addEventListener('click', async () => {
        if (!windDataLoaded && !initTried) {
            initTried = true;
            const ok = await loadWindLayer();
            if (!ok) {
                row.style.opacity = '0.25';
                row.style.cursor = 'not-allowed';
                showToast('Větrná vrstva momentálně není k dispozici.', true);
                return;
            }
        }
        if (!windLayer) return;

        windOn = !windOn;
        if (windOn) {
            windLayer.addTo(map);
            row.classList.remove('disabled');
        } else {
            map.removeLayer(windLayer);
            row.classList.add('disabled');
        }
    });

    // Grid se obnovuje po hodině, dokud je stránka otevřená
    setInterval(async () => {
        if (windOn && windLayer) {
            try {
                const res = await fetch('../wind-proxy.php', { cache: 'no-cache' });
                if (res.ok) {
                    const data = await res.json();
                    windLayer.setData(data);
                }
            } catch (e) { /* ponechat starou animaci */ }
        }
    }, 3600 * 1000);
});
