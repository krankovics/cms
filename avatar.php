<?php
require __DIR__.'/app/bootstrap.php';
$u=require_user();
$base=__DIR__.'/app/avatars/user-'.(int)$u['id'].'.';
foreach(['webp'=>'image/webp','png'=>'image/png','jpg'=>'image/jpeg'] as $ext=>$type){
 $path=$base.$ext;
 if(is_file($path)){header('Content-Type: '.$type);header('Content-Length: '.filesize($path));readfile($path);exit;}
}
$initial=mb_strtoupper(mb_substr(trim($u['name']),0,1));$initial=htmlspecialchars($initial,ENT_XML1|ENT_QUOTES,'UTF-8');
header('Content-Type: image/svg+xml; charset=utf-8');
echo '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96"><rect width="96" height="96" rx="48" fill="#315fd6"/><text x="48" y="62" font-size="43" font-family="Arial,sans-serif" text-anchor="middle" fill="white">'.$initial.'</text></svg>';
