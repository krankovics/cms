# comesy
**Content. Media. System.**  
**One platform. Every story.**

Moduláris, PHP/MySQL-alapú, több webhelyet kezelő szerkesztőségi CMS. A márkanév mindig **comesy** (kisbetűvel).

## Adminfelület v2

- [Admin UI v2 design system](docs/admin-design-system.md): modern SaaS layout, breakpointok, komponensek, világos/sötét színek és hozzáférhetőség.
- [Admin redesign backlog](docs/admin-redesign-backlog.md): oldalszintű feladatok, sprint-sorrend, elfogadási feltételek és regressziós kapuk.

Az adminfelület modulonként elkészült. A listák, szerkesztőpanelek, navigáció és szerepkörös működés reszponzív kialakítása élesíthető, tesztkapus GitHub Actions telepítéssel.

## Működő adminisztrációs részek

- Felhasználói bejelentkezés, profil, szuperadmin és szerkesztőségi szerepkörök
- Webhelyek, webhelyenkénti szerkesztési és olvasási jogosultságok
- Cikkszerkesztő: gazdag szöveg, médiafeltöltés, revíziók, kategóriák, címkék, SEO, kiemelt kép, mobil meta-accordionok és mentetlen módosítások védelme
- Cikkstátuszok: piszkozat, ellenőrzésre vár, publikált
- Egy cikk hozzárendelése több engedélyezett webhelyhez: közös tartalom és státusz
- Globális (site_id=0) és helyi kategóriák/címkék. A meglévő globális fogalmakat az új címkék létrehozásakor újrahasználja
- Médiatár: hozzáférés-alapú böngészés, feltöltési előrehaladás, képrészletező panel, alt szöveg mentése

**Korlát:** A többwebhelyes hozzárendelés a comesy CMS adatmodelljében működik. A publikált tartalom külső webhelyekre történő továbbítása külön frontend/API-integrációt igényel. A globális és helyi régi duplikátumokat nem egyesíti automatikusan.

## A teljes kiadói termékspecifikáció

