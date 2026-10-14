<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/article-sites.php';
$u=require_user();
$error='';$siteCount=0;$articleCount=0;$reviewCount=0;$mediaCount=0;$recent=[];$reviewQueue=[];
try {
 $pdo=db();$allowed=allowed_primary_sites($pdo,$u);
 $siteCount=count($allowed);
 if($allowed){
  $ids=implode(',',array_map('intval',$allowed));
  $authorScope=$u['role']==='author'?' AND author_id='.(int)$u['id']:''; 
  $visible=article_sites_ready($pdo)?"(site_id IN ($ids) OR EXISTS(SELECT 1 FROM article_sites asite WHERE asite.article_id=articles.id AND asite.site_id IN ($ids)))":"site_id IN ($ids)";
  $articleCount=(int)$pdo->query("SELECT COUNT(*) FROM articles WHERE $visible$authorScope")->fetchColumn();
  $reviewCount=(int)$pdo->query("SELECT COUNT(*) FROM articles WHERE $visible$authorScope AND status='review'")->fetchColumn();
  $mediaSites=allowed_resource_sites($pdo,$u,'media');
  if($mediaSites){$mediaIds=implode(',',array_map('intval',$mediaSites));$mediaCount=(int)$pdo->query("SELECT COUNT(*) FROM media_assets WHERE site_id IN ($mediaIds)")->fetchColumn();}
  $recent=$pdo->query("SELECT id,site_id,title,status,updated_at,author_id FROM articles WHERE $visible$authorScope ORDER BY updated_at DESC LIMIT 6")->fetchAll();
  $reviewQueue=$pdo->query("SELECT id,site_id,title,status,updated_at,author_id FROM articles WHERE $visible$authorScope AND status='review' ORDER BY updated_at DESC LIMIT 5")->fetchAll();
  if(article_sites_ready($pdo)){
   $allRecords=[];
   foreach(array_merge($recent,$reviewQueue) as $item)$allRecords[(int)$item['id']]=$item;
   if($allRecords){
    $articleIds=implode(',',array_map('intval',array_keys($allRecords)));
    $siteLinks=$pdo->query("SELECT article_id,site_id FROM article_sites WHERE article_id IN ($articleIds)")->fetchAll();
    $disallowed=[];
    foreach($siteLinks as $link)if(!in_array((int)$link['site_id'],$allowed,true))$disallowed[(int)$link['article_id']]=true;
    foreach($recent as &$item)$item['editable']=!isset($disallowed[(int)$item['id']])&&in_array((int)$item['site_id'],$allowed,true);unset($item);
    foreach($reviewQueue as &$item)$item['editable']=!isset($disallowed[(int)$item['id']])&&in_array((int)$item['site_id'],$allowed,true);unset($item);
   }
  }
  foreach($recent as &$item){if(!isset($item['editable']))$item['editable']=in_array((int)$item['site_id'],$allowed,true);}unset($item);
  foreach($reviewQueue as &$item){if(!isset($item['editable']))$item['editable']=in_array((int)$item['site_id'],$allowed,true);}unset($item);
 }
}catch(Throwable $e){error_log('comesy dashboard: '.$e->getMessage());$error='Az összesítések most nem tölthetők be.';}
$labels=['draft'=>'Piszkozat','review'=>'Ellenőrzésre vár','scheduled'=>'Időzített','published'=>'Publikált','archived'=>'Archivált'];
$canWrite=in_array($u['role'],['platform_admin','site_admin','editor','author'],true);
?><!doctype html><html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Áttekintés | comesy</title>
<link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/dashboard-v2.css"><script src="/assets/ui.js" defer></script>
</head><body><?php require __DIR__.'/app/header.php';?>
<main class="dashboard ui-dashboard" id="main-content">
 <header class="ui-page-header">
  <div class="ui-page-heading"><p class="ui-eyebrow">Kiadói munkaterület</p><h1 class="ui-page-title">Áttekintés</h1>
   <p class="ui-page-description">A tartalmaid, a webhelyeid és a szerkesztőségi feladatok egy helyen.</p>
  </div>
  <?php if($canWrite):?><div class="ui-page-actions"><a href="/editor.php" class="ui-btn ui-btn-primary"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Új cikk</a></div><?php endif;?>
 </header>
 <?php if($u['role']==='platform_admin'&&!article_sites_ready(db())):?><div class="ui-setup-notice" role="status"><svg class="ui-icon" aria-hidden="true"><use href="#cs-shield"></use></svg><div><strong>Adatbázis-migráció szükséges</strong><p>A többwebhelyes cikkkezelés befejezéséhez futtasd a migrációt.</p></div><a href="/migrate.php">Migráció →</a></div><?php endif;?>
 <?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
 <section class="ui-kpi-grid" aria-label="Tartalmi áttekintés">
  <a class="ui-kpi-card" href="/articles.php">
   <div class="ui-kpi-top"><span>Cikkek</span><span class="ui-kpi-icon"><svg class="ui-icon" aria-hidden="true"><use href="#cs-file"></use></svg></span></div>
   <strong class="ui-kpi-value"><?=(int)$articleCount?></strong><span class="ui-kpi-foot">Összes elérhető <span aria-hidden="true">↗</span></span>
  </a>
  <a class="ui-kpi-card" href="/articles.php?status=review">
   <div class="ui-kpi-top"><span>Ellenőrzésre vár</span><span class="ui-kpi-icon review"><svg class="ui-icon" aria-hidden="true"><use href="#cs-shield"></use></svg></span></div>
   <strong class="ui-kpi-value"><?=(int)$reviewCount?></strong><span class="ui-kpi-foot">Szerkesztőségi munkasor <span aria-hidden="true">↗</span></span>
  </a>
  <a class="ui-kpi-card" href="<?=$u['role']==='platform_admin'?'/sites.php':'/articles.php'?>">
   <div class="ui-kpi-top"><span>Webhelyek</span><span class="ui-kpi-icon"><svg class="ui-icon" aria-hidden="true"><use href="#cs-globe"></use></svg></span></div>
   <strong class="ui-kpi-value"><?=(int)$siteCount?></strong><span class="ui-kpi-foot">Elérhető munkaterületek <span aria-hidden="true">↗</span></span>
  </a>
  <a class="ui-kpi-card" href="/media.php">
   <div class="ui-kpi-top"><span>Médiaelemek</span><span class="ui-kpi-icon"><svg class="ui-icon" aria-hidden="true"><use href="#cs-image"></use></svg></span></div>
   <strong class="ui-kpi-value"><?=(int)$mediaCount?></strong><span class="ui-kpi-foot">Médiatár megnyitása <span aria-hidden="true">↗</span></span>
  </a>
 </section>
 <div class="ui-dashboard-columns">
  <section class="ui-panel ui-recent-panel" aria-labelledby="recent-title">
   <div class="ui-panel-header"><div><h2 id="recent-title">Legutóbbi cikkek</h2><p class="ui-panel-subtitle">Legutóbb módosított tartalmak</p></div><a href="/articles.php" class="ui-panel-link">Összes cikk <span aria-hidden="true">↗</span></a></div>
   <?php if($recent):?><div class="ui-row-list">
   <?php foreach($recent as $a):?>
    <article class="ui-row">
     <div class="ui-row-main">
      <?php if($canWrite&&$a['editable']):?><a class="ui-row-title" href="/editor.php?id=<?=(int)$a['id']?>"><?=h($a['title'])?></a><?php else:?><span class="ui-row-title"><?=h($a['title'])?></span><?php endif;?>
      <p class="ui-row-meta">Módosítva: <?=h($a['updated_at'])?></p>
     </div>
     <span class="ui-status ui-status-<?=h($a['status'])?>"><?=h($labels[$a['status']]??$a['status'])?></span>
    </article>
   <?php endforeach;?></div>
   <?php else:?><div class="ui-empty-state"><div class="ui-empty-icon"><svg class="ui-icon" aria-hidden="true"><use href="#cs-file"></use></svg></div><strong>Még nincs cikk a munkaterületen</strong><p>A legutóbb módosított cikkek itt jelennek majd meg.</p><?php if($canWrite):?><a class="ui-btn ui-btn-secondary" href="/editor.php">Első cikk létrehozása</a><?php endif;?></div><?php endif;?>
  </section>
  <div class="ui-dashboard-aside">
   <section class="ui-panel" aria-labelledby="queue-title">
    <div class="ui-panel-header"><div><h2 id="queue-title">Ellenőrzésre vár</h2><p class="ui-panel-subtitle">A szerkesztőségi munkasor</p></div><a href="/articles.php?status=review" class="ui-panel-link">Összes</a></div>
    <?php if($reviewQueue):?>
    <?php foreach($reviewQueue as $item):?><article class="ui-row"><div class="ui-row-main"><?php if($canWrite&&$item['editable']):?><a href="/editor.php?id=<?=(int)$item['id']?>" class="ui-row-title"><?=h($item['title'])?></a><?php else:?><span class="ui-row-title"><?=h($item['title'])?></span><?php endif;?><p class="ui-row-meta"><?=h($item['updated_at'])?></p></div><span class="ui-queue-dot" aria-label="Ellenőrzésre vár"></span></article><?php endforeach;?>
    <?php else:?><div class="ui-empty-state ui-empty-compact"><strong>Nincs függőben lévő cikk</strong><p>Jelenleg nincs új ellenőrzési feladat.</p></div><?php endif;?>
   </section>
   <section class="ui-panel ui-shortcuts" aria-labelledby="shortcuts-title">
    <div class="ui-panel-header"><h2 id="shortcuts-title">Gyors elérés</h2></div>
    <?php if($canWrite):?><a href="/editor.php"><span>Új cikk írása</span><svg class="ui-icon" aria-hidden="true"><use href="#cs-arrow"></use></svg></a><?php endif;?>
    <a href="/media.php"><span>Médiatár megnyitása</span><svg class="ui-icon" aria-hidden="true"><use href="#cs-arrow"></use></svg></a>
    <?php if($canWrite):?><a href="/taxonomy.php"><span>Kategóriák és címkék</span><svg class="ui-icon" aria-hidden="true"><use href="#cs-arrow"></use></svg></a><?php endif;?>
   </section>
  </div>
 </div>
</main></body></html>