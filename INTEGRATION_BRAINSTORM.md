# libcich.cz → integrace do mapy (2.5)

> **Stav 12. 6. 2026: SCHVÁLENO adminem (Lukáš Vyhlídka, Libčice.net) a IMPLEMENTOVÁNO v sandboxu — viz `TESTING.md`. Nenasazeno.**
>
> Odpovědi admina: (1) `devEid` = LoRa HW ID, dlouhodobě stabilní (`devId` se historicky recyklovalo → správně klíčujeme na devEid); poloha senzoru se může výjimečně změnit, ale v rámci dne je stabilní a při přesunu senzor nepublikuje. (2) Data po ~2 min, historie se zpětně nemění (kromě oprav porouchaných čidel); žádný rate-limit, naše cachování v pořádku. (3) Endpoint se seznamem senzorů zatím není, můžou doplnit. (4) Atribuce: uvádět „projekt Libčich / libcich.cz" s odkazem na https://libcich.cz/ — implementováno v popupu. Žádné další podmínky.
>
> Původní stav před implementací: Klíčová rozhodnutí (viz odznak ✅ v sekcích): živý marker z `/aq`, historie z `/aqi` přes náš kolektor, clustering přes Leaflet.markercluster izolovaně na libcich vrstvu + tlačítko „přiblížit na Libčice". V Libčicích nejsou žádné jiné naše senzory → bez geografického překryvu.

Brainstorm + technické předpoklady. Žádný produkční kód zatím — jen návrh, rizika a otázky.
Cíl: přidat ~50 senzorů sítě **libcich.cz** jako 5. zdroj do existující mapy, beze změny chování stávajících 4 sítí.

> Mapa je živá na webu → **maximální opatrnost**. Nový zdroj musí být plně izolovaný (vlastní proxy, vlastní layer group, vlastní fallback). Když libcich API spadne, zbytek mapy se nesmí ani zachvět.

---

## 1. Co o API víme (ověřeno dotazem)

Dva endpointy, oba vracejí **všechny senzory najednou**, časově bucketované. To je zásadní rozdíl oproti SC / PurpleAir, kde proxujeme síť po síti.

### `/api/aqi?year=&month=&day=` — hodinově agregovaný AQI
```jsonc
{
  "startTime": "2026-05-25T22:00:00Z",
  "endTime":   "2026-05-26T22:00:00Z",
  "intervals": {
    "2026-05-26T17:00:00Z": {
      "001C6320C7438A81": {
        "devId": "aq-0014",
        "pm10": 4.159, "pm2_5": 3.628,
        "pm10Aqi": 4, "pm2_5Aqi": 15,
        "latitude": 50.19762039, "longitude": 14.36266041,
        "tags": []
      }
      // … ~50–57 senzorů
    }
    // … hodinové intervaly
  }
}
```

### `/api/aq?year=&month=&day=&hour=` — surová měření po 2 minutách
```jsonc
{
  "startTime": "...", "endTime": "...",
  "intervals": {
    "2026-05-26T17:00:00Z": {
      "001C6320C7438A81": {
        "devEid": "001C6320C7438A81",
        "devId": "aq-0014",
        "battery": 4.12, "poweredOn": null,
        "temperature": 31.1, "humidity": 56.2,
        "pm1_0": 2, "pm2_5": 6, "pm10_0": 7,
        "windSpeed": null, "windDeg": null,   // větrná pole zatím null
        "latitude": 50.19762039, "longitude": 14.36266041,
        "annotation_code": ""
      }
    }
  }
}
```

**Klíčová pozorování:**
- **UTC timestampy** (`Z`). „Aktuální" hodnotu musíme počítat v UTC, ne v lokálním čase.
- Senzory jsou hustě u sebe kolem **50.19 N, 14.36 E** = Libčice nad Vltavou, severně od Prahy. To je hlavní UX problém (viz §4).
- `devEid` = HW EUI (stabilní). `devId` = lidský název (`aq-0014`), může se měnit → **klíč markeru = `devEid`, popisek = `devId`**.
- Dvě sady jednotek pole: `/aq` má `pm2_5` (raw µg/m³, malá čísla), `/aqi` má `pm2_5` + `pm2_5Aqi`. **Pozor na záměnu** — naše mapa barví podle PM2.5 µg/m³, ne podle AQI.
- Health signály zdarma: `tags` (`PMS_LOW_MEASUREMENT_NODE`), `annotation_code`, `battery`, `poweredOn` → využitelné pro indikaci poruchy (analogie k plateau detekci u SC).
- Žádný API klíč nepotřeba (zatím). Provider **preferuje, abychom tahali z jejich API** (ne scraping webu) → musíme cachovat slušně, nebombardovat.

