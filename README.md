# 8media CMS

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
A workflow FTP `server-dir` értéke a belépési FTP-gyökértől **relatív**. Jelenleg `./domains/cms.8media.hu/public_html/` értéket feltételez, ami akkor helyes, ha az FTP-felhasználó gyökere `/home/appwrkrft/`. Ha más, a deploy.yml fájlban korrigálni kell a relatív útvonalat az első telepítés előtt.

Az első release csak egy statikus PHP ellenőrző oldalt másol. Adatbázis-kapcsolatot nem létesít és nem futtat migrációt. A workflow nem töröl távoli fájlokat automatikusan.

## Tervezett rendszer
Multisite, blokkos tartalomszerkesztő, audit és verziózás, visual page builder, natív hirdetési modul, szabályozott nagyüzemi import és import-demó.
