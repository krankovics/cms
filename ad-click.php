<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
$id=max(0,(int)($_GET['id']??0));if(!$id){http_response_code(404);exit;}
try{
 $cfg=require __DIR__.'/app/config.php';
 $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 $q=$db->prepare("SELECT cr.destination_url FROM ad_creatives cr JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id WHERE cr.id=? AND cr.enabled=1 AND z.enabled=1 AND z.site_id=c.site_id AND c.state='active' AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.ends_at IS NULL OR c.ends_at>NOW()) LIMIT 1");
 $q->execute([$id]);$url=(string)$q->fetchColumn();
 if(!$url||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)){http_response_code(404);exit;}
 $db->prepare('UPDATE ad_creatives SET clicks=clicks+1 WHERE id=?')->execute([$id]);
 header('Location: '.$url,true,302);exit;
}catch(Throwable $e){error_log('ad-click: '.$e->getMessage());http_response_code(503);exit;}
