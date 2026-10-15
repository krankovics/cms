# comesy | Teljes kiadói platform: specifikáció és hiányelemzés

**Hiteles követelményalap:** „Kiadói CMS – végleges funkcionális és technikai specifikáció v1.0”, 2026-10-14, Google Drive. A 18 fejezetes admin-v2 dokumentáció *nem* azonos a teljes kiadói platformmal. A terméknév azóta: **comesy**; a technikai repo: `krankovics/cms`.

**Státuszok:** MEGVAN = tesztelt, korlátozott = csak részfunkció vagy adminfelület, HIÁNYZIK = még nincs működő modul. A megvalósítás állapotát csak sikeres automatikus teszt + célkörnyezeti migráció után szabad MEGVAN-ra váltani.

| Követelmény | Prioritás | Eddigi állapot | Teljes elfogadási feltétel |
| --- | --- | --- | --- |
| Többwebhelyes site/adatmodell és CMS szerepkörök | P0 | korlátozott | Site-ok domainnel/útvonalakkal, finom szerepkörmátrix, főszerkesztő; nincs cross-site adatszivárgás |
| Webhelyenkénti funkciókapcsolók | P0 | HIÁNYZIK | Oldalépítő, publikus regisztráció, komment, hírlevél és AI kapcsolók adatvesztés nélkül; külön olvasói és admin fiókok |
| Cikkek/rovatok/szerzők | P0 | korlátozott | Központi szerzői profilok site-felülírással, rovatok, kapcsolódó tartalom, URL-ek, dátumok, szerkesztőségi workflow |
| Blokkos cikkszerkesztő | P0 | korlátozott | Valódi natív bekezdés/cím/lista/kép/galéria/videó/tábla/CTA/komponens blokkok, drag-drop, revízió és preview |
| Revízió/audit/jóváhagyás | P0 | korlátozott | Mező/blokk-diff, restore új revízióként, login/ads/import/settings audit, főszerkesztői jóváhagyási lépések |
| Ütemezés és közzététel | P0 | HIÁNYZIK | Ütemező worker, visszavonás, közzétételi szabályok és valódi frontend |
| Vizuális oldalépítő | P0 | HIÁNYZIK | Sablonok, fej-/lábléc, menük, dinamikus listák, drag-drop, responsive preview, verzió, restore és publish |
| Frontend réteg (meglévő/új) | P0 | HIÁNYZIK | Működő publikus demo, frontend-adapter/API, URL-ek, canonical, sitemap, structured data, OG |
| Natív hirdetésadmin | P0 | korlátozott | Hirdetők, kampányok, kreatívok, hirdetési zónák, site/rovat targeting, futási idő, státusz, prioritás, adserver-fallback, biztonságos kiszolgálás/mérés/audit |
| Import: egy URL, teljes site és demó | P0 | korlátozott | URL-felderítés (sitemap/RSS/Atom/HTML), szerkeszthető blokk-konverzió, meta, média, URL-megőrzés, idempotencia, hiba és bizonytalanság, 10 cikkes működő demo |
| Nagy import, külön worker | P0 | korlátozott | Tartós job queue, 100 cikkes próba, robots, rate limit, 429/503 backoff, pause/resume, checkpoint, 300–700 ezer reális, 1M stresszteszt |
| Forrásoldal vizuális rekonstrukció | P0 cél | HIÁNYZIK | Felismert header/footer/sablonok natív komponensekkel, desktop/mobil összehasonlítás, snapshot/restore, hibajegyzék |
| Média- és URL-migráció | P0 | HIÁNYZIK | Dedup, jogosultság/licenc, CDN/source URL, sérült képek, belső linktérkép, 301 javaslatok |
| Importköltség-/minőségriport | P0 | HIÁNYZIK | 100 mintacikk, CPU, memória, forgalom, sebesség, hibaarány, tárhely, AI felhasználás, jóváhagyott keret |
| Biztonság, MFA, GDPR, backup | P0 | korlátozott | Admin MFA, rate limit, audit, IP/log retention, adatkezelés, mentés-visszaállítás próba, riasztás |
| Nyilvános tartalom API és kereső | P0 | korlátozott | Site-scope, publikált-only, lapozás, cache, keresés, dokumentált API; API-first frontend |
| SEO, News/discover kompatibilitás | P0 | korlátozott | Kiadói oldal canonical, robots, sitemaps, structured data, OG, hírfolyam és teljesítménymérés |
| Megfigyelhetőség, üzemeltetés | P0 | korlátozott | Metrikák, worker státusz, riasztás, migráció-/backup kontroll és kapacitáspróba |
| Komment/@említés, hírlevél, nyelvek | P1 | HIÁNYZIK | Működő szerkesztőségi együttműködés és bekötött szolgáltatások |
| Related/AI/belső linkajánlás | P1 | HIÁNYZIK | Mérhető és szerkeszthető ajánlók, ellenőrzött AI-munkafolyamat |
| Tagság/előfizetés/perszonalizáció | P2 | HIÁNYZIK | Külön olvasói modell, jogalap és fizetési integráció |

## Kötelező megvalósítási sorrend

