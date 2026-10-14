<?php
require __DIR__.'/app/bootstrap.php';
if(user()){header('Location: /');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!valid_csrf()){$error='Érvénytelen munkamenet. Frissítsd az oldalt.';}
 else{
  $email=trim((string)($_POST['email']??''));
  $password=(string)($_POST['password']??'');
  $attempts=(int)($_SESSION['login_attempts']??0);
  $last=(int)($_SESSION['login_last']??0);
  if($attempts>=5&&time()-$last<900){$error='Túl sok próbálkozás. Próbáld újra 15 perc múlva.';}
  else{
   try{$q=db()->prepare('SELECT id,password_hash,active FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$record=$q->fetch();
    if($record&&(int)$record['active']===1&&password_verify($password,$record['password_hash'])){
      session_regenerate_id(true);$_SESSION['user_id']=(int)$record['id'];$_SESSION['login_attempts']=0;header('Location: /');exit;
    }
   }catch(Throwable $e){error_log('comesy login: '.$e->getMessage());}
   $_SESSION['login_attempts']=$attempts+1;$_SESSION['login_last']=time();$error='Hibás belépési adatok vagy még nem telepített adatbázis.';
  }
 }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bejelentkezés | comesy</title><style>body{font-family:system-ui;background:#f4f6fb;color:#17223b;display:grid;place-items:center;min-height:100vh;margin:0;padding:20px}.box{width:min(420px,100%);background:white;padding:38px;border-radius:18px;box-shadow:0 12px 40px #13254b15}h1{font-size:38px;letter-spacing:-2px;margin:0}p{color:#6d7891}label{display:block;margin:20px 0 6px;font-size:13px;font-weight:700}input{width:100%;padding:13px;border:1px solid #ccd5e5;border-radius:9px;font-size:16px;box-sizing:border-box}button{background:#315fd6;color:white;border:0;border-radius:9px;width:100%;padding:14px;margin-top:25px;font-weight:700;font-size:15px}.err{color:#a52727;background:#fff0f0;padding:12px;border-radius:8px}</style><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script></head><body><main class="box"><h1>comesy</h1><p>Content. Media. System.<br>One platform. Every story.</p><button type="button" class="theme-toggle" data-theme-toggle aria-label="Sötét mód">◐</button><h2>Adminisztráció</h2><?php if($error):?><div class="err"><?=h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label for="email">E-mail cím</label><input id="email" name="email" type="email" autocomplete="username" required><label for="password">Jelszó</label><input id="password" name="password" type="password" autocomplete="current-password" required><button type="submit">Bejelentkezés</button></form></main></body></html>
