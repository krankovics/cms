<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/article-sites.php';
$u=require_user();
$error='';$siteCount=0;$articleCount=0;$reviewCount=0;$mediaCount=0;$recent=[];
try {
 $pdo=db();$allowed=allowed_primary_sites($pdo,$u);
 $siteCount=count($allowed);
 if($allowed){
  $ids=implode(',',array_map('intval',$allowed));
  $authorScope=$u['role']==='author'?' AND author_id='.(int)$u['id']:''; 
  $visible=article_sites_ready($pdo)?"(site_id IN ($ids) OR EXISTS(SELECT 1 FROM article_sites asite WHERE asite.article_id=articles.id AND asite.site_id IN ($ids)))":"site_id IN ($ids)";
  $articleCount=(int)$pdo->query("SELECT COUNT(*) FROM articles WHERE $visible$authorScope")->fetchColumn();
  $reviewCount=(int)$pdo->query("SELECT COUNT(*) FROM articles WHERE site_id IN ($ids)$authorScope AND status='review'")->fetchColumn();
  $mediaSites=allowed_resource_sites($pdo,$u,'media');
  if($mediaSites){$mediaIds=implode(',',array_map('intval',$mediaSites));$mediaCount=(int)$pdo->query("SELECT COUNT(*) FROM media_assets WHERE site_id IN ($mediaIds)")->fetchColumn();}
  $recent=$pdo->query("SELECT id,title,status,updated_at FROM articles WHERE site_id IN ($ids)$authorScope ORDER BY updated_at DESC LIMIT 5")->fetchAll();
 }
}catch(Throwable $e){error_log('comesy dashboard: '.$e->getMessage());$error='Az összesítések most nem tölthetők be.';}
$labels=['draft'=>'Piszkozat','review'=>'Ellenőrzésre vár','scheduled'=>'Időzített','published'=>'Publikált','archived'=>'Archivált'];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Áttekintés | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script>
<style>body{margin:0;font-family:system-ui}.dashboard{max-width:1120px;margin:0 auto;padding:22px}.dashboard h1{font-size:clamp(24px,4vw,32px);margin:4px 0 18px}.dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}.dash-card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px}.dash-card h2{font-size:14px;margin:0;color:var(--muted)}.dash-card strong{display:block;font-size:30px;margin:10px 0;font-variant-numeric:tabular-nums}.dash-card a,.dash-panel a{color:var(--blue)}.dash-panel{margin-top:18px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px}.dash-panel h2{margin:0 0 12px;font-size:18px}.dash-item{padding:10px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:12px}.dash-item:last-child{border-bottom:0}.dash-item small{color:var(--muted);white-space:nowrap}@media(max-width:720px){.dashboard{padding:14px}.dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.dash-card{padding:14px}.dash-card strong{font-size:25px}.dash-panel{padding:14px}.dash-item{flex-direction:column;gap:3px}.dash-item small{white-space:normal}}</style>
</head><body><?php require __DIR__.'/app/header.php';?><main class="dashboard"><h1>Áttekintés</h1><?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<div class="dashboard-grid"><section class="dash-card"><h2>Elérhető webhelyek</h2><strong><?=$siteCount?></strong><?php if($u['role']==='platform_admin'):?><a href="/sites.php">Webhelyek →</a><?php endif;?></section><section class="dash-card"><h2>Cikkek</h2><strong><?=$articleCount?></strong><a href="/articles.php">Cikklista →</a></section><section class="dash-card"><h2>Ellenőrzésre vár</h2><strong><?=$reviewCount?></strong><a href="/articles.php?status=review">Megnyitás →</a></section><section class="dash-card"><h2>Médiaelemek</h2><strong><?=$mediaCount?></strong><a href="/media.php">Médiatár →</a></section></div>
<section class="dash-panel"><h2>Legutóbb módosított cikkek</h2><?php foreach($recent as $a):?><div class="dash-item"><?php if(in_array((int)$a['site_id'],$allowed,true)):?><a href="/editor.php?id=<?=(int)$a['id']?>"><?=h($a['title'])?></a><?php else:?><?=h($a['title'])?> <small>(megosztott)</small><?php endif;?><small><?=h($labels[$a['status']]??$a['status'])?> · <?=h($a['updated_at'])?></small></div><?php endforeach;?><?php if(!$recent):?><p>Még nincs megjeleníthető cikk. <a href="/editor.php">Új cikk létrehozása</a></p><?php endif;?></section></main></body></html>