1. **Adatmodell/API/RBAC alap:** migrációk verziózása; audit és jövőbeli pluginregisztráció; site-scope; hirdetési/import feladatmodellek.
2. **Hirdetésadmin, önálló működő szelet:** reklámzónák, hirdető, kampány, kreatív, alap line-item, célzás, prioritás, publikálási intervallum, kiszolgáló API, mérés és külső kód helyének támogatása. Admin audit és role-scope tesztek.
3. **Import, önálló működő szelet:** egy URL-ből próba/piszkozat, nyers forrásadat megőrzése, HTML→szerkeszthető blokkok, idempotencia; folyamatosan továbbfejlesztve sitemap/RSS/tömeg/workerekké.
4. **Frontend és tartalom API:** bemutatható élő demo; template/rendering, SEO, menus, frontend adapterek.
5. **Oldalépítő + sablonverziózás:** natív komponensmodell, preview/publish, responsive paraméterek, restore.
6. **Szerkesztőségi workflow és scheduling:** publikálás, státuszok, jóváhagyás, diff/audit, jogosultság.
7. **Nagy import és megjelenésrekonstrukció:** 100-as mintamérés, skálázás/monitorozás, CDN/média/link mapping, költség, 1M stresszteszt.
8. **P1/P2 kiegészítő funkciók** külön integrációs és jogi elfogadással.

## Elfogadási kapuk

Minden modulhoz: PHP/JS lint, MySQL-sémateszt, unit+HTTP jogosultsági teszt, működő UI/valós mentés, mobil/desktop/sötét téma, biztonság és csökkenő kompatibilitás, CI zöld. Éles migráció kizárólag adatbázis-mentés és célkörnyezethez illesztett jóváhagyás után; adatbázissémát élesben pusztán GitHub-deploy nem módosít.

**Külső döntések:** forráswebhelyek importjának jogi jogosultsága és robots, frekvenciakorlát/elszámolás és hirdetés-hozzájárulás, cél-szerver kapacitása, MFA-szolgáltató/backup, frontend publikációs szerződések. Ha nincs pontos érték, konzervatív alapértelmezés: draft import, letiltott demo-éles átjárás, nem személyes targeting, nincs harmadik fél követőkód, szüneteltetett tömegimport.

## 2026-10-15: első fejlesztési szeletek tényleges állapota

- **Hirdetésadmin**: `ads.php`, `app/ad-actions.php`, `database/ads.sql`, `ad-slot.php`, `ad-click.php`. CRUD hirdetők/zónák/kampányok/kreatívok, audit, site-scope, rovatcélzás, prioritás és dátum, publikus iframe és összesített zónakérések/kattintások. **Még hiányzik:** frekvenciakorlát, line item/üzleti elszámolás, viewability-impression, konverzió, külső adserver konfiguráció és consent/CMP integráció; bevetés előtt célrendszeres mérés szükséges.
- **Importközpont**: `imports.php`, `import-worker.php`, `app/import-engine.php`, `app/import-jobs.php`, `app/import-worker.php`, `database/import.sql`. Külön feladatsor, URL/RSS/Atom/sitemap/site/demo módok, szerveroldali SSRF/robots védelem, blokkkonverzió, piszkozat, forrás-URL/hash/snapshot, retry/pause/resume, idempotencia és audit. **Külön üzemeltetési lépés:** tartós CLI cron/supervisor beállítása; a kódtelepítés önmagában nem indítja el a workert. **Még hiányzik:** 100 cikkes strukturált próba/költségreport, médiamigráció és linktérkép, 1M-es stresszteszt, vizuális rekonstrukció, forrás-specifikus sablon adapterek.
- **Publikus tartalom API**: `api-v1.php` listázás és részletezés, cikkek/taxonómiák, csak publikált site-scope, JSON/ETag, szűkített CORS. **Még hiányzik:** dedikált keresés, kiadói frontend, teljes frontend API-szerződés, cache/CDN/analytics.

**FONTOS:** A teljes P0+P1/P2 termékspecifikáció **még nincs kész**. A teljes UI redesign lezárása nem egyenlő a fenti nagy fejlesztésekkel. A funkcionális és teljesítmény-elfogadási tesztek lezárása előtt tilos az egész platformot késznek nevezni. A `migrate.php` az új táblákat csak a platformadmin jóváhagyott futtatásával telepíti; a GitHub-FTP önmagában nem módosítja az élő adatbázist.


## 2026-10-15: importminőség és forrásterhelési kontroll

- Új, izolált `import_item_metrics` adattábla (`database/import-metrics.sql`) gyűjti a queue-feladatok mért átviteli bájtjait, időtartamát, kinyert blokkokat, képeket, linkeket és az ellenőrzést igénylő cikkminőségi jelzéseket.
- Az `/import-report.php?site_id=...&job_id=...` csak az adott webhelyre jogosult admin/szerkesztő számára elérhető: összesítések, hibaarány, 100 legutóbbi feladat, minta-CSV és a forrásképek/hivatkozások státuszai. A CSV képletinjektálás-védelemmel készül.
- A robots.txt feldolgozás immár figyelembe veszi a cél robothoz tartozó csoportokat, az Allow/Disallow szabályokat, a specifikusabb útvonalak prioritását, a jokerjeleket és a lezáró horgonyt. A forráselérés hibája továbbra is tiltást jelent.
- A mérések tájékoztató jellegűek: a feldolgozási falióra-idő és a letöltött bájtszám nem azonos a CPU-költséggel, a hálózati számlával vagy az API-díjjal. Külön gépi infrastruktúra-mérés és **100 cikkes valós mintafuttatás** továbbra is szükséges.
- Az adminfelületen megjelenő minőségriport **új adatbázis-migrációt igényel**. Az éles adatbázis és a cron-beállítás automatikusan nem módosul.
