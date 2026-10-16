<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/ad-actions.php';
$u=require_user();$pdo=db();
$allowed=ad_manage_sites($pdo,$u);
if(!$allowed){http_response_code(403);exit('Nincs analitikai hozzáférés.');}
$site=(int)($_GET['site_id']??$allowed[0]);
ad_require_site($pdo,$u,$site);
$days=(int)($_GET['days']??30);if(!in_array($days,[7,30,90],true))$days=30;
$csv=($_GET['export']??'')==='csv';
$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
$range=gmdate('Y-m-d',time()-($days-1)*86400).' 00:00:00';
$error='';$daily=[];$zones=[];$creatives=[];$topPages=[];$siteViews=[];$summary=['requests'=>0,'renders'=>0,'viewable'=>0,'clicks'=>0,'pageviews'=>0,'engaged'=>0,'seconds'=>0];
try{
 $q=$pdo->prepare("SELECT DATE(created_at) event_day,COUNT(*) requests,COUNT(rendered_at) renders,COUNT(viewable_at) viewable,COUNT(clicked_at) clicks FROM ad_serves WHERE site_id=? AND created_at>=? GROUP BY DATE(created_at) ORDER BY event_day");
 $q->execute([$site,$range]);$delivery=$q->fetchAll();
 foreach($delivery as $d){$daily[$d['event_day']]=['date'=>$d['event_day'],'requests'=>(int)$d['requests'],'renders'=>(int)$d['renders'],'viewable'=>(int)$d['viewable'],'clicks'=>(int)$d['clicks'],'pageviews'=>0];}
 $q=$pdo->prepare("SELECT z.name,z.code,COUNT(s.token) requests,COUNT(s.rendered_at) renders,COUNT(s.viewable_at) viewable,COUNT(s.clicked_at) clicks FROM ad_zones z LEFT JOIN ad_serves s ON s.zone_id=z.id AND s.created_at>=? WHERE z.site_id=? GROUP BY z.id,z.name,z.code ORDER BY requests DESC,z.name");
 $q->execute([$range,$site]);$zones=$q->fetchAll();
 $q=$pdo->prepare("SELECT cr.name,c.name campaign_name,z.code zone_code,COALESCE(cc.format,'image') format,COUNT(s.token) requests,COUNT(s.rendered_at) renders,COUNT(s.viewable_at) viewable,COUNT(s.clicked_at) clicks FROM ad_creatives cr JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id LEFT JOIN ad_code_creatives cc ON cc.creative_id=cr.id LEFT JOIN ad_serves s ON s.creative_id=cr.id AND s.created_at>=? WHERE c.site_id=? GROUP BY cr.id,cr.name,c.name,z.code,cc.format ORDER BY requests DESC,cr.name LIMIT 100");
 $q->execute([$range,$site]);$creatives=$q->fetchAll();
 $q=$pdo->prepare("SELECT event_date,SUM(pageviews) pageviews,SUM(engaged_views) engaged,SUM(engaged_seconds) seconds FROM cms_page_analytics_daily WHERE site_id=? AND event_date>=DATE(?) GROUP BY event_date ORDER BY event_date");
 $q->execute([$site,$range]);$siteViews=$q->fetchAll();
 foreach($siteViews as $v){$d=$v['event_date'];if(!isset($daily[$d]))$daily[$d]=['date'=>$d,'requests'=>0,'renders'=>0,'viewable'=>0,'clicks'=>0,'pageviews'=>0];$daily[$d]['pageviews']=(int)$v['pageviews'];$summary['engaged']+=(int)$v['engaged'];$summary['seconds']+=(int)$v['seconds'];}
 ksort($daily);
 $q=$pdo->prepare("SELECT page_path,page_kind,SUM(pageviews) pageviews,SUM(engaged_views) engaged FROM cms_page_analytics_daily WHERE site_id=? AND event_date>=DATE(?) GROUP BY page_path,page_kind ORDER BY pageviews DESC LIMIT 20");
 $q->execute([$site,$range]);$topPages=$q->fetchAll();
 foreach($daily as $v)foreach(['requests','renders','viewable','clicks','pageviews'] as $k)$summary[$k]+=(int)$v[$k];
}catch(PDOException $e){error_log('comesy ad-analytics: '.$e->getMessage());$error='A részletes statisztikai táblák még nem elérhetők. Futtasd az adatbázis-migrációt.';}
$rate=static fn(int $a,int $b):string=>$b>0?number_format(100*$a/$b,1,',',' ').'%':'—';
$num=static fn($v):string=>number_format((int)$v,0,',',' ');
if($csv){
 if($error){http_response_code(503);exit($error);}
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="comesy-analytics-'.$site.'-'.$days.'d.csv"');
 $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
 fputcsv($out,['Nap','Hirdetéskérések','Megjelenítések','Látható hirdetések (becsült)','Kattintások','Oldalmegtekintések'],';');
 foreach($daily as $d)fputcsv($out,[$d['date'],$d['requests'],$d['renders'],$d['viewable'],$d['clicks'],$d['pageviews']],';');
 fclose($out);exit;
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Analitika | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/analytics-dashboard.css?v=1"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-analytics-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Monetizáció / AdBrain-alapú mérés</p><h1 class="ui-page-title">Hirdetési és tartalmi analitika</h1><p class="ui-page-description">Webhelyenkénti request, render, közelítő viewability, kattintás és cookie-mentes oldalmegtekintés.</p></div>
<div class="ui-page-actions"><a class="ui-btn ui-btn-secondary" href="/ads.php?site_id=<?=$site?>">Hirdetésadmin ↗</a><a class="ui-btn ui-btn-secondary" href="/ad-analytics.php?site_id=<?=$site?>&amp;days=<?=$days?>&amp;export=csv">CSV export ↧</a></div></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<form method="get" class="ui-analytics-filter"><label>Webhely<select name="site_id"><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=(int)$s['id']===$site?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label><label>Időszak<select name="days"><?php foreach([7,30,90] as $d):?><option value="<?=$d?>" <?=$d===$days?'selected':''?>>Elmúlt <?=$d?> nap</option><?php endforeach;?></select></label><button class="ui-btn ui-btn-primary" type="submit">Szűrés</button></form>
<div class="ui-analytics-kpi">
<?php foreach([['Pageview',$summary['pageviews']],['Hirdetéskérés',$summary['requests']],['Render',$summary['renders']],['Viewable (becslés)',$summary['viewable']],['Kattintás',$summary['clicks']]] as [$label,$value]):?><article class="ui-analytics-kpi-card"><span><?=h($label)?></span><strong><?=$num($value)?></strong></article><?php endforeach;?></div>
<div class="ui-analytics-rate"><article><strong><?=$rate($summary['renders'],$summary['requests'])?></strong><span>Render arány / kiszolgált kérés</span></article>
<article><strong><?=$rate($summary['viewable'],$summary['renders'])?></strong><span>Közelítő viewability / render</span></article>
<article><strong><?=$rate($summary['clicks'],$summary['renders'])?></strong><span>CTR / render (közvetlen kreatívkattintások)</span></article>
<article><strong><?=$rate($summary['engaged'],$summary['pageviews'])?></strong><span>Aktív oldalletöltések aránya (becslés)</span></article></div>
<section class="ui-panel ui-analytics-graph"><h2>Napi alakulás</h2>
<?php $max=1;foreach($daily as $item)$max=max($max,(int)$item['requests'],(int)$item['pageviews']);?>
<?php if(!$daily):?><p>Erre az időszakra még nincs rögzített adat.</p><?php else:?><div class="ui-analytics-chart" role="img" aria-label="Naponta: oldalmegtekintések és hirdetéskérések">
<?php foreach($daily as $d):?><div class="ui-analytics-bar-day" title="<?=h($d['date'])?>: <?=$num($d['pageviews'])?> oldalmegtekintés, <?=$num($d['requests'])?> hirdetéskérés">
<div class="ui-analytics-bars"><span class="ui-analytics-pv-bar" style="height:<?=min(100,100*(int)$d['pageviews']/$max)?>%"></span><span class="ui-analytics-ad-bar" style="height:<?=min(100,100*(int)$d['requests']/$max)?>%"></span></div><small><?=h(substr($d['date'],5))?></small></div><?php endforeach;?></div>
<p class="ui-analytics-legend"><span>■ Oldalmegtekintés</span><span>■ Hirdetéskérés</span></p><?php endif;?></section>
<div class="ui-analytics-columns"><section class="ui-panel"><h2>Hirdetési pozíciók</h2><div class="ui-analytics-scroll"><table><thead><tr><th>Pozíció</th><th>Kérés</th><th>Render</th><th>Viewable</th><th>Katt.</th></tr></thead><tbody>
<?php foreach($zones as $z):?><tr><td><strong><?=h($z['name'])?></strong><small><?=h($z['code'])?></small></td><td><?=$num($z['requests'])?></td><td><?=$num($z['renders'])?></td><td><?=$num($z['viewable'])?></td><td><?=$num($z['clicks'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="ui-panel"><h2>Legolvasottabb oldalak</h2><div class="ui-analytics-scroll"><table><thead><tr><th>Oldal</th><th>Oldaltípus</th><th>PV</th></tr></thead><tbody>
<?php foreach($topPages as $p):?><tr><td><small><?=h($p['page_path'])?></small></td><td><?=h($p['page_kind'])?></td><td><?=$num($p['pageviews'])?></td></tr><?php endforeach;?></tbody></table></div></section></div>
<section class="ui-panel"><h2>Kreatívok és kampányok</h2><div class="ui-analytics-scroll"><table><thead><tr><th>Kreatív</th><th>Kampány / zóna</th><th>Típus</th><th>Kérés</th><th>Render</th><th>Viewable</th><th>Kattintás</th></tr></thead><tbody>
<?php foreach($creatives as $cr):?><tr><td><?=h($cr['name'])?></td><td><?=h($cr['campaign_name'])?><small><?=h($cr['zone_code'])?></small></td><td><?=h($cr['format']==='code'?'Hirdetési kód':'Kép')?></td><td><?=$num($cr['requests'])?></td><td><?=$num($cr['renders'])?></td><td><?=$num($cr['viewable'])?></td><td><?=$num($cr['clicks'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<p class="ui-analytics-note">A pageview nem egyedi látogató vagy munkamenet. Az ad request nem látható megjelenés. A viewability közelítés, nem akkreditált mérés. A külső kódokon belüli kattintást a CMS nem látja. Személyes azonosító, IP-cím vagy cookie nem kerül az analitikai táblákba. Az adatszolgáltatás az éles, nem előnézeti oldalakra vonatkozik.</p>
</main></body></html>