Az [eredeti, 2026. október 14-i végleges kiadói CMS specifikáció](https://docs.google.com/document/d/1uUXQl5UVmW6DoojF6-OS93wG6sRZAbT-h58Ay6ynTes/edit) nemcsak az adminfelületi redesignról szól. A [teljes scope és hiányelemzés](docs/full-product-scope.md) külön nyilvántartja a hirdetéskezelést, importot, vizuális oldalépítőt, publikus frontendet, szerkesztőségi workflow-t, infrastruktúrát és API-t. **A teljes termék nincs kész.**

### Hirdetésadmin, első működő szelet

- `/ads.php`: hirdetők, kampányok, zónák, kreatívok, kezdő/végdátum, prioritás, rovat, státusz és egyszerű statisztikák.
- `/ad-slot.php?site_id=1&zone=article-top`: nyilvános, iframe-es aktív kreatív, cookie nélküli zónakérésszámlálás.
- `/ad-click.php?id=1`: csak aktív kreatív cél URL-jére mutató kattintási átirányítás.
- Szerepkörök és audit. A teljes adserver, viewability/frequency cap és bevétel-elszámolás még fejlesztendő.

### Importközpont, első működő szelet

- `/imports.php`: egy URL, RSS/Atom, sitemap, sitemapből teljes oldal felderítése, max. 10 cikkes demó feladatsor.
- Tartós MySQL queue, robots-ellenőrzés, SSRF-védelem, HTML → szerkeszthető blokkok, draft only, forráspillanatkép, idempotens feldolgozás, hibakezelés és újrapróbálás.
- A PHP worker **külön indítandó a szerveren** (cron vagy supervisor). Példa cron, a telepítésen ellenőrizendő PHP CLI elérési úttal:

```cron
* * * * * /bin/sh /home/appwrkrft/domains/cms.8media.hu/public_html/import-worker.sh --max=5 >> /home/appwrkrft/comesy-import.log 2>&1
```

A cron a `import-worker.sh` indítószkriptet használja, mert a DirectAdmin `/usr/bin/php` parancsa régebbi PHP-verzióra mutathat, amely elhasal a PHP 8-as `?->`, `match` és `str_contains` szintaxison. Az indító először PHP 8.3/8.4/8.2/8.1 CLI-ból választ, ellenőrzi a PDO MySQL, cURL, DOM, mbstring és iconv bővítményeket, majd csak ezután indítja a feldolgozást. Az importot **nem** indító ellenőrzés:

```sh
/bin/sh /home/appwrkrft/domains/cms.8media.hu/public_html/import-worker.sh --check
```

Ha nincs megfelelő PHP, a hiba a `/home/appwrkrft/comesy-import.log` naplóba kerül. A DirectAdmin cronparancsát kézzel kell átállítani, az FTP-telepítés ezt nem teszi meg. A sitemapek és RSS-folyamok feldolgozásakor külön, legfeljebb **32 MiB-os** forrásletöltés engedélyezett, XMLReader-rel történő streaming feldolgozással. A HTML-cikkek 3 MiB-os korlátja megmarad. A túl méretes forrásokat kisebb sitemapokra kell bontani vagy egyesével hozzáadni, nem szabad a limitet korlátlanul növelni.

A cikkimport a cikk belső HTML-elemeiből dolgozik, de ha ezekből nem áll össze teljes tartalom, ellenőrzött schema.org `Article`/`NewsArticle`/`BlogPosting` `articleBody` mezőt is megpróbál feldolgozni. A rövid, csak leadet tartalmazó JSON-LD nem számít teljes cikknek. A hiányos forrásokat a worker `Cikk kihagyva` állapotra állítja, az okot rögzíti, és **nem próbálja újra ugyanazzal a forrással háromszor**. A meglévő `failed` tételekhez az importközpontban egyszeri „Sikertelenek újrapróbálása” művelet használható. Fontos, hogy a korábban már `completed` státuszú piszkozatokhoz a retry nem nyúl hozzá.

A cikkimport csak akkor ment el piszkozatot, ha az oldalból érdemi cikktörzs felismerhető. Navigáció, hír-ajánlók és kapcsolódó tartalmak nem helyettesíthetik a cikket. Ha a forrás dinamikus, paywallos vagy nem tartalmaz elég bekezdést, az importfeladat hibásnak minősül és szerkesztői kontrollra szorul.

**Már elmentett hibás piszkozatok:** a módosítás nem írja felül automatikusan a korábban importált cikkeket és nem javítja őket csendben. A korábbi importokat szerkesztői ellenőrzéssel kell rendezni, és a sikeres korábbi elemeket nem futtatja újra a „Sikertelenek újrapróbálása” művelet. Az új validáció csak a további importálásoknál érvényes.

A worker kizárólag CLI-ból indítható; a `storage/import/worker.lock` fájlon keresztül megakadályozza az egymásra futó importfolyamatokat. Minden futás védett állapotjelzést ír a `storage/import/worker-status.json` fájlba, amelyet az Importközpont webhelyenkénti várakozó feladatszámmal együtt jelenít meg. Operátori ellenőrzéshez:

```sh
/usr/bin/php /home/appwrkrft/domains/cms.8media.hu/public_html/import-worker.php --health
```

Ez a parancs csak a legutóbbi workerjelet ellenőrzi, nem futtat importot. Sikeres friss jel esetén 0, hiányzó, elavult vagy hibás jel esetén 2 kilépési kóddal tér vissza. **Nem bizonyítja önmagában a cron tényleges beállítását**, ehhez a szerver cron/supervisor listáját és a naplókat is ellenőrizni kell. Az állapotjelző nem igényel új adatbázistáblát, de az importminőség-méréshez szükséges korábbi `import_item_metrics` migráció továbbra is külön, mentés után futtatandó.

- Az API a `/api-v1.php?site=<site-slug>&resource=articles` és `resource=article&article=<slug>` végponton kizárólag publikált cikkeket ad vissza. A hiányzó frontend, keresés, oldalépítő, részletes tömegimport-riport és vizuális rekonstrukció a teljes P0 backlog része.

**Fontos:** az új modulok adatbázistábláit a platformadmin által jóváhagyott `/migrate.php` futás hozza létre. A GitHub Actions FTP-kódtelepítés **nem** jelent automatikus éles adatbázis-migrációt vagy workerbeállítást. Mielőtt importot indítasz, ellenőrizni kell az éles szerveren a PHP `curl`, `dom` és `xml` kiegészítők meglétét, valamint a forrástartalom importjának jogszerűségét.

## Éles környezet és telepítés

- Admin: https://cms.8media.hu/
- Repository: https://github.com/krankovics/cms
- Éles célkönyvtár: `/home/appwrkrft/domains/cms.8media.hu/public_html/`
- MySQL kapcsolat: a védett `app/config.php` fájlban, GitHub Actions secretből generálva
- GitHub Actions secretek: `FTP_HOST`, `FTP_USER`, `FTP_PASS`, `DB_NAME`, `DB_USER`, `DB_PASS`

A `.github/workflows/deploy.yml` csak a **sikeres MySQL-integrációs és HTTP smoke tesztek** után telepít FTP-vel. A kód- és sémaellenőrzések külön, izolált tesztadatbázisban futnak, nem az éles adatbázison.

### Első telepítés

Az induló rendszerben a `COMESY_SETUP_KEY` secret (legalább 32 karakter) segítségével a `/setup.php` oldalon hozható létre az első szuperadmin. A létrehozás után a secretet el kell távolítani, majd új deploy szükséges.

### Meglévő telepítés bővítése

A kódtelepítés után a szuperadmin nyissa meg a [migrációs oldalt](https://cms.8media.hu/migrate.php), és egyszer futtassa a migrációt. Ez létrehozza a még hiányzó táblákat, és a korábbi cikkek elsődleges webhelyeit `article_sites` kapcsolattáblába másolja. A művelet újrafuttatható, a cikkek és a meglévő közös/helyi címkék nem törlődnek.

Ezután a [webhely-hozzáférések](https://cms.8media.hu/site-permissions.php) oldalon a megfelelő szerkesztőhöz több szerkeszthető webhely rendelhető. A cikkszerkesztőben ezek közül több jelölhető ki, de a cikk elsődleges webhelye nem vehető ki a hozzárendelésből.

## Tesztek

- `tests/integration.php`: MySQL séma, elsődleges/további webhelykapcsolatok, jogosultságok, globális fogalmak, törlés
- `tests/http-smoke.py`: bejelentkezés, két webhelyes cikkmentés, publikálás, listaszűrés, jogosulatlan olvasás blokkolása
- `.github/workflows/tests.yml`: PHP/JavaScript lint és az izolált tesztfolyamatok, a telepítési workflow-ból újrafelhasználva

A tesztek **nem** helyettesítik az éles felhasználói elfogadási tesztet és a külső webhelyek integrációs ellenőrzését.

## Admin UI v2 minőségbiztosítás

A GitHub Actions izolált MySQL 8 tesztadatbázison ellenőrzi a webhely-jogosultságokat, a szerepköröket, a cikkek mentését és publikálását, a címke- és médiakezelést, a képek alternatív szövegének módosítását, a felhasználókezelést, valamint a profiladatok mentését. A böngészős teszt 12 adminnézetet jár be 320, 390, 768, 1366 és 1920 px szélességen, és ellenőrzi a navigáció, panelek, sötét mód, keresés és feltöltés működését. Az automatikus tesztek Chromium alatt futnak; a tényleges iPhone Safari kézi ellenőrzése ettől különböző elfogadási teszt.

A teljes cikk tartalmának külső kiadói webhelyek felé való publikálása külön integrációt igényel.
