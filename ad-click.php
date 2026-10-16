<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$id=max(0,(int)($_GET['id']??0));$token=(string)($_GET['token']??'');if(!$id){http_response_code(404);exit;}
try{
 $cfg=require __DIR__.'/app/config.php';
 $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $q=$db->prepare("SELECT cr.destination_url FROM ad_creatives cr JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id WHERE cr.id=? AND cr.enabled=1 AND z.enabled=1 AND z.site_id=c.site_id AND c.state='active' AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.ends_at IS NULL OR c.ends_at>NOW()) LIMIT 1");
 $q->execute([$id]);$url=(string)$q->fetchColumn();
 if(!$url||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)){http_response_code(404);exit;}
 // A served token makes repeat clicks idempotent for campaign reporting.
 if(preg_match('/^[a-f0-9]{32}$/D',$token)){
  try{
   $update=$db->prepare('UPDATE ad_serves SET clicked_at=UTC_TIMESTAMP() WHERE token=? AND creative_id=? AND clicked_at IS NULL AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 DAY');
   $update->execute([$token,$id]);
   if($update->rowCount()>0)$db->prepare('UPDATE ad_creatives SET clicks=clicks+1 WHERE id=?')->execute([$id]);
  }catch(PDOException $e){
   // A legacy clickable image should continue working before new metrics tables exist.
   $db->prepare('UPDATE ad_creatives SET clicks=clicks+1 WHERE id=?')->execute([$id]);
  }
 }else{
  // Legacy links are still supported and counted in the existing counter.
  $db->prepare('UPDATE ad_creatives SET clicks=clicks+1 WHERE id=?')->execute([$id]);
 }
 header('Location: '.$url,true,302);exit;
}catch(Throwable $e){error_log('ad-click: '.$e->getMessage());http_response_code(503);exit;}
