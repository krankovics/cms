<?php
require __DIR__.'/app/bootstrap.php';
require_user();
try{
 $id=(int)($_GET['id']??0);
 $q=db()->prepare('SELECT storage_path,mime_type FROM media_assets WHERE id=?');$q->execute([$id]);$m=$q->fetch();
 if(!$m)throw new RuntimeException('Not found');
 $root=realpath(__DIR__.'/storage/media');$file=realpath(__DIR__.'/storage/media/'.$m['storage_path']);
 if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))throw new RuntimeException('Not found');
 header('Content-Type: '.$m['mime_type']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=300');
 readfile($file);
}catch(Throwable $e){http_response_code(404);}
