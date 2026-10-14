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
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Felhasználók | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Adminisztráció</p><h1 class="ui-page-title">Felhasználók</h1><p class="ui-page-description">Csapattagok, szerepkörök és fiókállapotok kezelése.</p></div>
<div class="ui-page-actions"><?php if($selected):?><a class="ui-btn ui-btn-primary" href="/users.php?new=1"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Új felhasználó</a><?php else:?><button class="ui-btn ui-btn-primary ui-compact-action" type="button" data-panel-open="user-editor" aria-controls="user-editor" aria-expanded="false"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Új felhasználó</button><?php endif;?></div>
</header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status">A felhasználó adatait mentettük.</p><?php endif;?>
<label class="ui-field ui-field-search ui-users-search">Keresés a csapatban <input type="search" data-ui-search="user-list" placeholder="Név, e-mail vagy szerepkör…" autocomplete="off"></label>
<div class="ui-result-bar"><span><strong data-ui-visible-for="user-list"><?=count($users)?></strong> felhasználó</span><a class="ui-panel-link" href="/site-permissions.php">Webhely-hozzáférések →</a></div>
<div class="ui-workbench">
<section class="ui-panel ui-data-panel ui-workbench-list" aria-label="Felhasználók listája">
<div id="user-list"><?php foreach($users as $item):?>
<div class="ui-person-row ui-filter-row" data-search="<?=h($item['name'].' '.$item['email'].' '.($roles[$item['role']]??''))?>">
<div class="ui-person-avatar" aria-hidden="true"><?=h(mb_strtoupper(mb_substr($item['name'],0,1)))?></div>
<div class="ui-person-details"><strong><?=h($item['name'])?></strong><small><?=h($item['email'])?></small><small><?=h($roles[$item['role']]??$item['role'])?></small></div>
<span class="ui-status <?=$item['active']?'ui-status-published':'ui-status-archived'?>"><?=$item['active']?'Aktív':'Inaktív'?></span>
<div class="ui-person-actions"><a class="ui-panel-link" href="/users.php?edit=<?=(int)$item['id']?>">Szerkesztés</a><?php if(in_array($item['role'],['site_admin','editor','author'],true)):?><a class="ui-panel-link" href="/site-permissions.php?user_id=<?=(int)$item['id']?>">Hozzáférés</a><?php endif;?></div>
</div>
<?php endforeach;?></div>
<div class="ui-panel-state" data-ui-empty-for="user-list" <?=$users?'hidden':''?>>Nincs a keresésnek megfelelő felhasználó.</div>
</section>
<aside class="ui-panel ui-workbench-pane" id="user-editor" data-ui-pane data-panel-default-open="<?=$selected||isset($_GET['new'])?'1':'0'?>" aria-label="<?=$selected?'Felhasználó szerkesztése':'Új felhasználó'?>">
<div class="ui-pane-header"><div><h2><?=$selected?'Felhasználó szerkesztése':'Új felhasználó'?></h2><p>Csapattag és jogosultsági szerepkör</p></div><button class="ui-pane-close" type="button" data-panel-close aria-label="Szerkesztőpanel bezárása">✕</button></div>
<form method="post" id="user-edit-form" data-editing-user="<?=$selected?'1':'0'?>">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="user_id" value="<?=(int)($selected['id']??0)?>">
<div class="ui-pane-body">
<label class="ui-field">Név <input name="name" data-pane-first required minlength="2" maxlength="190" value="<?=h($selected['name']??'')?>" autocomplete="name"></label>
<label class="ui-field">E-mail cím <input type="email" name="email" required value="<?=h($selected['email']??'')?>" autocomplete="email"></label>
<label class="ui-field">Szerepkör <select name="role"><?php foreach($roles as $key=>$label):?><option value="<?=h($key)?>" <?=($selected['role']??'author')===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
<label class="ui-field"><?=$selected?'Új jelszó (nem kötelező)':'Kezdő jelszó'?> <input type="password" name="new_password" minlength="14" autocomplete="new-password" <?=$selected?'':'required'?>><small>Legalább 14 karakter. Szerkesztéskor üresen hagyva nem változik.</small></label>
<?php if($selected):?><label class="ui-account-active"><input type="checkbox" name="active" value="1" <?=$selected['active']?'checked':''?>> Aktív fiók</label><?php endif;?>
<p class="ui-text-muted ui-account-help">A felhasználó szerkeszthető webhelyeit a <a href="/site-permissions.php">webhely-hozzáféréseknél</a> állíthatod be.</p>
</div>
<div class="ui-pane-footer"><button class="ui-btn ui-btn-primary" type="submit">Mentés</button><?php if($selected):?><a class="ui-btn ui-btn-secondary" href="/users.php">Új felhasználó</a><?php endif;?></div>
</form></aside>
</div><button type="button" class="ui-pane-dim" data-ui-pane-dim aria-label="Panel bezárása"></button>
<script>
document.getElementById('user-edit-form')?.addEventListener('submit',function(e){
 if(this.dataset.editingUser!=='1')return;
 const active=this.querySelector('[name="active"]');
 if(active&&!active.checked&&!confirm('Biztosan inaktiválod ezt a felhasználót? Nem fog tudni bejelentkezni.'))e.preventDefault();
});
</script></main></body></html>