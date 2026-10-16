<?php
require __DIR__.'/app/bootstrap.php';
$u=require_user();if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!valid_csrf())$error='Érvénytelen munkamenet.';
 else{
  try{
   $pdo=db();
   $sql=file_get_contents(__DIR__.'/database/editor.sql')."\n".file_get_contents(__DIR__.'/database/content.sql')."\n".file_get_contents(__DIR__.'/database/site-permissions.sql')."\n".file_get_contents(__DIR__.'/database/ads.sql')."\n".file_get_contents(__DIR__.'/database/import.sql')."\n".file_get_contents(__DIR__.'/database/import-metrics.sql')."\n".file_get_contents(__DIR__.'/database/layouts.sql')."\n".file_get_contents(__DIR__.'/database/pages-readers.sql');
   foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)$pdo->exec($statement);
   // Existing installations had an ENUM with only home/section/article.
   // Widening the enum is additive and preserves every saved revision.
   $pdo->exec("ALTER TABLE site_templates MODIFY COLUMN template_key ENUM('home','section','tag','article','page','login') NOT NULL");
   // Preserve existing site associations and backfill every legacy article's primary site.
   $pdo->exec('INSERT IGNORE INTO article_sites(article_id,site_id) SELECT id,site_id FROM articles');
   // Shared taxonomy terms remain untouched; no destructive merging.
   $message='A comesy adatbázis-migrációja sikeresen lefutott, beleértve a hat oldalsablon-típust, statikus oldalakat és olvasói fiókokat.';
  }catch(Throwable $e){error_log('comesy migration: '.$e->getMessage());$error='A migráció sikertelen. Ellenőrizd az adatbázis jogosultságait.';}
 }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Adatbázis-migráció | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-admin-section" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Rendszerkarbantartás</p><h1 class="ui-page-title">Adatbázis-migráció</h1><p class="ui-page-description">A comesy adatmodelljének frissítése.</p></div><div class="ui-page-actions"><a href="/settings.php" class="ui-btn ui-btn-secondary">Beállítások</a></div></header>
<section class="ui-admin-panel ui-maintenance-panel">
<h2>Adatbázisszerkezet ellenőrzése és kiegészítése</h2>
<div class="ui-maintenance-note"><strong>Fontos művelet</strong><p>A hiányzó szerkesztőségi és hirdetésmodul-táblák létrejönnek, és a korábbi cikkek elsődleges webhelye a többwebhelyes kapcsolótáblába kerül. A meglévő cikkek és fogalmak nem törlődnek vagy olvadnak össze.</p><p>Éles rendszeren a futtatás előtt ajánlott adatbázis-mentést készíteni.</p></div>
<?php if($message):?><p class="success" role="status"><?=h($message)?></p><?php endif;?>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<form method="post" class="ui-admin-form" onsubmit="return confirm('Elindítod az adatbázis-migrációt? A művelet előtt ajánlott biztonsági mentést készíteni.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><div class="ui-admin-actions"><button type="submit" class="ui-btn ui-btn-primary">Migráció futtatása</button><a href="/articles.php" class="ui-btn ui-btn-secondary">Vissza a cikkekhez</a></div></form>
</section></main></body></html>