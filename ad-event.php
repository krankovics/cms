<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
if(strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin'))==='cross-site'){http_response_code(403);exit;}
$raw=(string)file_get_contents('php://input');
if(strlen($raw)>250){http_response_code(413);exit;}
$data=json_decode($raw,true);
$token=is_array($data)?(string)($data['token']??''):'';
$event=is_array($data)?(string)($data['event']??''):'';
if(!preg_match('/^[a-f0-9]{32}$/D',$token)||!in_array($event,['render','viewable'],true)){http_response_code(400);exit;}
try{
 $cfg=require __DIR__.'/app/config.php';
 $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $column=$event==='render'?'rendered_at':'viewable_at';
 $q=$db->prepare('UPDATE ad_serves SET '.$column.'=UTC_TIMESTAMP() WHERE token=? AND '.$column.' IS NULL AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY');
 $q->execute([$token]);
 if($q->rowCount()===0){http_response_code(202);echo '{"ok":true,"deduplicated":true}';exit;}
 echo '{"ok":true}';
}catch(Throwable $e){error_log('ad-event: '.$e->getMessage());http_response_code(503);echo '{"ok":false}';}