---

## 2. Která data brát: `/aq` vs `/aqi`?

| | `/aq` (2 min) | `/aqi` (hodina) |
|---|---|---|
| Čerstvost | velmi vysoká | hodinový průměr |
| Teplota/vlhkost | **ano** | ne |
| PM1 | ano (`pm1_0`) | ne |
| Hluk dat | vyšší (spiky) | vyhlazený |
| Konzistence s naší mapou | mapa ukazuje „hodinové průměry" (viz indikátor) | **přesně sedí** |

**✅ ROZHODNUTO:**
- **Živý marker → `/aq`** (nejnovější 2-min interval) kvůli teplotě/vlhkosti/PM1 a čerstvosti.
- **Historie v popupu → `/aqi`** (hodinově agregované, čisté na graf), ukládané naším kolektorem (§5).

Důsledek pro proxy: jeden soubor `libcich-proxy.php` se dvěma režimy přes `?mode=` (default = `aq` živé pro mapu, `aqi` = poslední uzavřená hodina pro kolektor). Viz §3.1 a §5.

---

## 3. Architektura integrace (drží se vzoru 2.4)

Stávající vzor (`pa-proxy.php`, `sc-proxy.php` …) zopakovat 1:1:

```
prohlížeč (map/index.html + embed.html)
   └─ fetch ../libcich-proxy.php           ← NOVÝ
         ├─ spočítá aktuální UTC year/month/day/hour
         ├─ curl https://libcich.cz/api/aq?…   (timeout 15s)
         ├─ vybere nejnovější neprázdný interval
         ├─ normalizuje na tvar markeru {id,name,lat,lng,pm25,pm10,pm1,temp,hum,tags}
         ├─ uloží libcich_data_cache.json
         └─ fallback na starou cache při chybě
```

### 3.1 Nová proxy `www/libcich-proxy.php`
Zodpovědnosti (kopie patternu z `pa-proxy.php`), **dva režimy přes `?mode=`:**
- `?mode=aq` (default) → živá data pro mapu z `/api/aq` (nejnovější 2-min interval). Cache 120 s.
- `?mode=aqi` → poslední **uzavřená** hodina z `/api/aqi` pro kolektor historie (§5). Cache 600 s. Samostatný cache soubor.

1. `require config.php`, `Content-Type: application/json`, `Access-Control-Allow-Origin: ALLOWED_DOMAIN`.
2. **Cache** dle režimu (aq 120 s / aqi 600 s; oba slušné k jejich serveru).
3. Spočítat `gmdate('Y'/'m'/'d'/'H')` (UTC!) → sestavit URL dle režimu.
4. **Edge case na začátku hodiny:** aktuální hodinový bucket může být ještě prázdný → když `intervals` pro tuto hodinu chybí/prázdné, dotáhnout i předchozí hodinu (a u půlnoci předchozí den) a vybrat globálně nejnovější interval. **Nutno ošetřit přechod den/měsíc/rok.**
5. Z nejnovějšího intervalu projít senzory, normalizovat:
   - `id = 'LIBCICH_' + devEid`
   - `pm25 = pm2_5` (µg/m³, **ne** AQI)
   - validace: `pm25 != null`, `0 < pm25 <= 500`, lat/lng přítomné (stejné meze jako ostatní sítě).
   - `otherValues`: PM10, PM1, teplota, vlhkost.
   - příznak kvality z `tags` / `annotation_code` / `battery`.
6. `curl` (Active24 blokuje custom headers přes stream context — viz komentář v `pa-proxy.php`), `SSL_VERIFYPEER=true`, `USER_AGENT`.
7. `?debug=1` větev jako u `pa-proxy.php` (HTTP kód, raw preview) — zlatý pro ladění bez cache.
8. Fallback: HTTP != 200 nebo nevalidní JSON → `readfile` staré cache; když není → `502`.

