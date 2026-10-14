<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
header('Content-Type: application/json; charset=utf-8');
try {
 $u=user();
 if(!$u){http_response_code(401);echo json_encode(['ok'=>false,'error'=>'A munkamenet lejárt. Jelentkezz be újra.'],JSON_UNESCAPED_UNICODE);exit;}
 if($_SERVER['REQUEST_METHOD']!=='POST'||!valid_csrf())throw new RuntimeException('Érvénytelen kérés.');
 if(!in_array($u['role'],['platform_admin','site_admin','editor','author'],true))throw new RuntimeException('Nincs feltöltési jogosultság.');
 $pdo=db();$site=(int)($_POST['site_id']??0);
 if(!in_array($site,allowed_primary_sites($pdo,$u),true))throw new RuntimeException('Ehhez a webhelyhez nincs feltöltési jogosultság.');
 $file=$_FILES['image']??null;
 if(!$file||$file['error']!==UPLOAD_ERR_OK||$file['size']<1||$file['size']>10485760)throw new RuntimeException('A kép legfeljebb 10 MB lehet.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
 $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
 if(!isset($types[$mime])||!getimagesize($file['tmp_name']))throw new RuntimeException('Csak JPEG, PNG, WebP és GIF tölthető fel.');
 $dir=__DIR__.'/storage/media';
 if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('A médiatár nem írható.');
 $filename=bin2hex(random_bytes(20)).'.'.$types[$mime];
 if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$filename))throw new RuntimeException('Nem sikerült feltölteni a képet.');
 chmod($dir.'/'.$filename,0640);
 try {
  $q=$pdo->prepare('INSERT INTO media_assets(site_id,uploader_id,original_name,storage_path,mime_type,size_bytes,alt_text) VALUES(?,?,?,?,?,?,?)');
  $q->execute([$site,(int)$u['id'],mb_substr(basename($file['name']),0,255),$filename,$mime,$file['size'],'']);
 }catch(Throwable $e){unlink($dir.'/'.$filename);throw $e;}
 echo json_encode(['ok'=>true,'url'=>'/media-file.php?id='.(int)$pdo->lastInsertId()],JSON_THROW_ON_ERROR);
}catch(Throwable $e){error_log('editor upload: '.$e->getMessage());http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'A médiatár adatbázisa nem érhető el.':$e->getMessage()],JSON_UNESCAPED_UNICODE);}
