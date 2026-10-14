<?php
require __DIR__.'/app/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');
$cfg=config();$key=(string)($cfg['setup_key']??'');
if($key===''){http_response_code(503);exit('A telepítés nincs engedélyezve. Add hozzá a COMESY_SETUP_KEY GitHub secretet.');}
$error='';$success='';
try{
 $pdo=db();$exists=$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
 if($exists && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()>0){http_response_code(403);exit('A telepítés már megtörtént.');}
}catch(Throwable $e){$error='Adatbázis-kapcsolat nem érhető el. Ellenőrizd a DB secret-eket.';}
if($_SERVER['REQUEST_METHOD']==='POST'&&$error===''){
 if(!valid_csrf())$error='Érvénytelen munkamenet.';
 elseif(!hash_equals($key,(string)($_POST['setup_key']??'')))$error='Hibás telepítési kulcs.';
 elseif(!filter_var($_POST['email']??'',FILTER_VALIDATE_EMAIL))$error='Hibás e-mail cím.';
 elseif(strlen((string)($_POST['password']??''))<14)$error='A jelszó legalább 14 karakter legyen.';
 else{
  try{
   $sql=file_get_contents(__DIR__.'/database/schema.sql');
   foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement){$pdo->exec($statement);}
   $q=$pdo->prepare("INSERT INTO users(email,name,password_hash,role) VALUES(?,?,?,'platform_admin')");
   $q->execute([trim((string)$_POST['email']),trim((string)($_POST['name']??'Admin')),password_hash((string)$_POST['password'],PASSWORD_DEFAULT)]);
   $success='A comesy adminfiók elkészült. Jelentkezz be a /login.php oldalon. A COMESY_SETUP_KEY secretet ezután törölheted.';
  }catch(Throwable $e){error_log('comesy setup: '.$e->getMessage());$error='Telepítési hiba. Ellenőrizd az adatbázis jogosultságait.';}
 }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>comesy telepítés</title><link rel="stylesheet" href="/assets/setup.css"></head><body><main class="box"><h1>comesy<span>.</span></h1><p>Első adminfiók létrehozása</p><?php if($error):?><p class="msg"><?=h($error)?></p><?php endif;?><?php if($success):?><p class="msg"><?=h($success)?></p><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Telepítési kulcs<input name="setup_key" type="password" required></label><label>Név<input name="name" required></label><label>E-mail<input name="email" type="email" required></label><label>Adminjelszó (minimum 14 karakter)<input name="password" type="password" minlength="14" required></label><button>Adminfiók létrehozása</button></form><?php endif;?></main></body></html>
