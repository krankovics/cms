<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/layouts.php';
require_once __DIR__.'/app/template-family.php';
$u=require_user();
if(!in_array($u['role'],['platform_admin','site_admin','editor'],true)){http_response_code(403);exit('Nincs oldalépítő-hozzáférés.');}
$pdo=db();$allowed=allowed_primary_sites($pdo,$u);
if(!$allowed){http_response_code(403);exit('Nincs szerkeszthető webhely.');}
$site=(int)($_REQUEST['site_id']??$allowed[0]);
if(!in_array($site,$allowed,true)){http_response_code(403);exit('Nincs hozzáférés ehhez a webhelyhez.');}
$kind=(string)($_REQUEST['template']??'home');
if(!in_array($kind,['home','section','tag','article','page','login'],true))$kind='home';
$error='';$message='';
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $action=(string)($_POST['action']??'save');
  if($action==='menu'){
   $items=json_decode((string)($_POST['menu_json']??'[]'),true,32,JSON_THROW_ON_ERROR);
   if(!is_array($items)||!array_is_list($items))throw new RuntimeException('Érvénytelen menüadat.');
   cms_menu_replace($pdo,$u,$site,$items);
   header('Location: /builder.php?'.http_build_query(['site_id'=>$site,'template'=>$kind,'saved'=>'menu']));exit;
  }
  $raw=(string)($_POST['layout_json']??'[]');
  $expected=(int)($_POST['revision']??0);
  $revisionId=(int)($_POST['revision_id']??0);
  cms_template_save($pdo,$u,$site,$kind,$action,$raw,$expected,$revisionId);
  header('Location: /builder.php?'.http_build_query(['site_id'=>$site,'template'=>$kind,'saved'=>$action]));exit;
 }
}catch(Throwable $e){error_log('builder: '.$e->getMessage());$error=$e->getMessage();}
$sites=$pdo->query('SELECT id,name,slug,settings_json FROM sites ORDER BY name')->fetchAll();
$sites=array_values(array_filter($sites,fn($s)=>in_array((int)$s['id'],$allowed,true)));
$selected=null;foreach($sites as $s)if((int)$s['id']===$site)$selected=$s;
$flags=cms_site_settings($selected??[]);
$sourceAppearance=$selected?cms_design_template_for_site($selected):null;
$sourceExamples=$sourceAppearance?cms_template_family_sources($sourceAppearance):[];
$referenceUrl=(string)($sourceExamples[$kind]??'');
$schemaReady=true;$record=null;$revisions=[];$menu=[];$layout=cms_default_layout($kind);
try{
 $q=$pdo->prepare('SELECT * FROM site_templates WHERE site_id=? AND template_key=?');$q->execute([$site,$kind]);$record=$q->fetch();
 if($record){$parsed=json_decode((string)$record['draft_json'],true);if(is_array($parsed))$layout=$parsed;}
 $q=$pdo->prepare('SELECT r.id,r.revision_number,r.event,r.created_at,u.name editor FROM site_template_revisions r JOIN site_templates t ON t.id=r.template_id LEFT JOIN users u ON u.id=r.editor_id WHERE t.site_id=? AND t.template_key=? ORDER BY r.revision_number DESC LIMIT 15');
 $q->execute([$site,$kind]);$revisions=$q->fetchAll();
 $q=$pdo->prepare('SELECT id,label,url,position,active FROM site_menu_items WHERE site_id=? ORDER BY position,id');$q->execute([$site]);$menu=$q->fetchAll();
}catch(PDOException $e){
 error_log('builder tables missing: '.$e->getMessage());$schemaReady=false;
 $error='Az oldalépítő táblái még nem érhetők el. A szuperadmin futtassa az adatbázis-migrációt.';
}
$kindLabels=['home'=>'Nyitóoldal','section'=>'Rovatoldal','tag'=>'Címkeoldal','article'=>'Cikkoldal','page'=>'Statikus oldal','login'=>'Olvasói belépés'];
$readOnly=!$flags['builder_enabled']||!$schemaReady;
$previewArgs=['site'=>(string)($selected['slug']??''),'preview'=>'1','template'=>$kind];
if(in_array($kind,['section','tag'],true)){
 try{
  $type=$kind==='tag'?'tag':'category';
  $t=$pdo->prepare('SELECT slug FROM taxonomy_terms WHERE site_id IN (0,?) AND type=? ORDER BY site_id DESC,id LIMIT 1');
  $t->execute([$site,$type]);
  $previewSlug=$t->fetchColumn();
  if($previewSlug)$previewArgs[$kind==='tag'?'tag':'section']=$previewSlug;
 }catch(PDOException $e){error_log('builder page sample: '.$e->getMessage());}
}
if($kind==='page'){
 try{$t=$pdo->prepare('SELECT slug FROM site_pages WHERE site_id=? ORDER BY id LIMIT 1');$t->execute([$site]);$sample=$t->fetchColumn();if($sample)$previewArgs['page']=$sample;}
 catch(PDOException $e){error_log('builder page sample: '.$e->getMessage());}
}
if($kind==='login')$previewArgs['login']='1';
$previewUrl='/public.php?'.http_build_query($previewArgs);
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Oldalépítő | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/builder.css"><script src="/assets/ui.js" defer></script><script src="/assets/builder.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-builder-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Vizuális megjelenés</p><h1 class="ui-page-title">Oldalépítő</h1><p class="ui-page-description">Szerkeszthető komponensek, reszponzív előnézet, külön mentés és publikálás.</p></div><div class="ui-page-actions"><?php if($referenceUrl):?><a class="ui-btn ui-btn-secondary" href="<?=h($referenceUrl)?>" target="_blank" rel="noopener noreferrer">Eredeti <?=$kind==='tag'?'címke':($kind==='section'?'rovat':($kind==='home'?'címlap':($kind==='page'?'oldal':($kind==='login'?'belépés':'cikk'))))?> ↗</a><?php endif;?><a class="ui-btn ui-btn-secondary" href="<?=h($previewUrl)?>" target="_blank" rel="noopener">Előnézet ↗</a></div></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status"><?=h($_GET['saved']==='publish'?'A sablon új verziója publikálva.':($_GET['saved']==='menu'?'A navigáció mentve.':'A sablon változásait elmentettük.'))?></p><?php endif;?>
<?php if($readOnly):?><p class="ui-notice" role="status">Ezen a webhelyen az oldalépítő ki van kapcsolva. A korábban publikált frontend változatlanul működik. <?php if($u['role']==='platform_admin'):?><a href="/sites.php?edit=<?=$site?>">Funkció bekapcsolása a webhelybeállításoknál</a><?php endif;?></p><?php endif;?>
<div class="ui-builder-toolbar"><form method="get"><label class="ui-field">Webhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label>
<label class="ui-field">Sablon <select name="template" onchange="this.form.submit()"><?php foreach($kindLabels as $k=>$label):?><option value="<?=h($k)?>" <?=$kind===$k?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label></form>
<div class="ui-builder-status"><span>Mentett verzió: <?= (int)($record['revision_number']??0)?></span><span><?=!empty($record['published_at'])?'Publikálva: '.h($record['published_at']):'Még nincs publikált sablon'?></span></div></div>
<div class="ui-builder-grid">
<section class="ui-panel ui-builder-main"><div class="ui-panel-header"><h2>Oldalkomponensek</h2><span class="ui-text-muted">Sorrend és beállítások</span></div>
<form method="post" id="builder-layout-form" data-builder-form data-readonly="<?=$readOnly?'1':'0'?>" data-template="<?=h($kind)?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="template" value="<?=h($kind)?>"><input type="hidden" name="revision" value="<?=(int)($record['revision_number']??0)?>">
<input type="hidden" name="layout_json" value="<?=h(json_encode($layout,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>">
<div id="builder-block-list" class="ui-builder-blocks"></div>
<div class="ui-builder-add"><label for="builder-new-type">Komponens hozzáadása</label><select id="builder-new-type" <?=$readOnly?'disabled':''?>><option value="hero">Hero</option><option value="latest">Dinamikus cikklista</option><option value="text">Szövegdoboz</option><option value="image">Kép</option><option value="ad">Hirdetési zóna</option><?php if($kind==='article'):?><option value="article">Cikktartalom</option><?php endif;?><?php if($kind==='page'):?><option value="page_body">Statikus oldal tartalma</option><?php endif;?><?php if($kind==='login'):?><option value="reader_form">Olvasói bejelentkezés</option><?php endif;?></select><button type="button" class="ui-btn ui-btn-secondary" data-builder-add <?=$readOnly?'disabled':''?>>Hozzáadás</button></div>
<div class="ui-builder-actions"><button type="submit" name="action" value="save" class="ui-btn ui-btn-secondary" <?=$readOnly?'disabled':''?>>Piszkozat mentése</button><button type="submit" name="action" value="publish" class="ui-btn ui-btn-primary" <?=$readOnly?'disabled':''?>>Sablon publikálása</button></div>
</form>
</section>
<aside class="ui-builder-aside">
<section class="ui-panel"><h2>Előnézet</h2><p class="ui-page-description">A tartalom a jelenleg mentett vagy publikált cikkekből épül.</p><div class="ui-builder-viewport"><button type="button" data-builder-width="100%" aria-pressed="true">Desktop</button><button type="button" data-builder-width="768px" aria-pressed="false">Tablet</button><button type="button" data-builder-width="390px" aria-pressed="false">Mobil</button></div><div class="ui-builder-frame-wrap"><iframe id="builder-preview-frame" src="<?=h($previewUrl)?>" title="Kiadói sablon előnézete" loading="lazy"></iframe></div><button type="button" class="ui-btn ui-btn-secondary" data-builder-refresh>Előnézet frissítése</button></section>
<section class="ui-panel"><h2>Verziótörténet</h2><?php if(!$revisions):?><p class="ui-page-description">Még nincs mentett sablon.</p><?php endif;?>
<?php foreach($revisions as $rev):?><form method="post" class="ui-builder-revision" onsubmit="return confirm('Ebből a verzióból új piszkozat készüljön?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="template" value="<?=h($kind)?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="revision" value="<?=(int)($record['revision_number']??0)?>"><input type="hidden" name="revision_id" value="<?=(int)$rev['id']?>"><strong>#<?=(int)$rev['revision_number']?> · <?=h($rev['event'])?></strong><small><?=h($rev['created_at'])?> · <?=h($rev['editor']??'Rendszer')?></small><button type="submit" class="ui-btn ui-btn-secondary" <?=$readOnly?'disabled':''?>>Visszaállítás</button></form><?php endforeach;?></section>
</aside></div>
<section class="ui-panel ui-builder-menu"><h2>Webhely navigáció</h2><p class="ui-page-description">Natív menü URL-ek: /section/belfold, /tag/magyar-peter, /page/impresszum, /bejelentkezes. A rovatok és címkék a Kategóriák és címkék menüben, a statikus oldalak az Oldalak menüben szerkeszthetők.</p><p class="ui-page-description">A menüpontok minden publikus oldalon megjelennek, a kézi sorrend a mentéskor rögzül.</p>
<form method="post" id="builder-menu-form" data-readonly="<?=$readOnly?'1':'0'?>"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="template" value="<?=h($kind)?>"><input type="hidden" name="action" value="menu"><input type="hidden" name="menu_json" value="<?=h(json_encode($menu,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?>">
<div id="builder-menu-rows"></div><div class="ui-builder-actions"><button type="button" data-menu-add class="ui-btn ui-btn-secondary" <?=$readOnly?'disabled':''?>>+ Menüpont</button><button type="submit" class="ui-btn ui-btn-primary" <?=$readOnly?'disabled':''?>>Navigáció mentése</button></div>
</form></section>
</main></body></html>