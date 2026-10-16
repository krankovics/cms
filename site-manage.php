<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/site-settings.php';
require __DIR__.'/app/site-design-templates.php';
require __DIR__.'/app/layouts.php';
require __DIR__.'/app/template-family.php';
require __DIR__.'/app/native-site-chrome.php';
$u=require_user();
if(!in_array($u['role'],['platform_admin','site_admin','editor'],true)){http_response_code(403);exit('Nincs jogosultság a webhelykezelőhöz.');}
$pdo=db();
$siteId=(int)($_REQUEST['site_id']??0);
if($siteId<1||!in_array($siteId,allowed_primary_sites($pdo,$u),true)){http_response_code(403);exit('Nincs hozzáférés a kiválasztott webhelyhez.');}
$load=$pdo->prepare('SELECT id,name,domain,slug,settings_json FROM sites WHERE id=? LIMIT 1');
$load->execute([$siteId]);$site=$load->fetch();
if(!$site){http_response_code(404);exit('A webhely nem található.');}
$settings=cms_site_settings($site);
$tabs=['appearance'=>'Arculat','templates'=>'Sablonok','menu'=>'Menü','pages'=>'Oldalak','sections'=>'Rovatok','tags'=>'Címkék'];
$tab=(string)($_GET['tab']??'appearance');
if(!isset($tabs[$tab]))$tab='appearance';
$base='/site-manage.php?site_id='.$siteId;
$error='';$notice='';
$canAppearance=$u['role']==='platform_admin';
$menuEditable=!empty($settings['builder_enabled']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!valid_csrf())throw new RuntimeException('A munkamenet lejárt. Frissítsd az oldalt.');
  $action=(string)($_POST['action']??'');
  if($action==='save_menu'){
   if(!$menuEditable)throw new RuntimeException('A menüszerkesztő használatához engedélyezd a webhely oldalépítőjét.');
   $items=json_decode((string)($_POST['menu_json']??'[]'),true,32,JSON_THROW_ON_ERROR);
   if(!is_array($items)||!array_is_list($items))throw new RuntimeException('Érvénytelen menüadat.');
   cms_menu_replace($pdo,$u,$siteId,$items);
   header('Location: '.$base.'&tab=menu&saved=1',true,303);exit;
  }
  if($action==='save_appearance'){
   if(!$canAppearance)throw new RuntimeException('Az arculatot csak platformadminisztrátor módosíthatja.');
   $inputs=[];
   foreach(['publisher_name','site_description','accent_color','logo_url','source_template_id'] as $key)$inputs[$key]=$_POST[$key]??'';
   // Preserve unrelated feature flags. An appearance-only form must never
   // turn off reader accounts, builder, advertising or newsletter functionality.
   foreach(['builder_enabled','reader_accounts_enabled','comments_enabled','newsletter_enabled','ai_enabled','ad_slots_enabled'] as $flag){
    $inputs[$flag]=!empty($settings[$flag])?'1':'0';
   }
   $updated=cms_validate_site_settings($inputs,$settings);
   $previous=(string)($settings['source_template_id']??'');
   $profile=null;
   if(!empty($updated['source_template_id'])&&$previous!==$updated['source_template_id']){
    $profile=cms_design_template_read((string)$updated['source_template_id']);
    if(!$profile)throw new RuntimeException('A kiválasztott forrásarculat nem található.');
    // Fail before modifying site settings if mandatory migration is missing.
    foreach(['site_templates','site_pages','site_template_revisions'] as $table){
     $check=$pdo->query('SHOW TABLES LIKE '.$pdo->quote($table));
     if(!$check->fetchColumn())throw new RuntimeException('Az arculati sablonok aktiválásához először futtasd az adatbázis-migrációt.');
    }
   }
   $pdo->prepare('UPDATE sites SET settings_json=? WHERE id=?')->execute([json_encode($updated,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$siteId]);
   $pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)')->execute([$u['id'],'site.appearance_save','site',$siteId,json_encode(['template_id'=>$updated['source_template_id']??''],JSON_UNESCAPED_UNICODE)]);
   if($profile)cms_template_family_seed($pdo,$siteId,(int)$u['id'],$profile,!empty($updated['reader_accounts_enabled']));
   header('Location: '.$base.'&tab=appearance&saved=1',true,303);exit;
  }
  throw new RuntimeException('Ismeretlen mentési művelet.');
 }catch(Throwable $e){
  error_log('site manager: '.$e->getMessage());
  $error=$e instanceof PDOException?'Nem sikerült menteni. Ellenőrizd az adatbázis-migrációt.':$e->getMessage();
  $tab=$action==='save_menu'?'menu':'appearance';
 }
}
$profile=cms_design_template_for_site($site);
$templateOptions=$canAppearance?cms_design_templates_list():[];
$familyLabels=['home'=>'Nyitóoldal','section'=>'Rovatoldal','tag'=>'Címkeoldal','article'=>'Cikkoldal','page'=>'Statikus oldal','login'=>'Bejelentkezés'];
$familyDescriptions=['home'=>'Kiemelt hírek, friss tartalmak és címlapblokkok','section'=>'Rovathoz tartozó cikkek elrendezése','tag'=>'Címkéhez kapcsolódó tartalmak','article'=>'Cikktörzs és kapcsolódó cikkek','page'=>'Impresszum, kapcsolat és más önálló oldalak','login'=>'Olvasói belépés, ha engedélyezett'];
$familyStates=[];$menu=[];$pages=[];$terms=[];$dataError='';
try{
 if($tab==='templates'){
  $q=$pdo->prepare('SELECT template_key,draft_json,published_json,revision_number FROM site_templates WHERE site_id=?');
  $q->execute([$siteId]);
  foreach($q->fetchAll() as $item)$familyStates[$item['template_key']]=$item;
 }
 if($tab==='menu'){
  $q=$pdo->prepare('SELECT label,url,active,position FROM site_menu_items WHERE site_id=? ORDER BY position,id');
  $q->execute([$siteId]);$menu=$q->fetchAll();
 }
 if($tab==='pages'){
  $q=$pdo->prepare('SELECT id,title,slug,status,updated_at FROM site_pages WHERE site_id=? ORDER BY updated_at DESC,id DESC LIMIT 100');
  $q->execute([$siteId]);$pages=$q->fetchAll();
 }
 if($tab==='sections'||$tab==='tags'){
  $type=$tab==='sections'?'category':'tag';
  $q=$pdo->prepare('SELECT t.id,t.name,t.slug,m.description,(SELECT COUNT(*) FROM article_terms a WHERE a.term_id=t.id) article_count FROM taxonomy_terms t LEFT JOIN taxonomy_term_meta m ON m.term_id=t.id WHERE t.site_id=? AND t.type=? ORDER BY t.name LIMIT 200');
  $q->execute([$siteId,$type]);$terms=$q->fetchAll();
 }
}catch(PDOException $e){
 error_log('site manager data: '.$e->getMessage());
 $dataError='Az oldal adatai jelenleg nem érhetők el. Ellenőrizd az adatbázis-migrációt.';
}
$publicUrl='/public.php?site='.rawurlencode((string)$site['slug']).'&preview=1';
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h((string)$site['name'])?> · Webhelykezelő | comesy</title>
<link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/site-manager.css?v=1">
<script src="/assets/ui.js" defer></script><script src="/assets/site-manager.js?v=1" defer></script></head>
<body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page cs-site-manager" id="main-content">
<header class="ui-page-header">
<div class="ui-page-heading"><p class="ui-eyebrow">Adminisztráció / Webhelykezelő</p>
<h1 class="ui-page-title"><?=h((string)$site['name'])?></h1>
<p class="ui-page-description"><?=h((string)$site['domain'])?> · A webhely minden fő szerkesztési területe egy helyen.</p></div>
<div class="ui-page-actions"><a class="ui-btn ui-btn-secondary" href="/sites.php">Webhelyek</a><a class="ui-btn ui-btn-primary" href="<?=h($publicUrl)?>" target="_blank" rel="noopener noreferrer">Webhely előnézete ↗</a></div>
</header>
<nav class="cs-site-tabs" aria-label="Webhelykezelési területek">
<?php foreach($tabs as $key=>$label):?><a href="<?=h($base.'&tab='.$key)?>" <?=$tab===$key?'aria-current="page" class="is-active"':''?>><?=h($label)?></a><?php endforeach;?></nav>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status">A módosításokat mentettük.</p><?php endif;?>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($dataError):?><p class="ui-notice error" role="alert"><?=h($dataError)?> <a href="/migrate.php">Adatbázis-migráció ↗</a></p><?php endif;?>
<section class="ui-panel cs-site-content" aria-labelledby="cs-section-title">
<?php if($tab==='appearance'):?>
<div class="cs-site-section-head"><div><h2 id="cs-section-title">Arculat és webhelyazonosság</h2><p>Az itt mentett arculatot a webhely minden oldaltípusának megjelenítése használja.</p></div>
<?php if($canAppearance):?><a class="ui-btn ui-btn-secondary" href="/sites.php?edit=<?=$siteId?>">Összes webhelybeállítás ↗</a><?php endif;?></div>
<?php if(!$canAppearance):?><p class="ui-notice">Az arculat szerkesztése platformadminisztrátori jogosultságot igényel.</p><?php endif;?>
<form method="post" class="cs-site-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$siteId?>"><input type="hidden" name="action" value="save_appearance">
<label class="ui-field">Arculati sablon<select name="source_template_id" <?=$canAppearance?'':'disabled'?>><option value="">Webhely alapmegjelenése (nincs importált sablon)</option>
<?php foreach($templateOptions as $option):?><option value="<?=h($option['id'])?>" <?=($settings['source_template_id']??'')===$option['id']?'selected':''?>><?=h($option['title'].' · '.$option['host'])?></option><?php endforeach;?>
<?php if(!$canAppearance&&$profile):?><option selected><?=h((string)($profile['site_design']['name']??$profile['host']))?></option><?php endif;?></select></label>
<label class="ui-field">Kiadó megnevezése<input name="publisher_name" maxlength="190" value="<?=h((string)$settings['publisher_name'])?>" <?=$canAppearance?'':'disabled'?>></label>
<label class="ui-field">Webhely leírása<textarea name="site_description" maxlength="500" rows="3" <?=$canAppearance?'':'disabled'?>><?=h((string)$settings['site_description'])?></textarea></label>
<div class="cs-site-form-pair"><label class="ui-field">Kiemelőszín<input type="color" name="accent_color" value="<?=h((string)$settings['accent_color'])?>" <?=$canAppearance?'':'disabled'?>></label>
<label class="ui-field">Logó URL<input type="url" name="logo_url" maxlength="1000" value="<?=h((string)$settings['logo_url'])?>" <?=$canAppearance?'':'disabled'?>></label></div>
<?php if($canAppearance):?><button class="ui-btn ui-btn-primary" type="submit">Arculat mentése</button><?php endif;?></form>
<?php if($profile):?><p class="cs-site-helper">Hozzárendelt forrás: <strong><?=h((string)$profile['host'])?></strong>. A stílus módosítása nem írja felül az oldalak tartalmát.</p><?php endif;?>
<?php elseif($tab==='templates'):?>
<div class="cs-site-section-head"><div><h2 id="cs-section-title">Oldalsablonok</h2><p>Az oldaltípusok külön változtathatók, az átalakítások a webhelyen közösen érvényesülnek.</p></div>
<a class="ui-btn ui-btn-secondary" href="/builder.php?site_id=<?=$siteId?>&amp;template=home">Oldalépítő megnyitása ↗</a></div>
<div class="cs-site-card-grid"><?php foreach($familyLabels as $key=>$label):?>
<article class="cs-site-card"><div class="cs-site-card-head"><strong><?=h($label)?></strong><span class="cs-site-state"><?=isset($familyStates[$key])?($familyStates[$key]['published_json']?'Publikált':'Piszkozat'):'Alapértelmezett'?></span></div>
<p><?=h($familyDescriptions[$key])?></p>
<a class="ui-btn ui-btn-secondary" href="/builder.php?site_id=<?=$siteId?>&amp;template=<?=h($key)?>">Sablon szerkesztése ↗</a>
</article><?php endforeach;?></div>
<?php if(!$profile):?><p class="cs-site-helper">Az importált arculatot az Arculat fülön rendelheted hozzá, de az alapértelmezett oldalsablonok is szerkeszthetők.</p><?php endif;?>
<?php elseif($tab==='menu'):?>
<div class="cs-site-section-head"><div><h2 id="cs-section-title">Menüszerkesztő</h2><p>A fejléc, a mobilmenü és az oldalnavigáció ugyanazt a központi menüt használja.</p></div>
<a class="ui-btn ui-btn-secondary" href="<?=$publicUrl?>" target="_blank" rel="noopener noreferrer">Menü előnézete ↗</a></div>
<?php if(!$menuEditable):?><p class="ui-notice">A menüszerkesztő használatához előbb engedélyezd a Vizuális oldalépítőt a webhely beállításainál.
<?php if($canAppearance):?><a href="/sites.php?edit=<?=$siteId?>">Bekapcsolás ↗</a><?php endif;?></p><?php endif;?>
<form method="post" id="cs-site-menu-form" data-read-only="<?=$menuEditable?'0':'1'?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$siteId?>"><input type="hidden" name="action" value="save_menu">
<textarea name="menu_json" hidden><?=h(json_encode($menu,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))?></textarea>
<div id="cs-site-menu-rows" class="cs-site-menu-rows"></div>
<div class="cs-site-menu-actions"><button type="button" class="ui-btn ui-btn-secondary" id="cs-site-menu-add" <?=$menuEditable?'':'disabled'?>>+ Menüpont</button>
<button type="submit" class="ui-btn ui-btn-primary" <?=$menuEditable?'':'disabled'?>>Menü mentése</button></div></form>
<p class="cs-site-helper">Belső címek: <code>/</code>, <code>/section/belfold</code>, <code>/tag/magyar-peter</code>, <code>/page/impresszum</code>, <code>/bejelentkezes</code>. A menüpont csak létező céloldalon működik.</p>
<?php elseif($tab==='pages'):?>
<div class="cs-site-section-head"><div><h2 id="cs-section-title">Statikus oldalak</h2><p>Impresszum, adatkezelés, kapcsolat és más önálló oldalak.</p></div>
<a class="ui-btn ui-btn-primary" href="/pages.php?site_id=<?=$siteId?>">+ Új oldal</a></div>
<?php if(!$pages):?><p class="cs-site-empty">Még nincs statikus oldal. Hozz létre egyet az Oldalak szerkesztőjében.</p><?php endif;?>
<div class="cs-site-items"><?php foreach($pages as $page):?><article class="cs-site-item"><div><strong><?=h($page['title'])?></strong><small>/page/<?=h($page['slug'])?> · <?=h($page['status']==='published'?'Publikált':'Piszkozat')?> · <?=h($page['updated_at'])?></small></div>
<div class="cs-site-actions"><a class="ui-btn ui-btn-secondary" href="/pages.php?site_id=<?=$siteId?>&amp;edit=<?=(int)$page['id']?>">Szerkesztés</a><a class="ui-btn ui-btn-secondary" href="<?=h($publicUrl.'&page='.rawurlencode($page['slug']))?>" target="_blank" rel="noopener noreferrer">Előnézet ↗</a></div></article><?php endforeach;?></div>
<?php elseif($tab==='sections'||$tab==='tags'):?>
<?php $isTag=$tab==='tags';$termType=$isTag?'tag':'category';?>
<div class="cs-site-section-head"><div><h2 id="cs-section-title"><?=$isTag?'Címkék':'Rovatok'?></h2><p><?=$isTag?'A cikkek címkéi és kapcsolódó tartalmai.':'A rovatok a kategóriákból épülnek fel.'?></p></div>
<a class="ui-btn ui-btn-primary" href="/taxonomy.php?site_id=<?=$siteId?>&amp;type=<?=$termType?>">+ Új <?=$isTag?'címke':'rovat'?></a></div>
<?php if(!$terms):?><p class="cs-site-empty">Még nincs <?=$isTag?'címke':'rovat'?>. A Kategóriák és címkék menüben hozhatsz létre újat.</p><?php endif;?>
<div class="cs-site-items"><?php foreach($terms as $term):?><article class="cs-site-item"><div><strong><?=h($term['name'])?></strong><small><?=h($term['slug'])?> · <?=(int)$term['article_count']?> kapcsolódó cikk</small></div>
<div class="cs-site-actions"><a class="ui-btn ui-btn-secondary" href="/taxonomy.php?site_id=<?=$siteId?>&amp;type=<?=$termType?>&amp;edit=<?=(int)$term['id']?>">Szerkesztés</a><a class="ui-btn ui-btn-secondary" href="<?=h($publicUrl.'&'.($isTag?'tag':'section').'='.rawurlencode($term['slug']))?>" target="_blank" rel="noopener noreferrer">Előnézet ↗</a></div></article><?php endforeach;?></div>
<?php endif;?>
</section>
</main></body></html>