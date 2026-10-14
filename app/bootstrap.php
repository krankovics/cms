<?php
declare(strict_types=1);
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('comesy_session');
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
function config(): array {
    $file=__DIR__.'/config.php';
    if (!is_file($file)) return [];
    $cfg=require $file;
    return is_array($cfg)?$cfg:[];
}
function db(): PDO {
    static $db=null;
    if($db instanceof PDO) return $db;
    $c=config();
    if(empty($c['db_name'])||empty($c['db_user'])) throw new RuntimeException('Adatbázis nincs konfigurálva.');
    $db=new PDO('mysql:host='.($c['db_host']??'localhost').';dbname='.$c['db_name'].';charset=utf8mb4',$c['db_user'],$c['db_pass']??'',[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    return $db;
}
function h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf():string{if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function valid_csrf():bool{return isset($_POST['csrf'])&&is_string($_POST['csrf'])&&hash_equals(csrf(),$_POST['csrf']);}
function user():?array{
 if(empty($_SESSION['user_id']))return null;
 try{$q=db()->prepare('SELECT id,email,name,role FROM users WHERE id=? AND active=1 LIMIT 1');$q->execute([$_SESSION['user_id']]);return $q->fetch()?:null;}catch(Throwable $e){error_log('comesy auth: '.$e->getMessage());return null;}
}
function require_user():array{$u=user();if(!$u){header('Location: /login.php');exit;}return $u;}
