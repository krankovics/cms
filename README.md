# comesy
**Content. Media. System.**  
**One platform. Every story.**

Moduláris, PHP/MySQL-alapú, több webhelyet kezelő szerkesztőségi CMS. A márkanév mindig **comesy** (kisbetűvel).

## Működő adminisztrációs részek

- Felhasználói bejelentkezés, profil, szuperadmin és szerkesztőségi szerepkörök
- Webhelyek, webhelyenkénti szerkesztési és olvasási jogosultságok
- Cikkszerkesztő: gazdag szöveg, médiafeltöltés, revíziók, kategóriák, címkék, SEO
- Cikkstátuszok: piszkozat, ellenőrzésre vár, publikált
- Egy cikk hozzárendelése több engedélyezett webhelyhez: közös tartalom és státusz
- Globális (site_id=0) és helyi kategóriák/címkék. A meglévő globális fogalmakat az új címkék létrehozásakor újrahasználja
- Médiatár: hozzáférés-alapú böngészés és feltöltés

**Korlát:** A többwebhelyes hozzárendelés a comesy CMS adatmodelljében működik. A publikált tartalom külső webhelyekre történő továbbítása külön frontend/API-integrációt igényel. A globális és helyi régi duplikátumokat nem egyesíti automatikusan.

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
