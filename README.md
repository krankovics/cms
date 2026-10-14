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
