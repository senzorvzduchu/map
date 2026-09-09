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
            Mapa spojuje pět senzorových sítí do jednoho pohledu na kvalitu ovzduší
            v ČR. Lišta nahoře přepíná látku (PM2.5, PM10, NO₂, O₃, hluk), barva
            senzoru odpovídá aktuální hodnotě, kliknutím se otevře graf s historií a výhledem.
            Barevná plocha mezi senzory je interpolace.
        </div>

        <details class="info-sec" open>
            <summary>📡 Senzorové sítě</summary>
            <table class="info-table">
                <tr><th>Síť</th><th>Co to je</th><th>Váha</th></tr>
                <tr><td><strong>ČHMÚ</strong></td><td>Oficiální referenční stanice státní sítě. Měří PM2.5, PM10, NO₂ i O₃ certifikovanými analyzátory. Stanice, která zvolenou látku neměří, je na mapě jako prázdné kolečko.</td><td>1.0</td></tr>
                <tr><td><strong>PurpleAir</strong></td><td>Komunitní optické senzory. Hodnoty PM2.5 jsou korigované vzorcem US EPA (Barkjohn 2021).</td><td>0.25</td></tr>
                <tr><td><strong>Smart Citizen</strong></td><td>Open-source stanice (Fab Lab Barcelona). Jediná síť s měřením <strong>hluku</strong> (MEMS mikrofon, dB(A)).</td><td>0.15</td></tr>
                <tr><td><strong>Sensor.Community</strong></td><td>Největší DIY síť v ČR — levné optické senzory PM2.5/PM10, které si lidé staví sami.</td><td>0.10</td></tr>
                <tr><td><strong>Projekt Libčich</strong></td><td>Hustá lokální LoRa síť v Libčicích nad Vltavou (<a href="https://libcich.cz/" target="_blank" rel="noopener">libcich.cz</a>), PM2.5/PM10. Na národním zoomu sloučená do jedné bubliny.</td><td>0.10</td></tr>
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
        </details>

        <details class="info-sec">
            <summary>🎨 Barevná škála</summary>
            <p>Pásma pro PM2.5, PM10, NO₂ a O₃ jsou evropský index kvality
            ovzduší EEA (EAQI) po revizi z roku 2024, hodinové hodnoty; první
            dvě třídy odpovídají doporučením WHO 2021. Hluková pásma vycházejí
            z doporučení WHO pro hluk v prostředí.</p>
            <p>Čárkovaná čára v grafu je denní limit směrnice EU 2024/2881 platný
            od roku 2030: PM2.5 25, PM10 45, NO₂ 50&nbsp;µg/m³ (24 h), O₃ 120&nbsp;µg/m³
            (8h průměr). Graf ukazuje kratší průměry, čára je orientační.</p>
        </details>

        <details class="info-sec">
            <summary>🗺️ Interpolace</summary>
            <p>Barevná plocha mezi senzory je <strong>odhad, ne měření</strong>.
            Počítá se metodou IDW (inverse distance weighting, mocnina&nbsp;2):
            každý senzor ovlivňuje okolí tím víc, čím je blíž, a jeho vliv se
            násobí vahou sítě (ČHMÚ&nbsp;1.0 &gt; PurpleAir&nbsp;0.25 &gt;
            Smart&nbsp;Citizen&nbsp;0.15 &gt; Sensor.Community a Libčich&nbsp;0.10).
            V okolí referenční stanice tak převáží její údaj i proti několika
            blízkým amatérským senzorům.</p>
            <p><strong>Vítr odhad protahuje po směru proudění:</strong> u každého
            senzoru se vzorkuje aktuální vítr (Open-Meteo) a jeho vliv se natahuje
            po větru (až 2× při silném větru) a zkracuje proti větru. Za bezvětří
            je odhad kruhový.</p>
            <p>Dál než <strong>25&nbsp;km od nejbližšího senzoru se nekreslí nic</strong>
            (po větru až ~50&nbsp;km). Hluk se neinterpoluje. Sítě vypnuté v legendě
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
            V 24h pohledu je jeden bod za hodinu. Z 5minutových měření dané
            hodiny se bere <strong>nejvyšší</strong>, ne průměr, aby krátká
            špička v grafu zůstala.</p>
            <p>Komunitní senzory ukládáme každých 5 minut, ČHMÚ a Libčich jako
            hodinové průměry. Šedý pruh v grafu je výpadek dat. Popup upozorní,
            když senzor dlouhodobě hlásí konstantní hodnotu (možné ucpání) nebo
            když zdroj přestal aktualizovat.</p>
        </details>

        <details class="info-sec">
            <summary>🔮 Předpověď (výhled v grafu)</summary>
            <p>Pravá polovina grafu je <strong>modelová předpověď ČHMÚ</strong>
            na 24 hodin z otevřených dat ČHMÚ (pro každý kraj, pro PM2.5, PM10,
            NO₂ a O₃). Hluk se nepředpovídá.</p>
            <p><strong>Proč předpověď přepočítáváme.</strong> ČHMÚ publikuje
            hodnotu za celý kraj — medián přes celé území včetně lesů a polí.
            Městský senzor proto skoro vždy měří víc, než říká krajské číslo,
            v Praze i trojnásobně. Zachováváme tvar předpovědi, ale její úroveň
            přepočítáme na to, co daný senzor právě měří: průměr senzoru za
            poslední 3 hodiny vydělíme hodnotou modelu za stejné 3 hodiny a celou
            křivku předpovědi tímto poměrem vynásobíme. Poměr je omezen na
            třetinu až trojnásobek, aby vadný senzor předpověď nezkreslil. Když
            senzor za poslední 3 hodiny nic nehlásil, poměr se spočítá z měřené
            mapy ČHMÚ pro stejný kraj; když není ani ta, ukážeme model beze změny.</p>
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
            a senzory Sensor.Community, které hlásí víc než pětinásobek mediánu
            sousedů do 30 km (min. 60 µg/m³);</p>
            <p>• <strong>automaticky skrývá „zamrzlé" senzory</strong> — když se
            hodnota PM za poslední 2 hodiny prakticky nemění (odchylka pod 0,5 při
            průměru nad 10 µg/m³) nebo drží vysokou rovinu (odchylka pod 3 při
            průměru nad 60 µg/m³, typicky ucpaný senzor). Skrytí se samo zruší,
            jakmile senzor zase kolísá;</p>
            <p>• <strong>ručně blokuje</strong> senzory, které správce po ověření
            označí za vadné nebo umístěné v interiéru.</p>
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