**Výstupní tvar** (ať frontend zůstane triviální), pole objektů jako u PA:
```json
[{ "id":"001C6320C7438A81","name":"aq-0014","latitude":50.19,"longitude":14.36,
   "pm25":3.6,"pm10":4.2,"pm1":2.0,"temperature":31.1,"humidity":56,
   "tags":[],"confidence":"ok" }]
```

### 3.2 Frontend (`map/index.html` **i** `map/embed.html` — dvoufile pravidlo!)
Minimální, izolované změny:
- Přidat layer group: `window.layers.libcich = L.layerGroup().addTo(map)`.
- Přidat `fetchLibcichData()` do `Promise.all([...])` v `fetchAllData()`.
- Nový řádek v legendě (`data-layer="libcich"`) + nová `.src-…` třída (barva/okraj odlišující síť, např. plný kroužek značkové barvy).
- `createOrUpdateMarker(id, lat, lng, pm25, …, { type:'libcich', name, otherValues })`.
- V `createOrUpdateMarker` přidat větev pro `type==='libcich'` u `sourceText`, `borderColor`, `borderWeight` (drobné rozšíření switche).

> **Dvoufile pravidlo (CLAUDE.md §📝):** každá frontend změna musí jít do `index.html` **i** `embed.html`. Před hotovo ověřit oba.

---

## 4. ⚠️ Hlavní problém: 50 senzorů v husté oblasti

Při národním zoomu (mapa startuje `z8`, celá ČR) se ~50 senzorů v ~5 km městečku slije do jednoho barevného fleku — překryté `circleMarker`y, neklikatelné, vizuálně to vypadá jako „rozbitá" mapa. Stávající mapa **clustering nemá** (4 sítě jsou po ČR rozprostřené řídce). Toto je nová situace.

Možnosti (od nejlehčí po nejrobustnější):

1. **Leaflet.markercluster** (plugin) — standard. Sloučí husté body do clusteru s počtem, na zoom se rozpadnou. Náklad: další JS dep + `circleMarker` clustering vyžaduje obal (`L.marker` nebo `L.divIcon`). Pozor na záměnu vizuálního stylu vůči zbytku mapy. Lze aplikovat **jen na libcich vrstvu**, ostatní nechat beze změny.
2. **Spiderfy bez clusteringu** — `OverlappingMarkerSpiderfier`: při kliku na shluk se markery „rozpavoučí". Drží stávající vzhled bodů.
3. **Agregovaný „obecní" marker** — vlastní logika: při `zoom < N` zobrazit jeden marker pro Libčice s průměrem/medianem sítě, popisek „Libčice (50 senzorů)"; při `zoom >= N` rozbalit jednotlivé. Žádná dependence, plná kontrola vzhledu, ladí s brandem. Víc kódu.
4. **Nedělat nic, spolehnout na zoom** — uživatel musí dojet blízko. Nejlevnější, ale na národním pohledu vypadá špatně. ❌ nedoporučeno.

**✅ ROZHODNUTO: #1 Leaflet.markercluster, aplikovaný IZOLOVANĚ jen na libcich vrstvu.** Ostatní 4 sítě zůstávají beze změny (žádný clustering). Markercluster vyžaduje obal markeru (`L.marker`/`L.divIcon`) — vytvoříme `divIcon` ve stylu našich `circleMarker`ů, ať vizuál ladí s brandem. Plugin (`leaflet.markercluster` JS+CSS) přidat přes CDN vedle Leafletu.

**✅ + tlačítko / legend-klik „přiblížit na Libčice"** (`map.fitBounds` na bbox sítě) — protože síť je lokální, uživatel se na ni dostane jedním kliknutím z národního pohledu.

---

## 5. Historie pro graf v popupu

Dvě cesty:

**✅ ROZHODNUTO: A) Náš stávající kolektor** (`historygraph/collect.php` → SQLite, 5 dní). Že se historie naplní až ~5 dní po nasazení = **nevadí**.

Provedení (konzistentní s §2 — historie z `/aqi`):
- Přidat 5. blok do `collect.php`: tahnout `libcich-proxy.php?mode=aqi` (poslední uzavřená hodina, hodinově agregovaná), insert `LIBCICH_<devEid>` PM2.5 (+PM10).
- `get_history.php` funguje **beze změny** (je generický nad `sensor_id`).
- Kolektor běží na `CRON_TOKEN` — **žádná změna bezpečnosti**.

