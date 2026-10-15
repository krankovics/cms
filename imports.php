<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';require __DIR__.'/app/import-jobs.php';require __DIR__.'/app/import-health.php';
$u=require_user();$allowed=[];$sites=[];$jobs=[];$items=[];$error='';$due=0;$inProgress=0;$workerStatus=null;$site=(int)($_REQUEST['site_id']??0);
try{
 $pdo=db();$allowed=imp_allowed_sites($pdo,$u);
 if(!$allowed){http_response_code(403);exit('Nincs jogosultság importfeladatok kezeléséhez.');}
 if(!$site)$site=$allowed[0];
 if(!in_array($site,$allowed,true)){http_response_code(403);exit('Ehhez a webhelyhez nincs hozzáférés.');}
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  $job=imp_mutate($pdo,$u,$site,$_POST);
  header('Location: /imports.php?'.http_build_query(['site_id'=>$site,'job_id'=>$job,'saved'=>1]));exit;
 }
 $q=$pdo->prepare('SELECT * FROM import_jobs WHERE site_id=? ORDER BY id DESC LIMIT 100');$q->execute([$site]);$jobs=$q->fetchAll();
 $q=$pdo->prepare("SELECT COALESCE(SUM(i.status='pending' AND i.available_at<=NOW()),0) due,COALESCE(SUM(i.status='processing'),0) in_progress FROM import_items i JOIN import_jobs j ON j.id=i.job_id WHERE j.site_id=? AND j.status IN ('queued','running') AND i.status IN ('pending','processing')");
 $q->execute([$site]);$workload=$q->fetch()?:[];$due=(int)($workload['due']??0);$inProgress=(int)($workload['in_progress']??0);
 $workerStatus=imp_worker_status_read();
 $selected=max(0,(int)($_GET['job_id']??0));
 if($selected){
  $q=$pdo->prepare('SELECT i.id,i.source_url,i.task_type,i.status,i.attempts,i.last_error,i.article_id,i.updated_at FROM import_items i JOIN import_jobs j ON j.id=i.job_id WHERE i.job_id=? AND j.site_id=? ORDER BY i.id DESC LIMIT 60');
  $q->execute([$selected,$site]);$items=$q->fetchAll();
 }
}catch(Throwable $e){error_log('imports admin: '.$e->getMessage());$error=$e instanceof PDOException?'Az import táblái még nem érhetők el. Rendszeradminisztrátorként futtasd az adatbázis-migrációt.':$e->getMessage();}
$labels=['queued'=>'Sorban','running'=>'Folyamatban','paused'=>'Szüneteltetve','finished'=>'Befejezve','failed'=>'Hibás'];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Import | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/imports.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-imports-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalommigráció</p><h1 class="ui-page-title">Importközpont</h1><p class="ui-page-description">Forráscikkek, RSS, sitemap és demóimport. Minden import piszkozatként készül el, az eredeti URL és pillanatkép megőrzésével.</p></div></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status">Az importfeladat módosítását mentettük.</p><?php endif;?>
<?php $workerView=imp_worker_status_view($workerStatus,$due,$inProgress);
$lastBeat=(int)($workerStatus['finished_at']??$workerStatus['started_at']??0); ?>
<section class="ui-panel ui-import-report-panel" aria-label="Importworker állapota">
 <h2>Importworker állapota</h2>
 <p><strong><?=h($workerView['label'])?></strong> · <?=h($workerView['detail'])?></p>
 <p>Utolsó jel: <strong><?=$lastBeat?gmdate('Y-m-d H:i:s',$lastBeat).' UTC':'nincs adat'?></strong>
 · Legutóbb feldolgozott feladatkísérletek: <strong><?=(int)($workerStatus['processed']??0)?></strong>
 · Feldolgozható várakozók ezen a webhelyen: <strong><?=$due?></strong>
 · Folyamatban: <strong><?=$inProgress?></strong>.</p>
 <p class="ui-text-muted">A workerjel egy korábbi kézi futásból is származhat. A cron/supervisor tényleges ütemezése külön szerveroldali ellenőrzést igényel. Ellenőrző parancs: <code>php import-worker.php --health</code>.</p>
