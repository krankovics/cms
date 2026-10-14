<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user();
$isAdmin=$u['role']==='platform_admin';
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Beállítások | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-admin-section" id="main-content"><header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Rendszer</p><h1 class="ui-page-title">Beállítások</h1><p class="ui-page-description">Fiókbeállítások, webhelyek és rendszeradminisztráció.</p></div></header>
<div class="ui-admin-card-links">
<a class="ui-admin-navcard" href="/profile.php"><strong>Saját profil</strong><p>Profilkép, megjelenített név és jelszó módosítása.</p><span>Profil megnyitása →</span></a>
<?php if($isAdmin):?>
<a class="ui-admin-navcard" href="/sites.php"><strong>Webhelyek</strong><p>Webhelyek létrehozása, neve, domainje és szerkesztése.</p><span>Webhelyek kezelése →</span></a>
<a class="ui-admin-navcard" href="/users.php"><strong>Felhasználók</strong><p>Csapattagok, szerepkörök és aktív fiókok kezelése.</p><span>Felhasználók kezelése →</span></a>
<a class="ui-admin-navcard" href="/site-permissions.php"><strong>Webhely-hozzáférések</strong><p>Szerkesztési jogosultságok, címkék és médiatár elérése webhelyenként.</p><span>Hozzáférések →</span></a>
<a class="ui-admin-navcard" href="/migrate.php"><strong>Adatbázis-migráció</strong><p>Szükséges adattáblák biztonságos létrehozása és frissítése.</p><span>Migráció megnyitása →</span></a>
<section class="ui-admin-navcard" aria-label="Rendszerinformációk"><strong>Rendszerinformációk</strong><p>PHP: <?=h(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)?>. Adatbázis: <?php try{db()->query('SELECT 1');echo 'elérhető';}catch(Throwable $e){echo 'nem elérhető';}?>.</p></section>
<?php endif;?></div></main></body></html>