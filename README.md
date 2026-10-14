# comesy

**Content. Media. System.**

**One platform. Every story.**

A márkanév mindenhol kisbetűs: comesy.

Modulok: comesy Editor, comesy Sites, comesy Media, comesy AI, comesy Ads, comesy Analytics.

Központi multisite kiadói CMS. A projekt jelenleg a telepítési infrastruktúra ellenőrzésének fázisában van.

## Éles cél
- URL: https://cms.8media.hu/
- DirectAdmin abszolút könyvtár: /home/appwrkrft/domains/cms.8media.hu/public_html/
- GitHub: krankovics/cms (main)
- FTP: sima FTP, port 21
- DB host: localhost

## Github Actions secrets
FTP_HOST, FTP_USER, FTP_PASS, DB_NAME, DB_USER, DB_PASS.

## Fontos
Az FTP belépési könyvtára közvetlenül a public_html; a workflow `server-dir: ./` értéket használ.

Az első release csak egy statikus PHP ellenőrző oldalt másol. Adatbázis-kapcsolatot nem létesít és nem futtat migrációt. A workflow nem töröl távoli fájlokat automatikusan.

## Tervezett rendszer
Multisite, blokkos tartalomszerkesztő, audit és verziózás, visual page builder, natív hirdetési modul, szabályozott nagyüzemi import és import-demó.

## Első üzembe helyezés

1. A GitHub Actions secret-ek között add meg a `COMESY_SETUP_KEY` értékét (legalább 32 karakter).
2. A secret hozzáadása után indíts új deploy-t a `main` ágról. A korábban lefutott telepítés nem veszi át visszamenőleg a secretet.
3. Nyisd meg a `https://cms.8media.hu/setup.php` címet, add meg a telepítési kulcsot és hozz létre legalább 14 karakteres jelszóval platformadmin-fiókot.
4. Jelentkezz be a `https://cms.8media.hu/login.php` oldalon.
5. A telepítés után töröld a `COMESY_SETUP_KEY` secretet és indíts új deploy-t, hogy a szerveren tárolt konfigurációból is eltűnjön.

A `setup.php` csak akkor aktív, ha telepítési kulcs van konfigurálva és még nincs felhasználó az adatbázisban.
