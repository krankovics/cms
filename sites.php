<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-settings.php';
require __DIR__.'/app/site-design-templates.php';
require __DIR__.'/app/site-import-assignment.php';
$u=require_user();
if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$error='';$saved=isset($_GET['saved']);$sites=[];$importCandidates=[];
$appearanceTemplates=cms_design_templates_list();
$templateLabels=array_column($appearanceTemplates,'title','id');
try{
 $pdo=db();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $id=max(0,(int)($_POST['id']??0));
  if($id>0&&($_POST['action']??'')==='link_imported'){
   $siteQuery=$pdo->prepare('SELECT id,domain,slug FROM sites WHERE id=? LIMIT 1');
   $siteQuery->execute([$id]);$sourceSite=$siteQuery->fetch();
   if(!$sourceSite)throw new RuntimeException('A webhely nem található.');
   $linked=cms_site_link_import_candidates($pdo,$sourceSite,(int)$u['id']);
   header('Location: /sites.php?edit='.$id.'&linked='.$linked);exit;
  }
  $name=trim((string)($_POST['name']??''));
  $domain=mb_strtolower(trim((string)($_POST['domain']??'')));
  $slug=mb_strtolower(trim((string)($_POST['slug']??'')));
  if(mb_strlen($name)<2||mb_strlen($name)>190||!preg_match('/^(?=.{3,190}$)[a-z0-9-]+(?:\\.[a-z0-9-]+)+$/D',$domain)||!preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/D',$slug))throw new RuntimeException('Érvénytelen név, domain vagy azonosító.');
  $current=null;
  if($id){$q=$pdo->prepare('SELECT settings_json FROM sites WHERE id=?');$q->execute([$id]);$current=$q->fetch();}
  $settings=cms_validate_site_settings($_POST,$current?cms_site_settings($current):[]);
  $settingsJson=json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  if($id){
   $q=$pdo->prepare('UPDATE sites SET name=?,domain=?,slug=?,settings_json=? WHERE id=?');
   $q->execute([$name,$domain,$slug,$settingsJson,$id]);
   if(!$q->rowCount()){ $check=$pdo->prepare('SELECT id FROM sites WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('A webhely nem található.');}
  }else{
   $q=$pdo->prepare('INSERT INTO sites(name,domain,slug,settings_json) VALUES(?,?,?,?)');$q->execute([$name,$domain,$slug,$settingsJson]);$id=(int)$pdo->lastInsertId();
  }
  $audit=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
  $audit->execute([$u['id'],$current?'site.update':'site.create','site',$id,json_encode(['domain'=>$domain,'flags'=>array_intersect_key($settings,array_fill_keys(['builder_enabled','reader_accounts_enabled','comments_enabled','newsletter_enabled','ai_enabled','ad_slots_enabled'],true)),'source_template_id'=>$settings['source_template_id']??''],JSON_UNESCAPED_UNICODE)]);
  header('Location: /sites.php?saved=1');exit;
 }
 $sites=$pdo->query('SELECT s.id,s.name,s.domain,s.slug,s.settings_json,(SELECT COUNT(*) FROM articles a WHERE a.site_id=s.id OR EXISTS (SELECT 1 FROM article_sites asite WHERE asite.article_id=a.id AND asite.site_id=s.id)) article_count FROM sites s ORDER BY s.name')->fetchAll();
}catch(Throwable $e){error_log('sites: '.$e->getMessage());$error=$e instanceof PDOException?'Az adatbázis-művelet sikertelen. A domain és az azonosító egyedi kell legyen.':$e->getMessage();}
$edit=max(0,(int)($_GET['edit']??0));$selected=null;foreach($sites as $s)if((int)$s['id']===$edit)$selected=$s;
$siteSettings=cms_site_settings($selected??[]);
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhelyek | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-admin-section" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Adminisztráció</p><h1 class="ui-page-title">Webhelyek</h1><p class="ui-page-description">A comesy felületen kezelt kiadói webhelyek és tartalmaik.</p></div>
<div class="ui-page-actions"><?php if($selected):?><a class="ui-btn ui-btn-primary" href="/sites.php?new=1">Új webhely</a><?php else:?><button type="button" class="ui-btn ui-btn-primary ui-compact-action" data-panel-open="site-editor" aria-controls="site-editor" aria-expanded="false">+ Új webhely</button><?php endif;?></div></header>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($saved):?><p class="success" role="status">A webhely adatait mentettük.</p><?php endif;?>
<div class="ui-workbench">
<section class="ui-admin-panel ui-workbench-list"><div class="ui-panel-header"><h2>Meglévő webhelyek</h2><span class="ui-text-muted"><?=count($sites)?> webhely</span></div>
<?php if($sites):?><div class="ui-admin-list"><?php foreach($sites as $s):?><article class="ui-admin-row"><div class="ui-admin-row-main"><strong><?=h($s['name'])?></strong><small><?=h($s['domain'])?> · <?=h($s['slug'])?> · <?=(int)$s['article_count']?> cikk<?php $assigned=(string)(cms_site_settings($s)['source_template_id']??'');if($assigned!==''):?> · Sablon: <?=h($templateLabels[$assigned]??'Nem található')?><?php endif;?></small></div><a href="/sites.php?edit=<?=(int)$s['id']?>">Szerkesztés</a></article><?php endforeach;?></div>
<?php else:?><div class="ui-empty-state"><strong>Még nincs webhely.</strong><p>Az új webhelyet a jobb oldali űrlapon veheted fel.</p></div><?php endif;?></section>
<aside class="ui-admin-panel ui-workbench-pane" id="site-editor" data-ui-pane data-panel-default-open="<?=$selected||isset($_GET['new'])?'1':'0'?>" aria-labelledby="site-form-title"><button type="button" class="ui-pane-close" data-panel-close aria-label="Panel bezárása">✕</button><h2 id="site-form-title"><?=$selected?'Webhely szerkesztése':'Új webhely'?></h2><form method="post" class="ui-admin-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=(int)($selected['id']??0)?>">
<label class="ui-field">Név <input name="name" data-pane-first required minlength="2" maxlength="190" value="<?=h($selected['name']??'')?>" placeholder="Kiadói webhely"></label>
<label class="ui-field">Domain <input name="domain" required maxlength="190" placeholder="pelda.hu" value="<?=h($selected['domain']??'')?>"></label>
<label class="ui-field">Azonosító (slug) <input name="slug" required maxlength="100" placeholder="pelda" value="<?=h($selected['slug']??'')?>"></label>
<fieldset class="ui-site-features"><legend>Webhelyfunkciók</legend><p class="ui-page-description">A kikapcsolás nem törli a meglévő adatokat.</p>
<?php foreach(['builder_enabled'=>'Vizuális oldalépítő','reader_accounts_enabled'=>'Nyilvános olvasói fiókok','comments_enabled'=>'Hozzászólások','newsletter_enabled'=>'Hírlevél','ai_enabled'=>'AI-funkciók','ad_slots_enabled'=>'Natív hirdetési zónák'] as $flag=>$label):?>
<label class="ui-site-checkbox"><input type="checkbox" name="<?=h($flag)?>" value="1" <?=!empty($siteSettings[$flag])?'checked':''?>> <?=h($label)?></label>
<?php endforeach;?></fieldset>
<fieldset class="ui-site-features"><legend>Megjelenés és kiadói adatok</legend>
<label class="ui-field">Kiadó megnevezése <input name="publisher_name" maxlength="190" value="<?=h($siteSettings['publisher_name'])?>"></label>
<label class="ui-field">Webhely leírása <textarea name="site_description" maxlength="500" rows="3"><?=h($siteSettings['site_description'])?></textarea></label>
<label class="ui-field">Kiemelőszín <input type="color" name="accent_color" value="<?=h($siteSettings['accent_color'])?>"></label>
<label class="ui-field">Logó URL <input type="url" name="logo_url" maxlength="1000" value="<?=h($siteSettings['logo_url'])?>"></label>
<div class="ui-site-appearance" style="display:grid;gap:10px;margin-top:14px;padding:14px;border:1px solid var(--ui-border,#dce3ea);border-radius:10px">
<label class="ui-field" for="source-template-id">Arculati sablon
<select name="source_template_id" id="source-template-id" style="width:100%;min-height:46px;padding:9px 10px;border:1px solid var(--ui-border,#dce3ea);border-radius:8px">
<option value="">Nincs hozzárendelve (webhely alapmegjelenése)</option>
<?php foreach($appearanceTemplates as $tpl):?><option value="<?=h($tpl['id'])?>" <?=($siteSettings['source_template_id']??'')===$tpl['id']?'selected':''?>><?=h($tpl['title'])?> · <?=h($tpl['host'])?><?=!$tpl['reviewed']?' · automatikus':''?></option><?php endforeach;?>
</select></label>
<p class="ui-page-description" style="margin:0">Az importáláskor mentett forrásarculatok újrahasználható sablonként jelennek meg. A kiválasztott sablon a webhely megjelenéséhez és a cikkek előnézetéhez kapcsolódik. A hozzárendelés nem írja felül a cikkeket.</p>
<?php if(!$appearanceTemplates):?><p class="ui-page-description">Még nincs arculati sablon. Importálj egy cikket, majd használd a „Forrásstílus letöltése / frissítése” funkciót.</p><?php endif;?>
<?php if($selected&&($siteSettings['source_template_id']??'')!==''):?><p style="margin:0"><strong>Kiválasztva:</strong> <?=h($templateLabels[$siteSettings['source_template_id']]??'Nem elérhető sablon')?></p><?php endif;?>
</div>
</fieldset>
<?php if($selected):?><div style="margin:14px 0;padding:12px 14px;border:1px solid var(--ui-border,#dde4ed);border-radius:10px"><strong>Natív navigáció és funkciók</strong>
<p class="ui-page-description" style="margin:6px 0 12px">A kiválasztott arculati sablon csak a megjelenést adja. A menüpontokat a comesy menüszerkesztőjéből, a keresési találatokat a webhely saját cikkeiből építjük fel. A profilgomb jelenleg szerkesztőségi belépés.</p>
<?php if(!empty($siteSettings['builder_enabled'])):?><a class="ui-btn ui-btn-secondary" href="/builder.php?site_id=<?=(int)$selected['id']?>&amp;template=home">Menü szerkesztése ↗</a>
<?php else:?><p class="ui-page-description">A menüszerkesztő használatához kapcsold be a Vizuális oldalépítőt.</p><?php endif;?>
<a class="ui-btn ui-btn-secondary" href="/public.php?site=<?=rawurlencode((string)$selected['slug'])?>&amp;preview=1" target="_blank" rel="noopener">Webhely előnézete ↗</a></div><?php endif;?>
<div class="ui-admin-actions"><button class="ui-btn ui-btn-primary" type="submit">Mentés</button><?php if($selected):?><a class="ui-btn ui-btn-secondary" href="/sites.php">Mégse</a><?php endif;?></div></form>
<p>Az egyes webhelyek szerkesztési jogosultságait a <a href="/site-permissions.php">Hozzáféréseknél</a> tudod beállítani.</p>
</aside></div><button class="ui-pane-dim" data-ui-pane-dim type="button" aria-label="Panel bezárása"></button></main></body></html>