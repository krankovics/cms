<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/user-actions.php';
$u=require_user();if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$error='';$users=[];$selected=null;
$roles=['platform_admin'=>'Szuperadmin','site_admin'=>'Webhelyadmin','editor'=>'Szerkesztő','author'=>'Szerző','ad_manager'=>'Hirdetéskezelő'];
try{
 $pdo=db();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  save_admin_user($pdo,$u);
  header('Location: /users.php?saved=1');exit;
 }
 $users=$pdo->query('SELECT id,name,email,role,active FROM users ORDER BY name')->fetchAll();
 $edit=max(0,(int)($_GET['edit']??0));
 foreach($users as $item)if((int)$item['id']===$edit)$selected=$item;
}catch(Throwable $e){error_log('user management: '.$e->getMessage());$error=$e instanceof PDOException?'A mentés adatbázishiba miatt nem sikerült.':$e->getMessage();}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Felhasználók | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script>
<style>body{margin:0;font-family:system-ui}.wrap{max-width:900px;margin:auto;padding:16px}.panel{padding:18px;background:var(--surface);border:1px solid var(--line);border-radius:12px;margin:12px 0}.user-form{display:grid;gap:11px}.user-form label{display:grid;gap:5px}.user-form input:not([type=checkbox]),.user-form select{width:100%;box-sizing:border-box;font-size:16px;padding:10px;border-radius:8px}.user-form button{padding:12px;background:var(--blue);color:white;border:0;border-radius:8px}.user-list{display:grid;gap:6px}.user-row{padding:12px;border-bottom:1px solid var(--line)}.user-row strong{display:block}.user-row small{display:block;color:var(--muted);margin-top:5px}.user-row a{color:var(--blue)}.user-form .active-label{display:flex;align-items:center;gap:9px}.user-form .active-label input{width:18px;height:18px}@media(min-width:700px){.user-form{grid-template-columns:repeat(2,minmax(0,1fr))}.user-form .full{grid-column:1/-1}}</style>
</head><body><?php require __DIR__.'/app/header.php';?><main class="wrap"><h1>Felhasználók</h1><?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?><?php if(isset($_GET['saved'])):?><p class="success" role="status">A felhasználó mentve.</p><?php endif;?>
<section class="panel"><h2><?=$selected?'Felhasználó szerkesztése':'Új felhasználó'?></h2><form class="user-form" method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="user_id" value="<?=(int)($selected['id']??0)?>">
<label>Név<input name="name" required minlength="2" maxlength="190" value="<?=h($selected['name']??'')?>"></label>
<label>E-mail cím<input type="email" name="email" required value="<?=h($selected['email']??'')?>"></label>
<label>Szerepkör<select name="role"><?php foreach($roles as $key=>$label):?><option value="<?=$key?>" <?=($selected['role']??'author')===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
<label><?= $selected?'Új jelszó (üresen hagyható)':'Kezdő jelszó' ?><input type="password" name="new_password" minlength="14" autocomplete="new-password" <?=$selected?'':'required'?>></label>
<?php if($selected):?><label class="active-label full"><input type="checkbox" name="active" value="1" <?=$selected['active']?'checked':''?>> Aktív fiók</label><?php endif;?>
<div class="full"><button type="submit">Mentés</button> <?php if($selected):?><a href="/users.php">Mégse</a><?php endif;?></div></form><p>A szerkesztők és szerzők webhelyét a <a href="/site-permissions.php">webhely-hozzáféréseknél</a> add meg.</p></section>
<section class="panel"><h2>Felhasználók (<?=count($users)?>)</h2><label class="comesy-search"><span>Keresés</span><input type="search" data-list-search="user-list" placeholder="Név vagy e-mail…"></label><div class="user-list" id="user-list"><?php foreach($users as $item):?><div class="user-row" data-search="<?=h($item['name'].' '.$item['email'].' '.($roles[$item['role']]??''))?>"><strong><?=h($item['name'])?></strong><small><?=h($item['email'])?> · <?=h($roles[$item['role']]??$item['role'])?> · <?=$item['active']?'Aktív':'Inaktív'?></small><a href="/users.php?edit=<?=(int)$item['id']?>">Szerkesztés</a></div><?php endforeach;?></div></section></main></body></html>