</section>
<form method="get" class="ui-filterbar"><label class="ui-field">Célwebhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label></form>
<div class="ui-imports-grid"><section class="ui-panel ui-import-create">
<h2>Új importfeladat</h2>
<form method="post" class="ui-imports-form">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="action" value="create">
<label class="ui-field">Forrás típusa <select name="mode" id="import-mode">
<option value="url">Egy cikk URL-ből</option><option value="rss">RSS / Atom hírfolyam</option>
<option value="sitemap">Sitemap XML</option><option value="site">Teljes webhely, sitemap alapján</option>
<option value="demo">Demó, legfeljebb 10 cikk</option></select></label>
<label class="ui-field">Nyilvános forrás URL <input type="url" name="source_url" required maxlength="1000" placeholder="https://pelda.hu/cikk-vagy-sitemap" autocomplete="url"></label>
<label class="ui-field" id="import-max-field">Legfeljebb ennyi cikk <input type="number" name="max_items" min="1" max="1000000" value="100"><small>A felderítés és feldolgozás háttérfeladat. Nagy köteg előtt 100-as próbaimportot javasolunk.</small></label>
<label class="ui-import-consent"><input name="confirm_rights" type="checkbox" value="1" required><span>Jogosult vagyok a forrástartalom importálására, és ellenőriztem a forrásoldal feltételeit.</span></label>
<button class="ui-btn ui-btn-primary" type="submit">Import indítása</button>
</form>
<p class="ui-import-info">A feladat a feldolgozó sorába kerül. A rendszer nem teszi automatikusan nyilvánossá a cikkeket, és tiszteletben tartja a robots.txt tiltásait.</p>
</section>
<section class="ui-panel ui-import-list"><h2>Importfeladatok</h2>
<?php if(!$jobs):?><div class="ui-empty-state"><strong>Még nincs importfeladat.</strong><p>Hozz létre egy forrást, majd a feldolgozó worker lépésenként feldolgozza.</p></div><?php endif;?>
<?php foreach($jobs as $job):?><article class="ui-import-job"><div class="ui-import-job-main"><strong><a href="/imports.php?site_id=<?=$site?>&amp;job_id=<?=(int)$job['id']?>"><?=h($job['mode'])?> · #<?=(int)$job['id']?></a></strong><small title="<?=h($job['source_url'])?>"><?=h($job['source_url'])?></small><span class="ui-import-state"><?=h($labels[$job['status']]??$job['status'])?></span>
<p><?=number_format((int)$job['completed'],0,',',' ')?> cikk kész · <?=(int)$job['skipped']?> cikk kihagyva · <?=(int)$job['failed']?> hiba · <?=(int)$job['discovered']?> felderített feladat</p>
<?php if($job['last_error']):?><p class="ui-import-error"><?=h($job['last_error'])?></p><?php endif;?></div>
<div class="ui-import-job-actions">
<a class="ui-btn ui-btn-secondary" href="/import-report.php?site_id=<?=$site?>&amp;job_id=<?=(int)$job['id']?>">Minőségriport</a>
<?php
$jobAction=$job['status']==='paused'?'resume':(in_array($job['status'],['queued','running'],true)?'pause':((int)$job['failed']>0?'retry':''));
if($jobAction):
?>
<form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="job_id" value="<?=(int)$job['id']?>"><input type="hidden" name="action" value="<?=h($jobAction)?>"><button type="submit" class="ui-btn ui-btn-secondary"><?=$jobAction==='resume'?'Folytatás':($jobAction==='pause'?'Szüneteltetés':'Sikertelenek újrapróbálása')?></button></form>
<?php endif;?>
<?php if(in_array($job['status'],['finished','failed'],true)&&(int)$job['skipped']>0):?>
<form method="post" onsubmit="return confirm('Csak a hiányos cikktörzs miatt kihagyott forrásokat ellenőrizzük újra. A már elkészült cikkek változatlanok maradnak. Folytatod?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="job_id" value="<?=(int)$job['id']?>"><input type="hidden" name="action" value="retry_skipped"><button type="submit" class="ui-btn ui-btn-secondary">Kihagyott cikkek újraellenőrzése</button></form>
<?php endif;?></div></article><?php endforeach;?>
</section></div>
<?php if($items):?><section class="ui-panel ui-import-items"><h2>Feldolgozási napló · #<?=(int)$selected?></h2><p class="ui-page-description">A sitemapek felderítése nem számít cikkimportnak. Csak a „Cikk elkészült” sorok hoztak létre szerkeszthető piszkozatot.</p>
<div class="ui-import-items-list"><?php foreach($items as $item):
$isDiscovery=$item['task_type']==='discover';
$stateLabels=$isDiscovery?['pending'=>'Sitemap várakozik','processing'=>'Sitemap feldolgozása','completed'=>'Sitemap felderítve','failed'=>'Sitemaphiba','skipped'=>'Sitemap kihagyva']:['pending'=>'Cikk várakozik','processing'=>'Cikk feldolgozása','completed'=>'Cikk elkészült','failed'=>'Cikkhiba','skipped'=>'Cikk kihagyva'];
?><article><div><strong><?=h($stateLabels[$item['status']]??$item['status'])?></strong><small><?=h($item['source_url'])?></small><?php if($item['last_error']):?><small class="ui-import-error"><?=h($item['last_error'])?></small><?php endif;?></div><?php if($item['article_id']):?><a href="/editor.php?id=<?=(int)$item['article_id']?>">Cikk megnyitása</a><?php endif;?></article><?php endforeach;?></div>
</section><?php endif;?>
</main><script>
document.getElementById('import-mode')?.addEventListener('change',function(){
 const field=document.getElementById('import-max-field');
 field.hidden=this.value==='url'||this.value==='demo';
});
</script></body></html>