# comesy Admin UI v2 | Design system
**Állapot:** elfogadásra előkészített, implementációs specifikáció  
**Márka:** comesy, *Content. Media. System.*, *One platform. Every story.*  
**Cél:** saját, modern kiadói SaaS-admin, desktopon és mobilon ugyanazzal a komponensnyelvvel. A funkcionalitás a kiindulópont; nem WordPress-klón.

## 1. Jelenlegi állapot és UI-problémák

A mai admin CSS közösen az `assets/ui.css` fájlban él (~20 KB), de sok egymásra felülíró szabályt és `!important` módosítást tartalmaz. A legtöbb oldal saját inline `<style>` blokkot is használ. Emiatt más az űrlapok magassága, margója és gombrendszere; a CSS nem tekinthető valódi komponensrendszernek.

- A `taxonomy.php` mobilon magas létrehozási űrlappal kezdődik, és a tömeges műveletekhez tartozó sáv aránytalanul sok helyet foglal. A kategóriasorok csak a lap alján érhetők el.
- A `media.php` feltöltőpanelje megelőzi a galériát, a fájlválasztás natív, nehezen skálázható; hiányzik az egységes galéria/asset-inspector rendezés.
- A `users.php` a teljes létrehozási űrlapot és a listát egy lapra teszi, így a találatok mobilon a fold alá kerülnek; a szerepkörök és státuszok vizuálisan nincsenek következetesen jelölve.
- Az `articles.php` táblázat és szűrők több köztes képernyőméreten is széttöredeznek.
- Az `app/header.php` kizárólag mobil menüelemet tartalmaz; desktopon nem egyértelmű az állandó navigáció. A mobil drawer és az overlay régi CSS-mintákból épül.
- Az `index.php` mutatószámai léteznek, de a tartalmi prioritás, kezdő műveletek, munkasor és állapotok még nem elég erősek.

## 2. Layout és responsivitás

| Ablak | Navigáció | Tartalom | Oldalspecifikus panel |
|---|---|---|---|
| ≥1280 px | 248 px fix, összecsukható bal sidebar | 1160–1360 px max, 28–32 px vízszintes padding | 320–380 px jobb inspector |
| 1024–1279 px | 76 px ikon-sidebar + tooltip | fluid, 24 px padding | opcionálisan drawer |
| 768–1023 px | felső toolbar + off-canvas menü | fluid, 20 px padding | drawer |
| ≤767 px | 60–64 px sticky topbar + teljes magasságú drawer | 12–16 px padding; egy oszlop | bottom sheet vagy accordion |

Az oldal minimális oldalirányú mérete 320 px; horizontal overflow csak tudatos adattáblázatban megengedett. iPhone Safari esetén `100dvh` és `env(safe-area-inset-bottom)` használata szükséges. A menü a topbar magassága helyett a viewport teljes magasságához igazodik; nyitáskor a háttér scroll zárolt.

**Globális app shell**: `app/header.php` + közös navigáció + közös footer/toolbar. Desktop sidebar mindig látható, mobilon hamburgerrel nyílik. Navigáció: **Áttekintés**, **Editor** (Cikkek, Új cikk, Kategóriák és címkék, Médiatár), **Webhelyek** (jogosultságfüggő), **Csapat** (Felhasználók, Hozzáférések, jogosultságfüggő), **Beállítások** (Profil, Rendszer). Aktív helyzet, visszacsukás és fókusz visszaállítása kötelező.

## 3. Design tokenek

A meglévő `--bg`, `--surface`, `--ink`, `--muted`, `--line`, `--nav`, `--field`, `--blue` változók átmenetileg aliasok; a v2 komponensek csak szemantikus tokeneket használjanak.

| Token | Light | Dark | Rendeltetés |
|---|---|---|---|
| `--ui-bg` | `#F6F8FC` | `#0D1421` | alkalmazás háttér |
| `--ui-surface` | `#FFFFFF` | `#172338` | panel, kártya |
| `--ui-surface-subtle` | `#F1F5FA` | `#22314A` | mező, hover |
| `--ui-text` | `#192437` | `#EFF4FC` | elsődleges szöveg |
| `--ui-text-secondary` | `#64748B` | `#A8B9D2` | leírás, dátum |
| `--ui-border` | `#DFE6F0` | `#364861` | elválasztó |
| `--ui-primary` | `#335EEA` | `#88A9FF` | aktív elem, CTA |
| `--ui-primary-on` | `#FFFFFF` | `#0D1421` | elsődleges gomb felirat |
| `--ui-nav-bg` | `#14213A` | `#0B1324` | sidebar |
| `--ui-success` | `#157347` | `#47CEA0` | siker |
| `--ui-warning` | `#A5690E` | `#F4C06B` | figyelmeztetés |
| `--ui-danger` | `#C13C4D` | `#F27883` | destruktív művelet |

A kontrasztot tényleges párosításonként WCAG AA szerint kell ellenőrizni; a táblázat önmagában még nem kontrasztvalidáció.

**Typo:** Inter elsődlegesen, fallback `system-ui, -apple-system, Segoe UI, sans-serif`; szabályozott méretek: 12/13 (meta), 14 (kompakt lista), 15–16 (törzs), 18 (szekció), 24–30 (oldalcím). A megadott vizuális betűméret **minden inputon legalább 16 px**, az iPhone automatikus zoomjának elkerüléséhez. Címek `font-weight: 650–750`.

