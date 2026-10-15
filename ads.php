<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/ad-actions.php';
$u=require_user();$error='';$message='';$sites=[];$allowed=[];$advertisers=[];$zones=[];$campaigns=[];$creatives=[];
$site=(int)($_REQUEST['site_id']??0);
$kind=(string)($_GET['kind']??'campaign');if(!in_array($kind,['campaign','zone','advertiser','creative'],true))$kind='campaign';
$editId=max(0,(int)($_GET['edit']??0));
try {
 $pdo=db();$allowed=ad_manage_sites($pdo,$u);if(!$allowed){http_response_code(403);exit('Nincs hirdetéskezelési jogosultság.');}
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 if(!$site)$site=$allowed[0];
 ad_require_site($pdo,$u,$site);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  ad_mutate($pdo,$u,$site,$_POST);
  header('Location: /ads.php?'.http_build_query(['site_id'=>$site,'kind'=>(string)($_POST['entity']??'campaign'),'saved'=>1]));exit;
 }
 $q=$pdo->prepare('SELECT * FROM ad_advertisers WHERE site_id=? ORDER BY name');$q->execute([$site]);$advertisers=$q->fetchAll();
 $q=$pdo->prepare('SELECT * FROM ad_zones WHERE site_id=? ORDER BY name');$q->execute([$site]);$zones=$q->fetchAll();
 $q=$pdo->prepare('SELECT c.*,a.name advertiser_name FROM ad_campaigns c JOIN ad_advertisers a ON a.id=c.advertiser_id WHERE c.site_id=? ORDER BY c.updated_at DESC');$q->execute([$site]);$campaigns=$q->fetchAll();
 $q=$pdo->prepare('SELECT cr.*,c.name campaign_name,z.name zone_name FROM ad_creatives cr JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id WHERE c.site_id=? ORDER BY cr.id DESC');$q->execute([$site]);$creatives=$q->fetchAll();
 $edit=null;$collection=['advertiser'=>$advertisers,'zone'=>$zones,'campaign'=>$campaigns,'creative'=>$creatives][$kind];
 foreach($collection as $row)if((int)$row['id']===$editId)$edit=$row;
 if($editId&&!$edit)throw new RuntimeException('Nem található a szerkesztendő elem.');
}catch(Throwable $e){
 error_log('ads: '.$e->getMessage());
 $error=$e instanceof PDOException?'A hirdetésmodul adattáblái még nem állnak rendelkezésre. Futtasd a szükséges migrációt.':$e->getMessage();
}
function ad_value(?array $row,string $key,string $default=''):string{return h((string)($row[$key]??$default));}
function ad_time_value(?string $v):string{return $v?substr(str_replace(' ','T',$v),0,16):'';}
$types=['campaign'=>'Kampányok','zone'=>'Zónák','advertiser'=>'Hirdetők','creative'=>'Kreatívok'];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hirdetésadmin | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/ads.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-ads-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Monetizáció</p><h1 class="ui-page-title">Hirdetésadmin</h1><p class="ui-page-description">Hirdetők, kampányok, site- és rovatfüggő zónák, kreatívok és kiszolgálási adatok.</p></div></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status">A hirdetéskezelési változást mentettük.</p><?php endif;?>
<form method="get" class="ui-filterbar"><input type="hidden" name="kind" value="<?=h($kind)?>"><label class="ui-field">Webhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label></form>
<nav class="ui-tabbar" aria-label="Hirdetéskezelési modulok"><?php foreach($types as $k=>$label):?><a href="/ads.php?site_id=<?=$site?>&amp;kind=<?=h($k)?>" class="<?=$kind===$k?'is-active':''?>" <?=$kind===$k?'aria-current="page"':''?>><?=h($label)?></a><?php endforeach;?></nav>
<div class="ui-ads-metrics">
<div class="ui-ads-metric"><strong><?=count($campaigns)?></strong><span>Kampány</span></div>
<div class="ui-ads-metric"><strong><?=count(array_filter($campaigns,fn($c)=>$c['state']==='active'))?></strong><span>Aktív</span></div>
<div class="ui-ads-metric"><strong><?=array_sum(array_map(fn($c)=>(int)$c['slot_requests'],$creatives))?></strong><span>Zónakérések</span></div>
<div class="ui-ads-metric"><strong><?=array_sum(array_map(fn($c)=>(int)$c['clicks'],$creatives))?></strong><span>Kattintások</span></div>
</div>
<div class="ui-ads-grid"><section class="ui-panel ui-ads-list">
<div class="ui-panel-header"><h2><?=h($types[$kind])?></h2><a class="ui-btn ui-btn-secondary" href="/ads.php?site_id=<?=$site?>&amp;kind=<?=h($kind)?>">+ Új</a></div>
<?php $list=['advertiser'=>$advertisers,'zone'=>$zones,'campaign'=>$campaigns,'creative'=>$creatives][$kind];?>
<?php if(!$list):?><p class="ui-empty-state">Még nincs elem. A jobb oldali űrlappal felvehetsz egyet.</p><?php endif;?>
<div class="ui-ads-rows"><?php foreach($list as $row):
 $sub=$kind==='campaign'?($row['advertiser_name'].' · '.$row['state'].' · P'.$row['priority']):($kind==='creative'?($row['campaign_name'].' · '.$row['zone_name']):($kind==='zone'?($row['code'].' · '.$row['placement']):($row['contact_email']??'')));
