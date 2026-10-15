<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$id=max(0,(int)($_GET['id']??0));
$name=(string)($_GET['name']??'');
if(!$id||!preg_match('/^import-[a-f0-9]{36}\.(?:jpg|png|webp|gif)$/',$name)){http_response_code(404);exit;}
try{
 $q=db()->prepare('SELECT storage_path,mime_type FROM media_assets WHERE id=? AND storage_path=?');
 $q->execute([$id,$name]);$asset=$q->fetch();
 if(!$asset||!in_array($asset['mime_type'],['image/jpeg','image/png','image/webp','image/gif'],true))throw new RuntimeException('Image not found.');
 $root=realpath(__DIR__.'/storage/media');
 $file=realpath(__DIR__.'/storage/media/'.$asset['storage_path']);
 if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))throw new RuntimeException('Image not found.');
 header('Content-Type: '.$asset['mime_type']);
 header('X-Content-Type-Options: nosniff');
 header('X-Robots-Tag: noindex, nofollow');
 header("Content-Security-Policy: default-src 'none'; sandbox");
 header('Cache-Control: public, max-age=86400');
 header('Content-Length: '.filesize($file));
 readfile($file);
}catch(Throwable $e){http_response_code(404);}
