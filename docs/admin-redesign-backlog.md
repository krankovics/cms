# comesy Admin UI v2 | Megvalósítási backlog

Kapcsolódó alapdokumentum: [Admin UI v2 design system](./admin-design-system.md).  
Cél: modulonként bevezethető, regresszióbiztos SaaS-admin UI desktopon és mobilon.

**Állapot:** Az S0–S4 tervezett admin UI-átépítés elkészült. A működő modulok kódja éles telepítésre előkészített, és izolált adatbázison, HTTP-s valamint Chromium-böngészős tesztekkel ellenőrzött. Az iPhone Safari fizikai készülékes elfogadási teszt külön ellenőrzési pont, nem igazolt automatikus teszt.

## Építési sorrend és függőségek

| Sprint | Prioritás | Tétel | Függőség | Becsült nehézség |
|---|---|---|---|---|
| S0 | P0 | Design tokenek, admin v2 CSS-réteg, alapkomponensek | nincs | közepes |
| S0 | P0 | Egységes desktop sidebar + mobil drawer + közös page header | tokenek | közepes |
| S0 | P0 | Alap UI regressziós ellenőrzések és vizuális breakpoint matrix | app-shell | közepes |
| S1 | P0 | Áttekintő, KPI-k, munkasor, quick action | app-shell | kicsi |
| S1 | P0 | Cikklista: kompakt toolbar, táblázat/kártya | app-shell | közepes |
| S1 | P0 | Kategóriák és címkék kétpaneles kezelés | listaelemek | közepes |
| S2 | P0 | Médiatár galéria + feltöltési drawer + asset inspector | app-shell | közepes |
| S2 | P1 | Cikkszerkesztő meta-panelek + sticky save | shared panels | nagy |
| S2 | P1 | Felhasználók: kompakt lista, létrehozó/szerkesztő drawer | listaelemek | közepes |
| S3 | P1 | Webhelyek, hozzáférések, profil, beállítások egységesítése | app-shell | közepes |
| S3 | P1 | Átfogó a11y, dark/light és iPhone Safari ellenőrzés | minden oldal | közepes |
| S4 | P2 | Legacy CSS és inline style szabályok konszolidációja | minden oldal migrált | közepes |

## S0. Egységes alkalmazáskeret [P0 / DONE]

**Érintett fájlok:** `app/header.php`, `assets/ui.css`, tervezett `assets/admin-v2.css`, `assets/ui.js`.

Feladat:
- Vezessünk be v2 tokeneket és `ui-*` komponensosztályokat a jelenlegi PHP+CSS architektúrában.
- Desktopon állandó, 248 px sidebar a fő navigációval; 1024–1279 px ikon-nav; 768 px alatt off-canvas.
- Bal sidebarban egységes aktív állapot és modul-sorrend; a jogosultság szerint nem elérhető menüpontot ne mutassuk.
- Felső toolbar: comesy márka, aktuális modul kontextusa, világos/sötét váltás, felhasználómenü.
- A felhasználómenü és drawer nyitott állapota ne hagyjon scroll- és fókuszhibát.
- Alap `ui-page-header`, `ui-panel`, `ui-button`, `ui-field`, `ui-toolbar`, `ui-badge`, `ui-empty-state` komponensek.
- Az admin komponensek nem írhatják felül a bejelentkező/telepítő oldal saját stílusát.

**Elfogadás:** desktopon nincs hamburgerre kényszerülő navigáció, mobilon 320 px-en sem lóg ki a header, a drawer Escape-re és overlay-kattintásra zár, fókusz visszakerül a megnyitó gombra.

## S1. Áttekintés [P0 / DONE]

**Érintett:** `index.php`

- Hero nélküli, kompakt oldalcím + „Új cikk” gyorsművelet.
- KPI-k: cikkek, ellenőrzésre vár, webhelyek, médiatár, valós adatokkal és megfelelő szűrt hivatkozással.
- Kéthasábos desktop munkaterület: legutóbbi cikkek és szerkesztőségi feladatlista; mobilon egy oszlop.
- Nincs létrehozott adat esetén CTA, adatbázishibánál külön hibaállapot.
- A jogosultsági szűrést megtartjuk, nem hozunk be bemutatóadatot.

**Elfogadás:** 390 px-en a négy KPI legfeljebb két sor; első képernyőn elérhető legalább egy hasznos akció; 1366 px-en látható a munkasor.

## S1. Cikkek [P0 / DONE]

**Érintett:** `articles.php`

