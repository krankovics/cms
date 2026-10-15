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
| Natív hirdetésadmin | P0 | HIÁNYZIK | Hirdetők, kampányok, kreatívok, hirdetési zónák, site/rovat targeting, futási idő, státusz, prioritás, adserver-fallback, biztonságos kiszolgálás/mérés/audit |
| Import: egy URL, teljes site és demó | P0 | HIÁNYZIK | URL-felderítés (sitemap/RSS/Atom/HTML), szerkeszthető blokk-konverzió, meta, média, URL-megőrzés, idempotencia, hiba és bizonytalanság, 10 cikkes működő demo |
| Nagy import, külön worker | P0 | HIÁNYZIK | Tartós job queue, 100 cikkes próba, robots, rate limit, 429/503 backoff, pause/resume, checkpoint, 300–700 ezer reális, 1M stresszteszt |
| Forrásoldal vizuális rekonstrukció | P0 cél | HIÁNYZIK | Felismert header/footer/sablonok natív komponensekkel, desktop/mobil összehasonlítás, snapshot/restore, hibajegyzék |
| Média- és URL-migráció | P0 | HIÁNYZIK | Dedup, jogosultság/licenc, CDN/source URL, sérült képek, belső linktérkép, 301 javaslatok |
| Importköltség-/minőségriport | P0 | HIÁNYZIK | 100 mintacikk, CPU, memória, forgalom, sebesség, hibaarány, tárhely, AI felhasználás, jóváhagyott keret |
| Biztonság, MFA, GDPR, backup | P0 | korlátozott | Admin MFA, rate limit, audit, IP/log retention, adatkezelés, mentés-visszaállítás próba, riasztás |
| Nyilvános tartalom API és kereső | P0 | HIÁNYZIK | Site-scope, publikált-only, lapozás, cache, keresés, dokumentált API; API-first frontend |
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
