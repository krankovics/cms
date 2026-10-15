<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
$u=require_user();
if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$error='';$saved=false;
try{
 $pdo=db();
 $users=$pdo->query("SELECT id,name,email,role FROM users WHERE role IN ('site_admin','editor','author','ad_manager') AND active=1 ORDER BY name")->fetchAll();
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $uid=(int)($_REQUEST['user_id']??($users[0]['id']??0));
 $ids=array_map('intval',array_column($users,'id'));
 if($uid&&!in_array($uid,$ids,true))throw new RuntimeException('Érvénytelen felhasználó.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $primary=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['primary']??[])))));
  $siteIds=array_map('intval',array_column($sites,'id'));
  if(!$primary||array_diff($primary,$siteIds))throw new RuntimeException('Válassz legalább egy szerkeszthető webhelyet.');
  $tags=array_map('intval',(array)($_POST['tags']??[]));
  $media=array_map('intval',(array)($_POST['media']??[]));
  $pdo->beginTransaction();
  try{
   $pdo->prepare('DELETE FROM user_site_permissions WHERE user_id=?')->execute([$uid]);
   $q=$pdo->prepare('INSERT INTO user_site_permissions(user_id,site_id,is_primary,can_tags,can_media) VALUES(?,?,?,?,?)');
   foreach($siteIds as $sid)if(in_array($sid,$primary,true)||in_array($sid,$tags,true)||in_array($sid,$media,true))
    $q->execute([$uid,$sid,(int)in_array($sid,$primary,true),(int)in_array($sid,$tags,true),(int)in_array($sid,$media,true)]);
   $audit=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
   $audit->execute([$u['id'],'permission.update','user',$uid,json_encode(['site_ids'=>$primary,'tags'=>$tags,'media'=>$media],JSON_UNESCAPED_UNICODE)]);
   $pdo->commit();
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
  header('Location: /site-permissions.php?user_id='.$uid.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT site_id,is_primary,can_tags,can_media FROM user_site_permissions WHERE user_id=?');
 $q->execute([$uid]);$permissions=[];
 foreach($q->fetchAll() as $p)$permissions[(int)$p['site_id']]=$p;
}catch(Throwable $e){error_log('site permissions: '.$e->getMessage());$error=$e instanceof PDOException?'Futtasd le az adatbázis-migrációt.':$e->getMessage();$permissions=$permissions??[];$users=$users??[];$sites=$sites??[];}
$targetRole='editor';
foreach($users??[] as $subject)if((int)$subject['id']===(int)($uid??0))$targetRole=$subject['role'];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhely-hozzáférések | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-admin-section" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Adminisztráció</p><h1 class="ui-page-title">Webhely-hozzáférések</h1><p class="ui-page-description">Szerkesztési jogok, címke- és médiatár-hozzáférés webhelyenként.</p></div><div class="ui-page-actions"><a href="/users.php" class="ui-btn ui-btn-secondary">Felhasználók</a></div></header>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="success" role="status">A jogosultságokat elmentettük.</p><?php endif;?>
<?php if(!$users):?><section class="ui-admin-panel"><p>Nincs szerkesztői vagy szerzői szerepkörű aktív felhasználó. <a href="/users.php">Felhasználó létrehozása</a></p></section><?php else:?>
<section class="ui-admin-panel ui-permissions-picker"><form method="get" class="ui-admin-form"><label class="ui-field">Felhasználó <select name="user_id" onchange="this.form.submit()"><?php foreach($users as $item):?><option value="<?=(int)$item['id']?>" <?=$uid===(int)$item['id']?'selected':''?>><?=h($item['name'].' ('.$item['email'].') · '.($item['role']==='ad_manager'?'Hirdetéskezelő':$item['role']))?></option><?php endforeach;?></select></label></form></section>
<?php if($uid):?><form method="post" class="ui-admin-panel" id="permissions-form">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="user_id" value="<?=$uid?>">
<div class="ui-panel-header"><div><h2>Jogosultsági mátrix</h2><p class="ui-page-description">Jelöld ki legalább egy webhely szerkesztési jogát. A címke- és médiatárhozzáférés további webhelyekre is adható.</p></div></div>
<?php if($sites):?><div class="ui-permission-grid"><?php foreach($sites as $s):$sid=(int)$s['id'];$perm=$permissions[$sid]??[];?>
<details class="ui-permission-item" data-responsive-accordion open><summary><?=h($s['name'])?></summary><div class="ui-permission-checks">
<label><input type="checkbox" name="primary[]" value="<?=$sid?>" <?=!empty($perm['is_primary'])?'checked':''?>> <?=$targetRole==='ad_manager'?'Hirdetések':'Szerkesztés'?></label>
<label><input type="checkbox" name="tags[]" value="<?=$sid?>" <?=!empty($perm['can_tags'])?'checked':''?>> Címkék</label>
<label><input type="checkbox" name="media[]" value="<?=$sid?>" <?=!empty($perm['can_media'])?'checked':''?>> Médiatár</label></div></details>
<?php endforeach;?></div>
<p class="ui-page-description">A szerkeszthető webhelyek címkéi és médiatára automatikusan elérhető. A külön engedélyek csak további olvasási hozzáférést adnak.</p>
<div class="ui-admin-actions"><button class="ui-btn ui-btn-primary" type="submit">Jogosultságok mentése</button></div>
<?php else:?><p>Előbb hozz létre legalább egy webhelyet a <a href="/sites.php">Webhelyek</a> oldalon.</p><?php endif;?>
</form><?php endif;?><?php endif;?></main></body></html>