<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require_once __DIR__.'/app/import-engine.php';
require_once __DIR__.'/app/import-source-style.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$u=require_user();
try{
 if($_SERVER['REQUEST_METHOD']!=='POST'||!valid_csrf())throw new RuntimeException('Érvénytelen kérés vagy munkamenet.');
 if(!in_array($u['role'],['platform_admin','site_admin','editor'],true)){http_response_code(403);throw new RuntimeException('Nincs jogosultság a forrásarculat frissítésére.');}
 $id=max(0,(int)($_POST['id']??0));if(!$id)throw new RuntimeException('Hiányzó cikk.');
 $pdo=db();$q=$pdo->prepare('SELECT site_id FROM articles WHERE id=? LIMIT 1');$q->execute([$id]);$site=(int)($q->fetchColumn()?:0);
 if(!$site||!in_array($site,allowed_primary_sites($pdo,$u),true)){http_response_code(403);throw new RuntimeException('Nincs hozzáférés a cikkhez.');}
 $q=$pdo->prepare('SELECT source_url FROM import_article_map WHERE site_id=? AND article_id=? ORDER BY id DESC LIMIT 1');
 $q->execute([$site,$id]);$source=(string)($q->fetchColumn()?:'');
 if(!$source)throw new RuntimeException('Ez a cikk nem kapcsolódik importált forrásoldalhoz.');
 $response=imp_fetch($source,3145728);
 if(!str_contains(strtolower((string)($response['headers']['content-type']??'text/html')),'html'))throw new RuntimeException('A forrás már nem HTML oldal.');
 // The shared profile is cached by (comesy site, source hostname).
 $profile=cms_source_style_collect($site,$response['body'],$response['url']);
 echo json_encode(['ok'=>true,'host'=>$profile['host'],'available'=>$profile['css']!=='' ,'css_files'=>$profile['css_files']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
 error_log('comesy source style refresh: '.$e->getMessage());
 if(http_response_code()===200)http_response_code(400);
 echo json_encode(['ok'=>false,'error'=>mb_substr($e->getMessage(),0,180)],JSON_UNESCAPED_UNICODE);
}