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
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>comesy telepítés</title><style>
:root{font-family:Inter,system-ui,-apple-system,sans-serif;color-scheme:light;--bg:#f6f8fc;--surface:#fff;--text:#192437;--muted:#64748b;--line:#dfe6f0;--primary:#335eea}
@media(prefers-color-scheme:dark){:root{color-scheme:dark;--bg:#0d1421;--surface:#172338;--text:#eff4fc;--muted:#a8b9d2;--line:#364861;--primary:#88a9ff}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);min-height:100dvh;display:grid;place-items:center;padding:22px}
.box{width:min(460px,100%);margin:auto;background:var(--surface);border:1px solid var(--line);padding:clamp(20px,5vw,34px);border-radius:18px;box-shadow:0 12px 36px rgba(18,32,56,.06)}
h1{font-size:32px;margin:0;letter-spacing:-.055em}h1 span{color:var(--primary)}.box>p{font-size:13px;line-height:1.6;color:var(--muted)}
label{display:grid;gap:7px;margin-top:17px;font-weight:660;font-size:12px;color:var(--muted)}
input{width:100%;font-family:inherit;font-size:16px;min-height:46px;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--text)}
input:focus-visible,button:focus-visible{outline:3px solid var(--primary);outline-offset:2px}
button{width:100%;margin-top:24px;min-height:47px;border:0;border-radius:10px;background:var(--primary);color:var(--bg);font-size:14px;font-weight:720;cursor:pointer}
.msg{padding:12px;border:1px solid var(--line);border-radius:9px;background:var(--bg);color:var(--text)}
</style></head><body><main class="box"><h1>comesy<span>.</span></h1><p>Első adminfiók létrehozása</p><?php if($error):?><p class="msg"><?=h($error)?></p><?php endif;?><?php if($success):?><p class="msg"><?=h($success)?></p><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label>Telepítési kulcs<input name="setup_key" type="password" required></label><label>Név<input name="name" required></label><label>E-mail<input name="email" type="email" required></label><label>Adminjelszó (minimum 14 karakter)<input name="password" type="password" minlength="14" required></label><button>Adminfiók létrehozása</button></form><?php endif;?></main></body></html>
