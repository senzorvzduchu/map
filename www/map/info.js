/**
 * senzorvzduchu.cz — modal „Jak mapa funguje" (metodika, sítě, interpolace, vítr).
 * Statický český obsah bez uživatelského vstupu — bezpečné jako template literal.
 * Váhy sítí jsou záměrně duplikované s TRUST v interp.js — při změně upravit obojí.
 */

function openInfoModal() {
    const box = document.getElementById('modalBox');
    box.innerHTML = `
        <h2>Jak mapa funguje</h2>

        <div class="info-lead">
            Mapa spojuje šest senzorových sítí do jednoho pohledu na kvalitu ovzduší
            v ČR. Lišta nahoře přepíná veličinu (PM2.5, PM10, NO₂, O₃, hluk, teplota, vlhkost), barva
            senzoru odpovídá aktuální hodnotě, kliknutím se otevře graf s historií a výhledem.
            Barevná plocha mezi senzory je interpolace. U teploty a vlhkosti jsou referencí
            meteorologické stanice ČHMÚ.
        </div>

        <details class="info-sec" open>
            <summary>📡 Senzorové sítě</summary>
            <table class="info-table">
                <tr><th>Síť</th><th>Co to je</th><th>Váha</th></tr>
                <tr><td><strong>ČHMÚ</strong></td><td>Oficiální referenční stanice státní sítě. Měří PM2.5, PM10, NO₂ i O₃ certifikovanými analyzátory. Stanice, která zvolenou látku neměří, je na mapě jako prázdné kolečko. Teplotu a vlhkost dodává jiná sada stanic ČHMÚ: 61 meteorologických stanic (otevřená data ČHMÚ, 10minutové hodnoty, krajská a okresní města, horské stanice). Na mapě jsou referencí s černým okrajem; stanice kvality ovzduší se při volbě teploty nebo vlhkosti skryjí.</td><td>1.0</td></tr>
                <tr><td><strong>PurpleAir</strong></td><td>Komunitní optické senzory. Hodnoty PM2.5 jsou korigované vzorcem US EPA (Barkjohn 2021).</td><td>0.25</td></tr>
                <tr><td><strong>Smart Citizen</strong></td><td>Open-source stanice (Fab Lab Barcelona). Jediná síť s měřením <strong>hluku</strong> (MEMS mikrofon, dB(A)).</td><td>0.15</td></tr>
                <tr><td><strong>Sensor.Community</strong></td><td>Největší DIY síť v ČR — levné optické senzory PM2.5/PM10, které si lidé staví sami.</td><td>0.10</td></tr>
                <tr><td><strong>Projekt Libčich</strong></td><td>Hustá lokální LoRa síť v Libčicích nad Vltavou (<a href="https://libcich.cz/" target="_blank" rel="noopener">libcich.cz</a>), PM2.5/PM10. Na národním zoomu sloučená do jedné bubliny.</td><td>0.10</td></tr>
                <tr><td><strong>AirGradient</strong></td><td>Open-source monitory (<a href="https://www.airgradient.com/map/" target="_blank" rel="noopener">airgradient.com</a>), PM2.5/PM10 + CO₂, teplota, vlhkost. Surové hodnoty bez korekce. Senzor bez měření za posledních 30 minut je šedý. Data: AirGradient, licence CC BY-SA 4.0.</td><td>0.10</td></tr>
            </table>
            <p>Váha říká, jak moc daná síť ovlivňuje interpolační odhad (viz níže).
            Vlastní senzor si postavíte za cca 2&nbsp;000&nbsp;Kč — návod na
            <a href="https://senzorvzduchu.cz/senzory" target="_blank" rel="noopener">senzorvzduchu.cz/senzory</a>.</p>
        </details>

        <details class="info-sec">
            <summary>🧪 Co měříme</summary>
            <p><strong>PM2.5</strong> — částice do 2,5&nbsp;µm. Pronikají hluboko do plic
            i krevního oběhu; hlavní zdravotní riziko lokálních topenišť a kouře.</p>
            <p><strong>PM10</strong> — částice do 10&nbsp;µm. Prašnost z dopravy,
            stavebnictví a průmyslu.</p>
            <p><strong>NO₂</strong> — oxid dusičitý, hlavně z dopravy a spalování.
            Dráždí dýchací cesty; měří jej jen stanice ČHMÚ.</p>
            <p><strong>O₃</strong> — přízemní ozon, letní fotochemický smog.
            Vzniká ze spalin působením slunce, nejvyšší bývá za slunečných
            odpolední. Mimo města bývá vyšší než v jejich centrech, kde ho
            rozkládá NO z dopravy; měří jej jen stanice ČHMÚ.</p>
            <p><strong>Hluk</strong> — dB(A) ze stanic Smart Citizen. Hodnota platí
            pro nejbližší okolí stanice.</p>
            <p><strong>Teplota a vlhkost</strong> — referencí jsou meteorologické
            stanice ČHMÚ (černý okraj, budka 2&nbsp;m nad zemí, hodnota až hodinu
            stará). Ostatní body jsou čidla komunitních senzorů v krytu: na slunci
            ukazují o několik °C víc, vlhkost 95–100&nbsp;% bývá orosené čidlo.
            Hodnoty jsou orientační, srovnávejte s nejbližší stanicí ČHMÚ.
            U PurpleAir odečítáme podle výrobce 4,4&nbsp;°C a přičítáme 4&nbsp;%
            vlhkosti.</p>
        </details>

        <details class="info-sec">
            <summary>🎨 Barevná škála</summary>
            <p>Pásma pro PM2.5, PM10, NO₂ a O₃ jsou evropský index kvality
            ovzduší EEA (EAQI) po revizi z roku 2024, hodinové hodnoty; první
            dvě třídy odpovídají doporučením WHO 2021. Hluková pásma vycházejí
            z doporučení WHO pro hluk v prostředí.</p>
            <p>Teplota a vlhkost nemají zdravotní třídy, proto mají vlastní barvy:
            teplota modrá → červená s hranicemi 0, 10, 20, 25 a 30&nbsp;°C,
            vlhkost okrová → modrá s hranicemi 30, 50, 70, 85 a 95&nbsp;%.</p>
            <p>Čárkovaná čára v grafu je denní limit směrnice EU 2024/2881 platný
            od roku 2030: PM2.5 25, PM10 45, NO₂ 50&nbsp;µg/m³ (24 h), O₃ 120&nbsp;µg/m³
            (8h průměr). Graf ukazuje kratší průměry, čára je orientační.</p>
        </details>

        <details class="info-sec">
            <summary>🗺️ Interpolace</summary>
            <p><strong>Vítr odhad protahuje po směru proudění:</strong> u každého
            senzoru se vzorkuje aktuální vítr (Open-Meteo) a jeho vliv se natahuje
            po větru (až 2× při silném větru) a zkracuje proti větru. Za bezvětří
            je odhad kruhový.</p>
            <p>Dál než <strong>25&nbsp;km od nejbližšího senzoru se nekreslí nic</strong>
            (po větru až ~50&nbsp;km). Hluk, teplota ani vlhkost se neinterpolují. Sítě vypnuté v legendě
            se do odhadu nezapočítávají. Vrstvu vypnete v legendě („Interpolace").</p>
        </details>

        <details class="info-sec">
            <summary>💨 Vítr</summary>
            <p>Vrstva „Vítr" animuje aktuální vítr ve výšce 10&nbsp;m z modelu
            Open-Meteo na síti 15&nbsp;×&nbsp;12 bodů nad ČR, obnovuje se každou
            hodinu. Kurzor nad mapou ukáže směr a rychlost v daném místě.
            Stejný grid používá interpolace pro unášení odhadu po větru.</p>
        </details>

        <details class="info-sec">
            <summary>📈 Graf historie</summary>
            <p>Po kliknutí na senzor se zobrazí graf zvolené látky. Výchozí pohled
            je <strong>24 h historie + 24 h výhled</strong> („teď" uprostřed),
            přepínač „5 dní" ukáže surové body za pět dní bez předpovědi.
            V 24h pohledu je jeden bod za hodinu. Z měření dané hodiny se bere <strong>nejvyšší</strong>, ne průměr, aby krátká
            špička v grafu zůstala. U teploty a vlhkosti je to naopak průměr
            hodiny.</p>
            <p>Komunitní senzory ukládáme každých 15 minut, stanice kvality ovzduší
            ČHMÚ a Libčich jako hodinové průměry, meteorologické stanice ČHMÚ jednou za hodinu. Šedý pruh v grafu je výpadek dat. Popup upozorní,
            když senzor dlouhodobě hlásí konstantní hodnotu (možné ucpání) nebo
            když zdroj přestal aktualizovat.</p>
        </details>

        <details class="info-sec">
            <summary>🔮 Předpověď (výhled v grafu)</summary>
            <p>Pravá polovina grafu je <strong>modelová předpověď ČHMÚ</strong>
            na 24 hodin z otevřených dat ČHMÚ (pro každý kraj, pro PM2.5, PM10,
            NO₂ a O₃). Hluk, teplota ani vlhkost se nepředpovídají.</p>
            <p><strong>Proč předpověď přepočítáváme.</strong> ČHMÚ předpovídá
            medián za celý kraj včetně lesů a polí; městský senzor měří skoro
            vždy víc. Tvar předpovědi zachováváme, úroveň posouváme podle poměru
            senzor&nbsp;/&nbsp;model za poslední 3&nbsp;hodiny (omezeno na třetinu
            až trojnásobek). Bez čerstvých dat senzoru se poměr bere z měřené mapy
            ČHMÚ pro kraj, jinak ukážeme model beze změny.</p>
            <p><strong>Světlejší pás kolem čáry</strong> je rozpětí mezi čtvrtinou
            nejčistších a nejšpinavějších míst kraje, ne nejistota předpovědi.
            Spolehlivý je průběh (kdy se má zhoršit a polevit), absolutní čísla
            jsou orientační. Lokální zdroj v předpovědi není a být nemůže.</p>
        </details>

        <details class="info-sec">
            <summary>⚠️ Výstrahy ČHMÚ na kvalitu ovzduší</summary>
            <p>Lišta, která vyjede zleva, přebírá <strong>oficiální výstrahy
            Systému integrované výstražné služby ČHMÚ</strong> (open data, formát
            CAP). Zobrazujeme <strong>jen výstrahy na kvalitu ovzduší</strong>
            (smogová situace, prach, přízemní ozon, NO₂, SO₂), tak jak je ČHMÚ
            vydal.</p>
            <p>Když nějaká platí, mapa se zeptá na vaši polohu a ukáže jen výstrahy
            pro váš správní obvod (ORP) — hranice přebíráme z výstražné mapy ČHMÚ.
            Bez polohy zobrazí celostátní přehled. Poloha zůstává v prohlížeči,
            na server ji neposíláme. Když žádná výstraha na ovzduší neplatí, lišta
            se neukáže a na polohu se mapa neptá.</p>
            <p>Úplný přehled všech výstrah je na
            <a href="https://vystrahy-cr.chmi.cz/" target="_blank" rel="noopener">vystrahy-cr.chmi.cz</a>.</p>
        </details>

        <details class="info-sec">
            <summary>🛠️ Kontrola kvality a blokace senzorů</summary>
            <p>Komunitní senzory jsou levné a občas se rozbijí. Mapa proto:</p>
            <p>• <strong>zahazuje nesmyslné hodnoty</strong> — PM2.5 nad 500 µg/m³
            a senzory Sensor.Community s více než pětinásobkem mediánu sousedů
            do 30 km;</p>
            <p>• <strong>automaticky skrývá „zamrzlé" senzory</strong> — hodnota PM
            se 2 hodiny prakticky nemění nebo drží vysokou rovinu (ucpaný senzor).
            Skrytí se zruší, jakmile senzor zase kolísá;</p>
            <p>• <strong>automaticky skrývá senzory v interiéru</strong> — v noci
            (0–5 h) o víc než 6 °C teplejší a o 15 bodů sušší než nejbližší stanice
            ČHMÚ do 30 km, s denním rozkmitem teploty pod 4 °C. Hodnotí se denně
            znovu;</p>
            <p>• <strong>ručně blokuje</strong> senzory, které správce po ověření
            označí za vadné nebo v interiéru.</p>
            <p>Skrytý senzor chybí na mapě i v interpolaci. Stanice ČHMÚ se
            neblokují nikdy. Myslíte, že je nějaký senzor vadný? Napište nám.</p>
        </details>

        <div class="info-legal">
            Provozuje <a href="https://senzorvzduchu.cz" target="_blank" rel="noopener">Senzorvzduchu,&nbsp;z.s.</a>
            · <a href="https://senzorvzduchu.cz/zasady-ochrany-osobnich-udaju/" target="_blank" rel="noopener">Ochrana osobních údajů</a>
            · <a href="mailto:info@senzorvzduchu.cz">Kontakt</a>
        </div>

        <div class="btn-row">
            <button class="btn btn-primary" id="infoCloseBtn">Rozumím</button>
        </div>
    `;
    document.getElementById('modalOverlay').style.display = 'flex';
    document.getElementById('infoCloseBtn').addEventListener('click', closeModal);
}

window.addEventListener('load', function () {
    const chip = document.getElementById('infoChip');
    const link = document.getElementById('infoLink');
    if (chip) chip.addEventListener('click', openInfoModal);
    if (link) link.addEventListener('click', openInfoModal);
});
