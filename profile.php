<?php
require __DIR__.'/app/bootstrap.php';
$u=require_user();$message='';$error='';
$avatarDir=__DIR__.'/app/avatars';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!valid_csrf()){$error='Lejárt munkamenet. Frissítsd az oldalt.';}
 else {
  $action=(string)($_POST['action']??'');
  try {
   if($action==='name'){
    $name=trim((string)($_POST['name']??''));
    if(mb_strlen($name)<2||mb_strlen($name)>190)throw new RuntimeException('A név 2–190 karakter lehet.');
    $q=db()->prepare('UPDATE users SET name=? WHERE id=?');$q->execute([$name,$u['id']]);$u['name']=$name;$message='A nevet mentettük.';
   }elseif($action==='password'){
    $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');$confirm=(string)($_POST['confirm_password']??'');
    $q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$u['id']]);$hash=$q->fetchColumn();
    if(!$hash||!password_verify($current,$hash))throw new RuntimeException('A jelenlegi jelszó hibás.');
    if(strlen($new)<14||strlen($new)>256)throw new RuntimeException('Az új jelszó 14–256 karakter legyen.');
    if(!hash_equals($new,$confirm))throw new RuntimeException('A két új jelszó nem egyezik.');
    $q=db()->prepare('UPDATE users SET password_hash=? WHERE id=?');$q->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);
    session_regenerate_id(true);$message='A jelszó megváltozott.';
   }elseif($action==='avatar'){
    $file=$_FILES['avatar']??null;
    if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK)throw new RuntimeException('A feltöltés sikertelen.');
    if(($file['size']??0)>2*1024*1024)throw new RuntimeException('A kép legfeljebb 2 MB lehet.');
    $type=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($allowed[$type])||!getimagesize($file['tmp_name']))throw new RuntimeException('Csak JPG, PNG vagy WebP kép tölthető fel.');
    if(!is_dir($avatarDir)&&!mkdir($avatarDir,0700,true))throw new RuntimeException('A képtár nem írható.');
    $dest=$avatarDir.'/user-'.(int)$u['id'].'.'.$allowed[$type];
    if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('A kép nem menthető.');
    chmod($dest,0600);
    foreach(['jpg','png','webp'] as $ext){$old=$avatarDir.'/user-'.(int)$u['id'].'.'.$ext;if($old!==$dest&&is_file($old))unlink($old);}
    $message='A profilkép frissült.';
   }
  }catch(Throwable $e){$error=$e instanceof PDOException?'A módosítás nem sikerült.':$e->getMessage();}
 }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Profilbeállítások | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script></head><body><?php require __DIR__.'/app/header.php'; ?><main class="profile-wrap"><div class="profile-head"><a href="/" class="brand">comesy</a><button type="button" class="theme-toggle" data-theme-toggle aria-label="Sötét mód váltása">◐</button></div><div class="profile-card"><a href="/" class="back">← Vissza az adminba</a><h1>Profilbeállítások</h1><div class="profile-summary"><img class="avatar large" src="/avatar.php?v=<?=time()?>" alt="Profilkép"><div><strong><?=h($u['name'])?></strong><p><?=h($u['email'])?></p></div></div><?php if($message):?><p class="success"><?=h($message)?></p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><section><h2>Profilkép</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="avatar"><label for="avatar">Kép feltöltése (JPG, PNG, WebP, max. 2 MB)</label><input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp" required><button type="submit">Profilkép mentése</button></form></section><section><h2>Alapadatok</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="name"><label for="name">Megjelenített név</label><input id="name" name="name" maxlength="190" value="<?=h($u['name'])?>" required><label>E-mail cím</label><input value="<?=h($u['email'])?>" disabled><button type="submit">Adatok mentése</button></form></section><section><h2>Jelszó módosítása</h2><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="password"><label for="old">Jelenlegi jelszó</label><input id="old" name="current_password" type="password" autocomplete="current-password" required><label for="new">Új jelszó (min. 14 karakter)</label><input id="new" name="new_password" type="password" minlength="14" autocomplete="new-password" required><label for="confirm">Új jelszó újra</label><input id="confirm" name="confirm_password" type="password" minlength="14" autocomplete="new-password" required><button type="submit">Jelszó megváltoztatása</button></form></section><form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button class="signout">Kijelentkezés</button></form></div></main></body></html>