- Oldalcím + új cikk CTA.
- Search, webhely és státusz filter egy kompakt toolbarsávban; mobilon egy search, szűrők drawerben.
- Státuszból szöveges badge, nem külön nagy cella; dátum és webhely másodlagos metadata.
- Mobil `ui-list-row` címmel, státusszal, webhellyel, utolsó módosítással, kebab menüvel.
- Lapszámozás: szerveroldalon legfeljebb 50 vagy 100 elem/oldal, a valós találatszám megőrzésével.
- Szerkesztési jog hiányában a sor megtekinthető, de szerkesztőlink nincs.

**Elfogadás:** a keresés URL-ben marad meg újratöltéskor; nincs táblázatból eredő oldalirányú görgetés mobilon; legalább 1000 cikk kezelése áttekinthető.

## S1. Kategóriák és címkék [P0 / DONE]

**Érintett:** `taxonomy.php`, `assets/ui.js`, `app/taxonomy-actions.php`

- Desktop 60/40 split: bal lista (keresés, típus, webhely, darabszám), jobb szerkesztőpanel.
- Mobil alapnézet csak a lista; „Új kategória” / „Új címke” gomb drawerben nyitja az űrlapot.
- Szülőkategóriák hierarchikus megjelenítése; használatszám, slug és leírás csak részletezéskor.
- Tömeges műveletek sávja csak kijelöléskor jelenjen meg; látható találatok kijelölése mellett minden kiválasztott elem száma pontos.
- Összevonás, törlés külön destruktív confirmban, információval a cikkbesorolásokról.
- A globális fogalomtár külön választható; globális és helyi címkék kapcsolódási logikája maradjon áttekinthető.
- Server-side jogosultság változatlanul kötelező.

**Elfogadás:** mobilon az első látható listatétel maximum egy képernyőnyi görgetésre; a form nem látszik állandóan; nem ismétlődnek azonos fogalmak azonos nézetben; valódi jogosultsági kontroll van.

## S2. Médiatár [P0 / DONE]

**Érintett:** `media.php`, `media-file.php`, `editor-upload.php`

- Galéria az elsődleges képernyő, „Feltöltés” kompakt action a headerben.
- Feltöltés dropzone / fájlválasztó drawerben; progressz és hibakezelés; 10 MB limit kliensen és szerveren.
- Kattintott kép jobb oldali panelen / mobil drawerben: preview, név, típus, méret, alt, URL másolás.
- Feltöltési cél és galéria site filter továbbra is külön fogalom.
- Csak a meglévő jogosultságoknak megfelelő fájlok legyenek elérhetők.
- Későbbi körre tehető: pagination, metaadat-mentés, bulk alt; törlés csak kapcsolati ellenőrzéssel.

**Elfogadás:** 390 px-en kétoszlopos galéria; keresés és site filter a galéria előtt; a fájlválasztó nem uralja a képernyőt.

## S2. Cikkszerkesztő [P1 / DONE]

**Érintett:** `editor.php`, `assets/editor.js`

- Fejlécben cím + mentés / előnézet / publikálási állapot.
- Desktop: fő szerkesztő 70%, meta sidebar 30% (státusz, webhelyek, kategóriák, címkék, SEO, revízió).
- Mobil: szerkesztő az első; meta panelek accordionban; 44–52 px sticky alsó mentési művelet.
- Tartalom beillesztése és kép feltöltése nem sérülhet; a Quill CSS-t külön scoped komponenssel kell kezelni.
- Oldal elhagyásakor mentetlen változtatás jelzése; visszaállítható helyi piszkozat.
- Az elsődleges webhely nem kapcsolható ki jogosulatlanul, és azonos nevű globális címkék kezelése megmarad.

**Elfogadás:** iPhone-on a képbeillesztés és mentés tesztje sikeres; a mentés látható marad; státusz és kategória módosítása nem ugráltatja az oldalt.

## S2. Felhasználók [P1 / DONE]

**Érintett:** `users.php`, `site-permissions.php`

- Lista az első képernyő: avatar/monogram, név, email, szerepkör, aktív/inaktív státusz.
- „Új felhasználó” drawer, nem állandó nagy űrlap; szerkesztőpanel kiválasztott felhasználón.
- Jelszó mező csak létrehozásnál kötelező, szerkesztéskor opcionális.
- Szerepkör- és jogosultságváltozás egyértelmű, a fiók letiltása megerősítést kér.
- Biztonsági invariáns: az utolsó aktív szuperadmin nem tiltható le.
- A webhely-hozzáférésekhez vezető link a felhasználó saját sorából nyíljon.

**Elfogadás:** mobilon első foldon kereső és néhány felhasználó, nem teljes hosszú űrlap; sorok 44 px vagy nagyobb kattintási céllal.

## S3. Webhelyek, jogosultságok, profil és beállítások [P1 / DONE]

**Érintett:** `sites.php`, `site-permissions.php`, `profile.php`, `settings.php`, `migrate.php`