?><article class="ui-ads-row"><div><strong><?=h($row['name'])?></strong><small><?=h($sub)?></small><?php if($kind==='creative'):?><small><?=number_format((int)$row['slot_requests'],0,',',' ')?> zónakérés · <?=number_format((int)$row['clicks'],0,',',' ')?> kattintás</small><?php endif;?></div><a href="/ads.php?site_id=<?=$site?>&amp;kind=<?=h($kind)?>&amp;edit=<?=(int)$row['id']?>">Szerkesztés</a></article><?php endforeach;?></div>
</section>
<section class="ui-panel ui-ads-editor"><h2><?=$edit?'Szerkesztés':'Új '.(['campaign'=>'kampány','zone'=>'zóna','advertiser'=>'hirdető','creative'=>'kreatív'][$kind])?></h2>
<form method="post" class="ui-ads-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="entity" value="<?=h($kind)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=(int)($edit['id']??0)?>">
<label class="ui-field">Név <input name="name" required minlength="2" maxlength="190" value="<?=ad_value($edit,'name')?>"></label>
<?php if($kind==='advertiser'):?><label class="ui-field">Kapcsolattartó e-mail <input type="email" name="email" value="<?=ad_value($edit,'contact_email')?>"></label><?php endif;?>
<?php if($kind==='zone'):?>
<label class="ui-field">Zónakód <input name="code" maxlength="100" value="<?=ad_value($edit,'code')?>" placeholder="Automatikusan a névből"></label>
<label class="ui-field">Elhelyezés <select name="placement"><?php foreach(['home'=>'Nyitóoldal','section'=>'Rovat','article'=>'Cikk','sidebar'=>'Oldalsáv','video'=>'Videó','layer'=>'Layer'] as $val=>$label):?><option value="<?=h($val)?>" <?=($edit['placement']??'article')===$val?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
<?php endif;?>
<?php if($kind==='campaign'):?>
<label class="ui-field">Hirdető <select name="advertiser_id" required><option value="">Válassz hirdetőt…</option><?php foreach($advertisers as $a):?><option value="<?=(int)$a['id']?>" <?=((int)($edit['advertiser_id']??0)===(int)$a['id'])?'selected':''?>><?=h($a['name'])?></option><?php endforeach;?></select></label>
<label class="ui-field">Állapot <select name="state"><?php foreach(['draft'=>'Piszkozat','active'=>'Aktív','paused'=>'Szüneteltetett','finished'=>'Befejezett'] as $v=>$label):?><option value="<?=h($v)?>" <?=($edit['state']??'draft')===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
<label class="ui-field">Prioritás (0–100) <input type="number" min="0" max="100" name="priority" value="<?=ad_value($edit,'priority','50')?>"></label>
<label class="ui-field">Rovat slug (üres = összes rovat) <input name="section_slug" maxlength="190" value="<?=ad_value($edit,'section_slug')?>"></label>
<label class="ui-field">Kezdés <input type="datetime-local" name="starts_at" value="<?=h(ad_time_value($edit['starts_at']??null))?>"></label>
<label class="ui-field">Befejezés <input type="datetime-local" name="ends_at" value="<?=h(ad_time_value($edit['ends_at']??null))?>"></label>
<?php endif;?>
<?php if($kind==='creative'):?>
<label class="ui-field">Kampány <select name="campaign_id" required><option value="">Válassz…</option><?php foreach($campaigns as $c):?><option value="<?=(int)$c['id']?>" <?=((int)($edit['campaign_id']??0)===(int)$c['id'])?'selected':''?>><?=h($c['name'])?></option><?php endforeach;?></select></label>
<label class="ui-field">Zóna <select name="zone_id" required><option value="">Válassz…</option><?php foreach($zones as $z):?><option value="<?=(int)$z['id']?>" <?=((int)($edit['zone_id']??0)===(int)$z['id'])?'selected':''?>><?=h($z['name'])?> (<?=h($z['code'])?>)</option><?php endforeach;?></select></label>
<label class="ui-field">Kép URL (HTTPS ajánlott) <input type="url" name="image_url" required maxlength="1000" placeholder="https://cdn.example.hu/kreativ.jpg" value="<?=ad_value($edit,'image_url')?>"></label>
<label class="ui-field">Cél URL <input type="url" name="destination_url" required maxlength="1000" value="<?=ad_value($edit,'destination_url')?>"></label>
<label class="ui-field">Alternatív szöveg <input name="alt_text" required maxlength="500" value="<?=ad_value($edit,'alt_text')?>"></label>
<label class="ui-check"><input type="checkbox" name="enabled" value="1" <?=!$edit||!empty($edit['enabled'])?'checked':''?>> Engedélyezve</label>
<?php endif;?>
<div class="ui-ads-actions"><button type="submit" class="ui-btn ui-btn-primary">Mentés</button><?php if($edit):?><a href="/ads.php?site_id=<?=$site?>&amp;kind=<?=h($kind)?>" class="ui-btn ui-btn-secondary">Mégse</a><?php endif;?></div></form>
<?php if($edit):?><form method="post" class="ui-ads-delete" onsubmit="return confirm('Biztosan törlöd ezt az elemet? A kapcsolódó hirdetési rekordok is érintettek lehetnek.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="entity" value="<?=h($kind)?>"><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><input type="hidden" name="action" value="delete"><button type="submit" class="ui-btn ui-btn-danger">Elem törlése</button></form><?php endif;?>
</section></div>
<section class="ui-panel ui-ads-doc"><h2>Megjelenítési kód</h2><p>Publikált zónához illeszd be a következő iframe-et a kiadói frontendbe. A választás webhelyhez, aktív kampányhoz, dátumhoz, rovathoz és prioritáshoz kötött.</p><code>&lt;iframe src="https://cms.8media.hu/ad-slot.php?site_id=<?=$site?>&amp;zone=ZONAKOD" loading="lazy" title="Hirdetés" style="width:100%;border:0;min-height:250px"&gt;&lt;/iframe&gt;</code><p>A kérések száma nem viewability-impression. Felhasználói azonosító, harmadik féltől származó követőkód és személyalapú célzás nincs a kezdeti kiszolgálóban.</p></section>
</main></body></html>