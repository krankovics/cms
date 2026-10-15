<?php
declare(strict_types=1);
/**
 * Per-job migration quality and resource report.
 * Reports *measured* transfer bytes and worker wall time; machine CPU and
 * external infrastructure billing are deliberately not estimated.
 */
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/import-jobs.php';
$user=require_user();
$pdo=db();
$allowed=imp_allowed_sites($pdo,$user);
$site=(int)($_GET['site_id']??0);
$jobId=max(0,(int)($_GET['job_id']??0));
if(!$allowed||!in_array($site,$allowed,true)){http_response_code(403);exit('Nincs hozzáférés az import jelentéséhez.');}
$q=$pdo->prepare('SELECT id,site_id,source_url,mode,status,max_items,created_at,updated_at,last_error FROM import_jobs WHERE id=? AND site_id=?');
$q->execute([$jobId,$site]);$job=$q->fetch();
if(!$job){http_response_code(404);exit('Nem található importfeladat.');}

$statsSql="SELECT COUNT(*) total,
 SUM(i.status='completed') completed, SUM(i.status='skipped') skipped,
 SUM(i.status='failed') failed, SUM(i.status='pending') pending,
 SUM(i.status='processing') processing,
 COALESCE(SUM(m.response_bytes),0) total_bytes,
 COALESCE(SUM(m.duration_ms),0) total_ms,
 COALESCE(SUM(m.block_count),0) total_blocks,
 COALESCE(SUM(m.image_count),0) total_images,
 COALESCE(SUM(m.link_count),0) total_links,
 SUM(m.item_id IS NOT NULL) measured,
 SUM(m.result='retry') retried,
 SUM(JSON_LENGTH(m.quality_flags)>0) quality_issues,
 SUM(i.task_type='article') articles,
 SUM(i.task_type='discover') discovery
 FROM import_items i LEFT JOIN import_item_metrics m ON m.item_id=i.id
 WHERE i.job_id=? AND i.site_id=?";