- Webhelyek kártyalistában, domain/slug és cikkdarabszám másodlagos szöveggel; form drawer.
- Webhely-hozzáférések: jogosultságmátrix desktopon, mobilon webhelyenként összecsukható csoport.
- Profil és beállítások ne tartalmazzanak nehéz, egymásba ágyazott kártyákat.
- A migráció veszélyes/karbantartási műveletként jelenjen meg, pontos hatásmagyarázattal.
- Mindenhol a szuperadmin/admin/szerző szerepkör alapján jelenjenek meg a lehetőségek.

**Elfogadás:** 390 px-es méreten is átlátható a jogosultságmátrix; site választás és mentés nem igényel horizontális scrollt.

## Minőségkapuk és tesztrendszer

Az üzleti logikát nem változtathatja meg a vizuális redesign; minden fázisban kötelező:
- `php -l` és `node --check`
- meglévő MySQL-integrációs és HTTP smoke tesztek
- light/dark képernyő: 390×844, 768×1024, 1366×768, 1920×1080
- jogosultsági állapotok: author, editor, site_admin, platform_admin
- üres lista, 1 elem, nagy lista, hiba, mentett és változott állapot
- Safari iOS: menü megnyitás/zárás, inputfókusz, fájlválasztás, sticky action
- WCAG AA fókusz, label és billentyűzet-kezelés; `prefers-reduced-motion`

## Szállítási stratégia

1. Először közös CSS/token + navigáció; csak ezután bontjuk meg a már működő oldalakat.
2. A redesign alatt a meglévő `ui.css` alias változói megmaradnak, így a korábbi képernyők nem törnek el.
3. Egyesével cseréljük a markupot, oldalspecifikus inline CSS kivonásával.
4. Minden befejezett oldalhoz kerül előtte/utána mobil + desktop screenshot, és regressziós eredmény.
5. A valós célkörnyezetbe csak sikeres tesztek után telepítünk; funkcionális változásra külön migrációs vagy API-terv készül.

## Nyitott termékdöntések

- Végleges betűcsalád: Inter/CDN vagy self-hosted font (a CDN-hiba esetére fallback kötelező).
- Desktop sidebar: összecsukható ikonállapot legyen-e perzisztens (`localStorage`), vagy egyszerű fix?
- Kategória/címke panel: route alapú szerkesztés desktopon, illetve URL-ben szerkesztett ID a mobil drawerhez.
- Médiatár jövőbeni dokumentum/PDF támogatása külön feature; most kép-médiatárként kezeljük.
- Üzleti szempontból elkülönítendő: közös cikk-hozzárendelés vs. külső webhelyre publikálás.

A fenti döntések hiányában az alapértelmezett implementáció egyszerű, függőség nélküli, működő megoldást követ.

## Megvalósítási összefoglaló

- **S0**: szemantikus design tokenek; kibontható/összecsukható, állapotot megőrző desktop navigáció; fókuszkezelt mobilmenü; világos és sötét téma.
- **S1**: szerkesztőségi KPI/dashboard, szerveroldali cikklista-lapozás, mobilos szűrőpanel és aktív filterek, hierarchikus kategóriák, globális fogalomtár, megerősített tömeges törlés.
- **S2**: galéria-központú médiatár feltöltési visszajelzéssel és alternatívszöveg-szerkesztéssel; Quill-cikkszerkesztő összehajtható meta-panelekkel, kiemelt képpel, revíziókkal, mentetlen tartalomra figyelmeztetéssel; felhasználókezelő jogosultsági linkekkel és inaktiválás-megerősítéssel.
- **S3**: mobilos webhelyszerkesztő-panel, webhelyenként lenyitható hozzáférési mátrix, saját profil, settings, guarded migráció.
- **S4**: régi admin- és taxonomy-CSS felülírások eltávolítása; külön admin, listaszerkesztő és auth stíluslapok; nem módosuló PHP üzleti jogosultságok.

**CI minőségkapuk:** PHP/JS lint, izolált MySQL, teljes cikkmentés/publikálás, kategórialétrehozás, médiatár/feltöltés/alt-szerkesztés, felhasználólétrehozás, szerepkörös hozzáférésvizsgálat, 12 adminnézet 320/390/768/1366/1920 px-en, sötét mód és képmentés. Éles FTP-telepítés csak a zöld tesztek után történik.

**Külön termékfejlesztések:** külső kiadói oldalak tényleges cikkátvétele, további dokumentumformátumok (PDF, videó), fejlett média-bulk funkciók és szerkesztőségi jóváhagyási automatizmusok. Ezek nem képezik az Admin UI v2 vizuális átépítésének szállítását.
