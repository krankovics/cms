<?php
declare(strict_types=1);
/** Minimal first-party aggregated content analytics: NO cookies, IPs, visitor IDs
 * or cross-site user tracking. Only event totals per page/day/device. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
if((string)($_SERVER['HTTP_SEC_FETCH_SITE']??'same-origin')==='cross-site'){http_response_code(403);exit;}
$origin=(string)($_SERVER['HTTP_ORIGIN']??'');
if($origin!==''&&strtolower((string)parse_url($origin,PHP_URL_HOST))!==strtolower((string)parse_url('http://'.(string)($_SERVER['HTTP_HOST']??''),PHP_URL_HOST))){http_response_code(403);exit;}
$raw=(string)file_get_contents('php://input');
if(strlen($raw)>850){http_response_code(413);exit;}
$event=json_decode($raw,true);
if(!is_array($event)){http_response_code(400);exit;}
$siteId=(int)($event['site_id']??0);
$type=(string)($event['event']??'');
$kind=(string)($event['page_kind']??'home');
$device=(string)($event['device']??'desktop');
$path=(string)($event['page_path']??'');
if($siteId<1||!in_array($type,['view','engagement'],true)||!in_array($kind,['home','section','tag','article','page','login'],true)||!in_array($device,['desktop','tablet','mobile'],true)||strlen($path)>255||strlen($path)<2||!str_starts_with($path,'/public.php?')){http_response_code(400);exit;}
$seconds=max(0,min(1800,(int)($event['seconds']??0)));
$scroll=max(0,min(100,(int)($event['scroll']??0)));
try{
 $cfg=require __DIR__.'/app/config.php';
 $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $q=$pdo->prepare('SELECT id FROM sites WHERE id=? LIMIT 1');$q->execute([$siteId]);
 if(!$q->fetchColumn()){http_response_code(404);exit;}
 if($type==='view'){
  $q=$pdo->prepare("INSERT INTO cms_page_analytics_daily(site_id,event_date,page_path,page_kind,device,pageviews) VALUES(?,UTC_DATE(),?,?,?,1) ON DUPLICATE KEY UPDATE pageviews=pageviews+1");
  $q->execute([$siteId,$path,$kind,$device]);
 }else{
  $q=$pdo->prepare("INSERT INTO cms_page_analytics_daily(site_id,event_date,page_path,page_kind,device,engaged_views,engaged_seconds,scroll_sum) VALUES(?,UTC_DATE(),?,?,?,1,?,?) ON DUPLICATE KEY UPDATE engaged_views=engaged_views+1,engaged_seconds=engaged_seconds+VALUES(engaged_seconds),scroll_sum=scroll_sum+VALUES(scroll_sum)");
  $q->execute([$siteId,$path,$kind,$device,$seconds,$scroll]);
 }
 http_response_code(202);echo '{"ok":true}';
}catch(Throwable $e){error_log('page-analytics: '.$e->getMessage());http_response_code(503);}