**Spacing:** `4, 8, 12, 16, 20, 24, 32, 40` px. Kártyapadding desktopon 20–24 px, mobilon 14–16 px. Form mezők között 12–16 px, szekciók között 20–24 px. Ikonok 18–20 px, érintési cél minimum 44 × 44 px.

**Shape:** input radius 10 px, gomb 10 px, kártya 14 px, modal 18 px. Kártya halvány borderrel, árnyék elsősorban menün/draweren/modálon; ne legyen minden kártya árnyékos.

## 4. Komponenskészlet

| Komponens | Egységes viselkedés |
|---|---|
| `ui-page-header` | breadcrumb/opcionális előzmény, cím, segédszöveg, legfeljebb két elsődleges akció |
| `ui-toolbar` | kereső, státusz-/site-szűrők, aktív filter chips, törlés/reset |
| `ui-card` | kompakt fejléc + body + opcionális footer; nincs fölösleges nested card |
| `ui-field` | label, control, help/error; validáció és disabled/fókusz állapot |
| `ui-button` | primary / secondary / ghost / danger; loading, disabled, icon-only aria-label |
| `ui-badge` | kizárólag státusz: piszkozat, ellenőrzés, publikált, ütemezett, archivált |
| `ui-data-table` | desktop táblázat, ≤767 px kártyás listanézet, szemantikus adatkapcsolatok |
| `ui-list-row` | cím, meta, állapot, jobb oldali kebab műveletek; billentyűzetről kezelhető |
| `ui-drawer` | fókuszcsapda, escape-zárás, visszatérő fókusz, backdrop, scroll lock |
| `ui-confirm-dialog` | destruktív akció megerősítése, konkrét elemszám, visszafordíthatatlanság |
| `ui-empty-state` | elsődleges következő akcióval; hiba és üres eredmény különálló |
| `ui-toast` | siker és információ aria-live regionben, hibák a mezőhöz kötve |

Ne használjunk öncélú badge-et a menüben, minden kártyán vagy címsoron.

## 5. Oldal sablonok

**A. Áttekintés**: 4 kompakt KPI (desktop 4, mobil 2×2), alatta szerkesztőségi munkasor és legutóbbi cikkek; a kártyák a megfelelő szűrt listára vezetnek.

**B. Listaoldal**: page header + egy sor toolbar + találatszám + adatlista + pagination. Mobilon nincs külön nagy keresőkártya, a szűrés collapsible drawerbe kerülhet.

**C. Kétpaneles kezelés (Kategória/Címke, Média)**: desktopon bal oldalt teljes lista (minimum 60%), jobb oldalt szerkesztő/inspector (maximum 40%). Mobilon lista az első, új elem a fő CTA, szerkesztés drawerben/bottom sheetben.

**D. Szerkesztő**: címsor + kontextusakciók (előnézet, mentés, publikálás), fő szövegterület + jobb beállítás-sáv. Mobilon a címsor alatt minimális editor, a meta/SEO/webhelyek lenyitható panelen, mentési akció ragadós alsó sávban.

**E. Beállítások**: egyszerű navigálható szekciók, a veszélyes karbantartási akciók elkülönítve.

## 6. Hozzáférhetőség és interakció

- Szemantikus `<nav>`, `<main>`, `<section>`, `<button>`; input–label kötés mindenhol.
- Látható `:focus-visible`; nem csak színnel kódolt státusz.
- Mobil drawerre `aria-expanded`, `aria-current="page"`, Escape és fókuszkezelés.
- Legfeljebb 200 ms normál animáció; `prefers-reduced-motion: reduce` támogatás.
- Siker/hiba az akció közvetlen közelében, ne automatikus több képernyős visszagörgetéssel.
- Tömeges kijelölésnél az összes kijelölt elemszám látható, a destruktív művelet csak kiválasztás után aktív.
- Ne változzon semmilyen üzleti jogosultság, SQL-viselkedés vagy migráció kizárólag vizuális refaktor miatt.

## 7. Technikai építési szabályok

1. `assets/admin-v2.css` dedikált komponensréteg; fokozatosan átvezetni a régi `ui.css`-ből, majd a duplikált szabályokat eltávolítani.
2. `app/header.php` egységes navigáció, URL-alapú aktív menü, szerepkör-feltételek megtartása.
3. Új PHP oldalsablonokban az inline CSS megszüntetése, nem szabad újabb `!important` szőnyeget hozzáadni.
4. Interakciók az `assets/ui.js` közös, kis moduljaiban; nincs külső UI framework-kényszer.
5. A markup minden URL-hez és formhoz a már meglévő CSRF- és jogosultságellenőrzésekkel marad bekötve.
6. Változtatásonként PHP/JS lint, MySQL-integráció és HTTP smoke; ezután 390 px, 768 px, 1366 px viewport vizuális ellenőrzés.

## 8. Végső elfogadási feltételek

- 320–1920 px szélességen nincs véletlen vízszintes scroll vagy kilógó gomb.
- Desktopon az összes aktív adminmodul állandó, hozzáférésfüggő navigációból elérhető.
- Mobilon a lista nézete az alap; létrehozó/szerkesztő forma nem tolhatja a találatokat indokolatlanul a fold alá.
- Input nem zoomol automatikusan iPhone Safari alatt; interakciók minimum 44 px célterületet kínálnak.
- A light/dark témákban minden üzleti akció és státusz kontrasztos.
- Szerző, szerkesztő, site admin és szuperadmin jogosultsági regresszióteszt nem romlik.
- Ugyanazok a fogalmak és gombok minden oldalon azonos helyen, méretben és viselkedéssel jelennek meg.
