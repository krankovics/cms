<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
$u=require_user();
if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$error='';$saved=false;
try{
 $pdo=db();
 $users=$pdo->query("SELECT id,name,email FROM users WHERE role='site_admin' AND active=1 ORDER BY name")->fetchAll();
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $uid=(int)($_REQUEST['user_id']??($users[0]['id']??0));
 $ids=array_map('intval',array_column($users,'id'));
 if($uid&&!in_array($uid,$ids,true))throw new RuntimeException('Érvénytelen felhasználó.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $primary=(int)($_POST['primary']??0);
  $siteIds=array_map('intval',array_column($sites,'id'));
  if(!in_array($primary,$siteIds,true))throw new RuntimeException('Válassz saját webhelyet.');
  $tags=array_map('intval',(array)($_POST['tags']??[]));
  $media=array_map('intval',(array)($_POST['media']??[]));
  $pdo->beginTransaction();
  try{
   $pdo->prepare('DELETE FROM user_site_permissions WHERE user_id=?')->execute([$uid]);
   $q=$pdo->prepare('INSERT INTO user_site_permissions(user_id,site_id,is_primary,can_tags,can_media) VALUES(?,?,?,?,?)');
   foreach($siteIds as $sid)if($sid===$primary||in_array($sid,$tags,true)||in_array($sid,$media,true))
    $q->execute([$uid,$sid,(int)($sid===$primary),(int)in_array($sid,$tags,true),(int)in_array($sid,$media,true)]);
   $pdo->commit();
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
  header('Location: /site-permissions.php?user_id='.$uid.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT site_id,is_primary,can_tags,can_media FROM user_site_permissions WHERE user_id=?');
 $q->execute([$uid]);$permissions=[];
 foreach($q->fetchAll() as $p)$permissions[(int)$p['site_id']]=$p;
}catch(Throwable $e){error_log('site permissions: '.$e->getMessage());$error=$e instanceof PDOException?'Futtasd le az adatbázis-migrációt.':$e->getMessage();$permissions=$permissions??[];$users=$users??[];$sites=$sites??[];}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhely-hozzáférések | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{margin:0;font-family:system-ui}.wrap{max-width:900px;margin:auto;padding:20px}.panel{padding:22px;border:1px solid var(--line);background:var(--surface);border-radius:14px;margin:16px 0}select,button{padding:10px;font-size:16px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:13px;text-align:left;border-bottom:1px solid var(--line)}input[type=checkbox],input[type=radio]{width:20px;height:20px}small{color:var(--muted)}</style></head><body><?php require __DIR__.'/app/header.php';?><main class="wrap"><h1>Site admin hozzáférések</h1><p>A saját webhely minden címkéjéhez és médiájához hozzáfér. A többi webhely hozzáférése külön-külön engedélyezhető.</p><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><?php if(isset($_GET['saved'])):?><p>Jogosultságok mentve.</p><?php endif;?><form method="get" class="panel"><label>Site admin <select name="user_id" onchange="this.form.submit()"><?php foreach($users as $user):?><option value="<?=(int)$user['id']?>" <?=$uid===(int)$user['id']?'selected':''?>><?=h($user['name'].' ('.$user['email'].')')?></option><?php endforeach;?></select></label></form><?php if($uid):?><form method="post" class="panel"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="user_id" value="<?=$uid?>"><div class="table-wrap"><table><thead><tr><th>Webhely</th><th>Saját site</th><th>Címkék</th><th>Médiatár</th></tr></thead><tbody><?php foreach($sites as $s):?><?php $sid=(int)$s['id'];$p=$permissions[$sid]??[];?><tr><td><?=h($s['name'])?></td><td><input type="radio" name="primary" value="<?=$sid?>" <?=!empty($p['is_primary'])?'checked':''?> required aria-label="<?=h($s['name'])?> saját webhely"></td><td><input type="checkbox" name="tags[]" value="<?=$sid?>" <?=!empty($p['can_tags'])?'checked':''?> aria-label="<?=h($s['name'])?> címkék"></td><td><input type="checkbox" name="media[]" value="<?=$sid?>" <?=!empty($p['can_media'])?'checked':''?> aria-label="<?=h($s['name'])?> médiatár"></td></tr><?php endforeach;?></tbody></table></div><p><small>A saját webhelyhez a pipák nélkül is jár mindkét hozzáférés.</small></p><button type="submit">Jogosultságok mentése</button></form><?php endif;?></main></body></html>