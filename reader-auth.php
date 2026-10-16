<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/site-settings.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
$slug=trim((string)($_POST['site']??$_GET['site']??''));
if(!preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/D',$slug)){http_response_code(400);exit('Érvénytelen webhely.');}
$pdo=db();$q=$pdo->prepare('SELECT id,name,settings_json FROM sites WHERE slug=? LIMIT 1');$q->execute([$slug]);$site=$q->fetch();
if(!$site){http_response_code(404);exit('A webhely nem található.');}
$siteId=(int)$site['id'];
if(!cms_site_settings($site)['reader_accounts_enabled']){http_response_code(404);exit('Az olvasói fiókok nincsenek bekapcsolva.');}
$back='/public.php?site='.rawurlencode($slug).'&login=1';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: '.$back,true,303);exit;}
if(!valid_csrf()){http_response_code(403);exit('Érvénytelen munkamenet.');}
$action=(string)($_POST['action']??'login');
if($action==='logout'){
 if((int)($_SESSION['reader_site_id']??0)===$siteId)unset($_SESSION['reader_id'],$_SESSION['reader_site_id'],$_SESSION['reader_name']);
 session_regenerate_id(true);header('Location: '.$back,true,303);exit;
}
$attempts=$_SESSION['reader_attempts']??[];
if(!is_array($attempts))$attempts=[];
$attempts=array_values(array_filter($attempts,static fn($v)=>is_numeric($v)&&(int)$v>time()-900));
if(count($attempts)>=12){http_response_code(429);exit('Túl sok próbálkozás. Próbáld újra 15 perc múlva.');}
$attempts[]=time();$_SESSION['reader_attempts']=$attempts;
$email=mb_strtolower(trim((string)($_POST['email']??'')));
$password=(string)($_POST['password']??'');
$validEmail=mb_strlen($email)<=190&&filter_var($email,FILTER_VALIDATE_EMAIL);
if(!$validEmail||strlen($password)>1024){header('Location: '.$back.'&auth_error=1',true,303);exit;}
try{
 if($action==='register'){
  $name=trim((string)($_POST['display_name']??''));
  // Bcrypt's 72-byte password limit is avoided by an explicit byte cap.
  if(mb_strlen($name)<2||mb_strlen($name)>190||mb_strlen($password)<12||strlen($password)>72){header('Location: '.$back.'&auth_error=1',true,303);exit;}
  $q=$pdo->prepare('SELECT id FROM site_readers WHERE site_id=? AND email=? LIMIT 1');
  $q->execute([$siteId,$email]);
  if($q->fetchColumn()){header('Location: '.$back.'&auth_error=1',true,303);exit;}
  $hash=password_hash($password,PASSWORD_DEFAULT);
  $q=$pdo->prepare('INSERT INTO site_readers(site_id,email,display_name,password_hash) VALUES(?,?,?,?)');
  $q->execute([$siteId,$email,$name,$hash]);
  session_regenerate_id(true);
  $_SESSION['reader_id']=(int)$pdo->lastInsertId();
  $_SESSION['reader_site_id']=$siteId;$_SESSION['reader_name']=$name;
  $_SESSION['reader_attempts']=[];
  header('Location: '.$back,true,303);exit;
 }
 if($action!=='login'){http_response_code(400);exit('Érvénytelen művelet.');}
 $q=$pdo->prepare('SELECT id,display_name,password_hash,active,failures,locked_until FROM site_readers WHERE site_id=? AND email=? LIMIT 1');
 $q->execute([$siteId,$email]);$account=$q->fetch();
 $locked=$account&&$account['locked_until']&&strtotime((string)$account['locked_until'])>time();
 $valid=$account&&(int)$account['active']===1&&!$locked&&password_verify($password,(string)$account['password_hash']);
 if(!$valid){
  if($account&&!$locked){
   $fails=(int)$account['failures']+1;
   $pdo->prepare('UPDATE site_readers SET failures=?,locked_until=? WHERE id=?')->execute([$fails,$fails>=7?gmdate('Y-m-d H:i:s',time()+900):null,$account['id']]);
  }else{password_verify($password,'$2y$10$1T48.WytUEdt2oXG32HOzebpsYuUutWED9Tr2rxazmh/itRh44v3W');}
  header('Location: '.$back.'&auth_error=1',true,303);exit;
 }
 $pdo->prepare('UPDATE site_readers SET failures=0,locked_until=NULL WHERE id=?')->execute([$account['id']]);
 if(password_needs_rehash($account['password_hash'],PASSWORD_DEFAULT)){
  $pdo->prepare('UPDATE site_readers SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$account['id']]);
 }
 session_regenerate_id(true);
 $_SESSION['reader_id']=(int)$account['id'];
 $_SESSION['reader_site_id']=$siteId;$_SESSION['reader_name']=$account['display_name'];
 $_SESSION['reader_attempts']=[];
 header('Location: '.$back,true,303);exit;
}catch(PDOException $e){
 error_log('reader auth: '.$e->getMessage());
 header('Location: '.$back.'&auth_error=1',true,303);exit;
}