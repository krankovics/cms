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
      try{$audit=db()->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'auth.login','user',?,NULL)");$audit->execute([$record['id'],$record['id']]);}catch(Throwable $e){error_log('auth audit: '.$e->getMessage());}
      session_regenerate_id(true);$_SESSION['user_id']=(int)$record['id'];$_SESSION['login_attempts']=0;header('Location: /');exit;
    }
   }catch(Throwable $e){error_log('comesy login: '.$e->getMessage());}
   try{db()->exec("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(NULL,'auth.login_failed','auth',NULL,NULL)");}catch(Throwable $e){error_log('auth audit: '.$e->getMessage());}
   $_SESSION['login_attempts']=$attempts+1;$_SESSION['login_last']=time();$error='Hibás belépési adatok vagy még nem telepített adatbázis.';
  }
 }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Bejelentkezés | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><link rel="stylesheet" href="/assets/login.css"></head><body class="comesy-login"><main class="login-layout"><section class="login-hero"><div class="login-brand">comesy.</div><div class="login-hero-message"><p>Content. Media. System.</p><h1>One platform.<br>Every story.</h1><p>Egyetlen felület a tartalmak, a webhelyek és a kiadói munkafolyamatok kezeléséhez.</p></div><div class="login-foot">comesy · Publishing workspace</div></section><section class="login-side"><div class="login-theme"><button type="button" class="theme-toggle" data-theme-toggle aria-label="Sötét mód váltása">◐</button></div><div class="login-card"><p class="login-mobile-tagline">Content. Media. System.<br>One platform. Every story.</p><p class="login-kicker">Szerkesztőségi munkaterület</p><h2>Üdv újra!</h2><p class="login-intro">Jelentkezz be a comesy adminisztrációs felületére.</p><?php if($error):?><div class="login-error" role="alert"><?=h($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><label for="email">E-mail cím</label><input id="email" name="email" type="email" autocomplete="username" inputmode="email" placeholder="nev@ceg.hu" required><label for="password">Jelszó</label><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Add meg a jelszavad" required><button class="login-submit" type="submit">Bejelentkezés →</button></form><p class="login-bottom">Biztonságos szerkesztőségi bejelentkezés</p></div></section></main></body></html>