**Plus:** jednotná 5-denní historie jako u ostatních sítí, graf „prostě funguje", a díky `mode=aqi` jsou to čisté hodinové průměry (ne 2-min snímky).

---

## 6. Bezpečnost / provoz

- **Žádné nové tajemství** (API bez klíče) — `config.php` se nemění. Pokud by později klíč přibyl, jde do `config.php` (nikdy do gitu; viz CLAUDE.md §🔒).
- **Slušné cachování** (provider preferuje API pull): proxy cache 120–300 s, kolektor po hodině. Netáhat per-request.
- **Plná izolace:** `try/catch` kolem `fetchLibcichData()`, vlastní cache soubor, vlastní layer group → výpadek libcich neovlivní SC/ČHMÚ/PA/SCK.
- **Nasazení = ruční FTP** (CLAUDE.md §📤). Git je jen source-of-truth. Nové soubory k uploadu: `libcich-proxy.php` (+ úpravy `map/index.html`, `map/embed.html`, případně `historygraph/collect.php`).

---

## 7. Návrh kroků implementace (až po odsouhlasení)

1. Scaffolding 2.5: zkopírovat 2.4 jako základ (bez `config.php`, bez `.git` historie dle uvážení).
2. `www/libcich-proxy.php` se dvěma režimy (`?mode=aq` / `?mode=aqi`) + `?debug=1`, ověřit normalizaci proti živému API.
3. Frontend: layer group + `fetchLibcichData()` + legenda + větev v `createOrUpdateMarker` — do **obou** HTML.
4. Clustering: Leaflet.markercluster (CDN) izolovaně na libcich vrstvu + tlačítko „přiblížit na Libčice" (`fitBounds`).
5. Historie: 5. blok v `collect.php` tahnoucí `libcich-proxy.php?mode=aqi` (§5).
6. Test: `debug=1`, přechod hodiny/dne (UTC), výpadek API (fallback na cache), překryv/clustering markerů na různých zoomech.
7. Ruční FTP nasazení dle checklistu.

---

## 8. Otevřené otázky

**Interní — ✅ vyřešeno:**
1. ~~Marker 2-min vs hodinový~~ → `/aq` živě, `/aqi` historie (§2).
2. ~~Clustering~~ → Leaflet.markercluster izolovaně na libcich vrstvu + „přiblížit na Libčice" (§4).
3. ~~Historie~~ → náš kolektor, `mode=aqi` (§5).
4. ~~Překryv s ČHMÚ/SC v Libčicích~~ → **v Libčicích žádné jiné naše senzory nejsou**, bez geografického clash.

**Pro admina libcich (→ samostatný e-mail, viz `EMAIL_ADMIN_LIBCICH.md`):**
- Je `devEid` garantovaně stabilní ID senzoru?
- Reálná perioda aktualizace dat a doporučená cache doba / rate-limit?
- Existuje endpoint se seznamem senzorů (roster/bbox), ať nemusíme číst časové buckety jen kvůli soupisce?
- Plánuje se naplnění `windSpeed`/`windDeg`?
- Přání ke způsobu uvedení zdroje (atribuce „libcich.cz") a podmínky užití.

---

## Příloha: mapování polí libcich → marker mapy

| marker pole | `/aq` zdroj | `/aqi` zdroj | pozn. |
|---|---|---|---|
| `id` | `devEid` | `devEid` | klíč markeru, prefix `LIBCICH_` |
| `name` | `devId` | `devId` | popisek (`aq-0014`) |
| `pm25` | `pm2_5` | `pm2_5` | µg/m³, barva markeru |
| `pm10` | `pm10_0` | `pm10` | ⚠️ jiný název pole mezi endpointy |
| `pm1` | `pm1_0` | — | |
| `temperature` | `temperature` | — | jen v `/aq` |
| `humidity` | `humidity` | — | jen v `/aq` |
| AQI | — | `pm2_5Aqi`,`pm10Aqi` | nepleť s µg/m³ |
| health | `annotation_code`,`battery` | `tags` | indikace poruchy |
