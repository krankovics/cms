<?php
require __DIR__.'/app/bootstrap.php';
$u=require_user();$error='';
try{
 $pdo=db();$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();$site=(int)($_REQUEST['site_id']??($sites[0]['id']??0));
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  if(!in_array($u['role'],['platform_admin','site_admin','editor','author'],true))throw new RuntimeException('Nincs jogosultság.');
  $f=$_FILES['image']??null;
  if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>10485760||$f['size']<1)throw new RuntimeException('Maximum 10 MB méretű kép tölthető fel.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
  if(!isset($extensions[$mime])||!getimagesize($f['tmp_name']))throw new RuntimeException('Nem támogatott képformátum.');
  $check=$pdo->prepare('SELECT id FROM sites WHERE id=?');$check->execute([$site]);if(!$check->fetch())throw new RuntimeException('Érvénytelen webhely.');
  $dir=__DIR__.'/storage/media';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('A tárhely nem írható.');
  $name=bin2hex(random_bytes(20)).'.'.$extensions[$mime];
  if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Feltöltési hiba.');
  chmod($dir.'/'.$name,0640);
  try{$q=$pdo->prepare('INSERT INTO media_assets(site_id,uploader_id,original_name,storage_path,mime_type,size_bytes,alt_text) VALUES(?,?,?,?,?,?,?)');$q->execute([$site,$u['id'],mb_substr(basename($f['name']),0,255),$name,$mime,$f['size'],mb_substr(trim((string)($_POST['alt']??'')),0,500)]);}catch(Throwable $e){unlink($dir.'/'.$name);throw $e;}
  header('Location: /media.php?site_id='.$site.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT id,original_name,alt_text FROM media_assets WHERE site_id=? ORDER BY id DESC LIMIT 100');$q->execute([$site]);$items=$q->fetchAll();
}catch(Throwable $e){error_log('media: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázis-migráció szükséges.':$e->getMessage();$sites=$sites??[];$items=[];}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Médiatár | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{margin:0;font-family:system-ui}.wrap{max-width:1000px;margin:auto;padding:20px}.panel{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:20px;margin:16px 0}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px}.asset{border:1px solid var(--line);padding:10px;border-radius:10px;overflow-wrap:anywhere}.asset img{width:100%;aspect-ratio:1;object-fit:cover}input,select,button{font-size:16px;padding:10px;max-width:100%}</style></head><body><?php require __DIR__.'/app/header.php';?><main class="wrap"><h1>Médiatár</h1><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><form method="get" class="panel">Webhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></form><form method="post" enctype="multipart/form-data" class="panel"><h2>Új kép feltöltése</h2><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><p><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required></p><p><label>Alternatív szöveg <input name="alt" maxlength="500"></label></p><button type="submit">Feltöltés</button><p>JPEG, PNG, WebP, GIF · legfeljebb 10 MB</p></form><section class="panel"><h2>Képek</h2><div class="grid"><?php foreach($items as $a):?><div class="asset"><img loading="lazy" src="/media-file.php?id=<?=(int)$a['id']?>" alt="<?=h($a['alt_text'])?>"><p><?=h($a['original_name'])?></p><button type="button" data-url="/media-file.php?id=<?=(int)$a['id']?>" onclick="navigator.clipboard.writeText(location.origin+this.dataset.url)">URL másolása</button></div><?php endforeach;?></div></section></main></body></html>