$q=$pdo->prepare($statsSql);$q->execute([$jobId,$site]);$stats=$q->fetch()?:[];
$q=$pdo->prepare("SELECT kind,status,COUNT(*) total FROM import_asset_refs WHERE site_id=? AND article_id IN (SELECT article_id FROM import_items WHERE job_id=? AND article_id IS NOT NULL) GROUP BY kind,status");
$q->execute([$site,$jobId]);$references=$q->fetchAll();
$q=$pdo->prepare("SELECT i.id,i.source_url,i.task_type,i.status,i.attempts,i.article_id,i.last_error,
 m.result,m.response_bytes,m.duration_ms,m.block_count,m.image_count,m.link_count,m.quality_flags
 FROM import_items i LEFT JOIN import_item_metrics m ON m.item_id=i.id
 WHERE i.job_id=? AND i.site_id=? ORDER BY i.id DESC LIMIT 100");
$q->execute([$jobId,$site]);$sample=$q->fetchAll();

if(($_GET['format']??'')==='csv'){
 header('Content-Type: text/csv; charset=utf-8');
 header('Content-Disposition: attachment; filename="comesy-import-'.$jobId.'-last-100.csv"');
 header('Cache-Control: no-store, private');
 $out=fopen('php://output','w');
 fprintf($out,"\xEF\xBB\xBF");
 fputcsv($out,['task_id','source_url','type','status','attempts','article_id','result','bytes','duration_ms','blocks','images','links','quality_flags','error'],';','"','');
 foreach($sample as $s){
  $row=[];
  foreach(['id','source_url','task_type','status','attempts','article_id','result','response_bytes','duration_ms','block_count','image_count','link_count','quality_flags','last_error'] as $key){
   $value=(string)($s[$key]??'');
   if(preg_match('/^[\s]*[=+@\-]/u',$value))$value="'".$value;
   $row[]=$value;
  }
  fputcsv($out,$row,';','"','');
 }
 fclose($out);exit;
}
function irn(int|float|null $value):string{return number_format((float)$value,0,',',' ');}
function irpct(float $numerator,float $denominator):string{return $denominator>0?number_format(100*$numerator/$denominator,1,',',' '):'0,0';}
$done=(int)($stats['completed']??0)+(int)($stats['skipped']??0)+(int)($stats['failed']??0);
$total=(int)($stats['total']??0);
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importminőség | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/imports.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-imports-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalommigráció · #<?=$jobId?></p><h1 class="ui-page-title">Importminőség és erőforrások</h1><p class="ui-page-description"><?=h($job['source_url'])?> · <?=h($job['status'])?></p></div>
<div class="ui-page-actions"><a href="/imports.php?site_id=<?=$site?>&amp;job_id=<?=$jobId?>" class="ui-btn ui-btn-secondary">Vissza az importhoz</a><a class="ui-btn ui-btn-primary" href="/import-report.php?site_id=<?=$site?>&amp;job_id=<?=$jobId?>&amp;format=csv">Utolsó 100 tétel CSV</a></div></header>
<section class="ui-import-report-grid" aria-label="Importstatisztikák">
<div class="ui-import-stat"><strong><?=irn($stats['articles']??0)?></strong><span>Felderített cikktétel</span></div>
<div class="ui-import-stat"><strong><?=irn($stats['completed']??0)?></strong><span>Kész</span></div>
<div class="ui-import-stat"><strong><?=irn($stats['skipped']??0)?></strong><span>Kihagyva</span></div>
<div class="ui-import-stat"><strong><?=irn($stats['failed']??0)?></strong><span>Hibás</span></div>
<div class="ui-import-stat"><strong><?=irn(($stats['total_bytes']??0)/1048576)?> MB</strong><span>Letöltött HTML/XML</span></div>
<div class="ui-import-stat"><strong><?=irn(($stats['total_ms']??0)/1000)?> mp</strong><span>Összes mért worker-idő</span></div>
<div class="ui-import-stat"><strong><?=irn($stats['total_blocks']??0)?></strong><span>Importált blokkok</span></div>
<div class="ui-import-stat"><strong><?=irn($stats['quality_issues']??0)?></strong><span>Minőségi jelzés</span></div>
</section>
<section class="ui-panel ui-import-report-panel"><h2>Feldolgozás minősége</h2>
<p><strong><?=irpct($done,$total)?>%</strong> feldolgozott tétel · <?=irn($stats['pending']??0)?> várakozó · <?=irn($stats['processing']??0)?> folyamatban. A felderítő XML-feladatok nem azonosak cikkekkel.</p>
<p><strong><?=irpct((float)($stats['failed']??0),(float)max(1,$done))?>%</strong> végleges feladathiba a feldolgozott tételekhez képest. Minőségi figyelmeztetések: rövid lead, kevés blokk, hiányzó képek vagy címsorok. Ezek szerkesztői ellenőrzésre utalnak, nem automatikus cikkhibák.</p>
<p><strong>API-költség:</strong> ez az importútvonal jelenleg nem hív fizetős AI API-t, ezért a modellhívások díja 0. <strong>CPU, memória, szerver- és hálózati költség:</strong> nincs instrumentálva, így nem számolok becsült forintköltséget. A fenti idő az alkalmazásban mért feladatonkénti összegzett idő, nem a teljes import átfutási ideje.</p>
<?php if($references):?><h3>Forrásképek és belső hivatkozások</h3>
<div class="ui-import-ref-list"><?php foreach($references as $ref):?><span><?=h($ref['kind'])?> · <?=h($ref['status'])?>: <strong><?=irn($ref['total'])?></strong></span><?php endforeach;?></div><?php endif;?></section>
<section class="ui-panel ui-import-report-panel"><h2>Utolsó 100 tétel mintája</h2>
<div class="ui-table-wrap"><table class="ui-import-report-table"><thead><tr><th>Forrás</th><th>Állapot</th><th>Letöltés</th><th>Idő</th><th>Minőség</th></tr></thead><tbody>
<?php foreach($sample as $item):?><tr><td><span class="ui-import-truncate" title="<?=h($item['source_url'])?>"><?=h($item['source_url'])?></span><small><?=h($item['task_type'])?> · #<?=(int)$item['id']?></small></td><td><?=h($item['status'])?><?php if($item['article_id']):?> <a href="/editor.php?id=<?=(int)$item['article_id']?>">Cikk ↗</a><?php endif;?></td><td><?=irn($item['response_bytes']??0)?> B</td><td><?=irn($item['duration_ms']??0)?> ms</td><td><?=h(implode(', ',json_decode((string)($item['quality_flags']??'[]'),true)?:[]))?></td></tr><?php endforeach;?>
</tbody></table></div>
<?php if(!$sample):?><p>Nincs még feldolgozható tétel.</p><?php endif;?></section>
</main></body></html>