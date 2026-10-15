<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
$u=require_user();
if(!in_array($u['role'],['platform_admin','site_admin'],true)){http_response_code(403);exit('Nincs auditnapló-hozzáférés.');}
$pdo=db();$siteChoices=allowed_primary_sites($pdo,$u);
if(!$siteChoices&&$u['role']!=='platform_admin'){http_response_code(403);exit('Nincs naplózható webhely.');}
$site=max(0,(int)($_GET['site_id']??0));$action=trim((string)($_GET['action']??''));$actor=max(0,(int)($_GET['user_id']??0));
$start=trim((string)($_GET['from']??''));$end=trim((string)($_GET['to']??''));
if($site&&$u['role']!=='platform_admin'&&!in_array($site,$siteChoices,true)){http_response_code(403);exit('Nincs jogosultság.');}
if(!preg_match('/^[a-z.]{0,60}$/',$action))$action='';
foreach(['start','end'] as $key)if($$key!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$key))$$key='';
$page=max(1,min(5000,(int)($_GET['page']??1)));$limit=50;
$where=[];$bind=[];
if($site){$where[]="CAST(JSON_UNQUOTE(JSON_EXTRACT(l.meta_json,'$.site_id')) AS UNSIGNED)=?";$bind[]=$site;}
elseif($u['role']!=='platform_admin'){
 $ids=implode(',',array_fill(0,count($siteChoices),'?'));
 $where[]="CAST(JSON_UNQUOTE(JSON_EXTRACT(l.meta_json,'$.site_id')) AS UNSIGNED) IN ($ids)";
 $bind=array_merge($bind,$siteChoices);
}
if($action!==''){$where[]='l.action LIKE ?';$bind[]=$action.'%';}
if($actor){$where[]='l.user_id=?';$bind[]=$actor;}
if($start!==''){$where[]='l.created_at>=?';$bind[]=$start.' 00:00:00';}
if($end!==''){$where[]='l.created_at<?';$bind[]=(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
$filter=$where?' WHERE '.implode(' AND ',$where):'';
$q=$pdo->prepare('SELECT COUNT(*) FROM audit_log l'.$filter);$q->execute($bind);$total=(int)$q->fetchColumn();
$q=$pdo->prepare('SELECT l.id,l.action,l.object_type,l.object_id,l.meta_json,l.created_at,u.name actor_name FROM audit_log l LEFT JOIN users u ON u.id=l.user_id'.$filter.' ORDER BY l.created_at DESC,l.id DESC LIMIT '.$limit.' OFFSET '.(($page-1)*$limit));
$q->execute($bind);$events=$q->fetchAll();
$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
$actors=$pdo->query('SELECT id,name FROM users ORDER BY name')->fetchAll();
$common=['article'=>'Cikk','taxonomy'=>'Besorolás','site'=>'Webhely','user'=>'Felhasználó','site_template'=>'Sablon','import_job'=>'Import','creative'=>'Kreatív','campaign'=>'Kampány'];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Auditnapló | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/audit.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-audit-page" id="main-content"><header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Rendszerbiztonság</p><h1 class="ui-page-title">Auditnapló</h1><p class="ui-page-description">Szerkesztési, jogosultsági, hirdetési, sablon- és importműveletek időrendben.</p></div></header>
<form method="get" class="ui-audit-filters">
<label>Webhely <select name="site_id"><option value="0">Összes engedélyezett</option><?php foreach($sites as $s):if($u['role']!=='platform_admin'&&!in_array((int)$s['id'],$siteChoices,true))continue;?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label>
<label>Művelet <input name="action" maxlength="60" value="<?=h($action)?>" placeholder="Például article."></label>
<label>Felhasználó <select name="user_id"><option value="0">Összes</option><?php foreach($actors as $a):?><option value="<?=(int)$a['id']?>" <?=$actor===(int)$a['id']?'selected':''?>><?=h($a['name'])?></option><?php endforeach;?></select></label>
<label>Dátumtól <input type="date" name="from" value="<?=h($start)?>"></label><label>Dátumig <input type="date" name="to" value="<?=h($end)?>"></label>
<button type="submit" class="ui-btn ui-btn-primary">Szűrés</button><a href="/audit.php" class="ui-btn ui-btn-secondary">Törlés</a></form>
<section class="ui-panel ui-audit-results"><div class="ui-panel-header"><h2>Rögzített események</h2><span><?=number_format($total,0,',',' ')?> találat</span></div>
<?php if(!$events):?><p class="ui-page-description">Nincs a szűrőknek megfelelő naplóesemény.</p><?php endif;?>
<div class="ui-audit-events"><?php foreach($events as $event):
 $meta=json_decode((string)($event['meta_json']??''),true);if(!is_array($meta))$meta=[];
 $safeMeta=[];
 foreach($meta as $key=>$value)if(in_array((string)$key,['site_id','revision','status','template','role','active','items','source_url','job_id','flags','domain'],true))$safeMeta[$key]=$value;
?><article class="ui-audit-event"><div class="ui-audit-event-main">
<strong><?=h($event['action'])?></strong><span><?=h($event['actor_name']??'Rendszer / ismeretlen')?></span>
<small><?=h($common[$event['object_type']]??$event['object_type'])?> #<?=(int)$event['object_id']?><?php if($safeMeta):?> · <?=h(mb_substr(json_encode($safeMeta,JSON_UNESCAPED_UNICODE),0,350))?><?php endif;?></small>
</div><time datetime="<?=h(str_replace(' ','T',$event['created_at']))?>"><?=h($event['created_at'])?></time></article><?php endforeach;?></div>
<?php if($total>$limit):?><nav class="ui-audit-pages" aria-label="Napló lapozása">
<?php if($page>1):?><a href="?<?=h(http_build_query(array_merge($_GET,['page'=>$page-1])))?>">← Előző</a><?php endif;?><span><?=$page?> / <?=ceil($total/$limit)?></span><?php if($page*$limit<$total):?><a href="?<?=h(http_build_query(array_merge($_GET,['page'=>$page+1])))?>">Következő →</a><?php endif;?></nav><?php endif;?>
</section>
<p class="ui-page-description">Az auditnaplóban szereplő személyes adatok tárolási idejét és megőrzési szabályait az adatkezelési előírások szerint kell beállítani.</p></main></body></html>