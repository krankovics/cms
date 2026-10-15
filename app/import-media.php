<?php
declare(strict_types=1);
/** Download images only through the existing public-IP/robots-aware fetcher. */
function imp_import_image_asset(PDO $pdo,int $site,?int $actor,string $url,string $alt=''):string{
 $url=imp_url_metadata($url);
 $sourceName='import-'.hash('sha256',$url);
 $q=$pdo->prepare("SELECT id,storage_path FROM media_assets WHERE site_id=? AND original_name=? ORDER BY id LIMIT 1");
 $q->execute([$site,$sourceName]);$prev=$q->fetch();
 if($prev&&is_file(dirname(__DIR__).'/storage/media/'.$prev['storage_path'])){
  if($alt!=='')$pdo->prepare("UPDATE media_assets SET alt_text=? WHERE id=? AND alt_text=''")->execute([mb_substr($alt,0,500),$prev['id']]);
  return 'https://cms.8media.hu/media-public.php?id='.(int)$prev['id'].'&name='.rawurlencode($prev['storage_path']);
 }
 $response=imp_fetch($url,8388608);
 if(strlen($response['body'])<64)throw new RuntimeException('Üres képforrás.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($response['body']);
 $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
 if(!isset($types[$mime])||@getimagesizefromstring($response['body'])===false)throw new RuntimeException('A kép nem támogatott vagy hibás.');
 $root=dirname(__DIR__).'/storage/media';
 if(!is_dir($root)&&!mkdir($root,0750,true)&&!is_dir($root))throw new RuntimeException('A képtár nem írható.');
 $relative='import-'.bin2hex(random_bytes(18)).'.'.$types[$mime];
 $file=$root.'/'.$relative;
 if(file_put_contents($file,$response['body'],LOCK_EX)===false)throw new RuntimeException('A forráskép mentése sikertelen.');
 chmod($file,0640);
 try{
  $q=$pdo->prepare('INSERT INTO media_assets(site_id,uploader_id,original_name,storage_path,mime_type,size_bytes,alt_text) VALUES(?,?,?,?,?,?,?)');
  $q->execute([$site,$actor,$sourceName,$relative,$mime,strlen($response['body']),mb_substr($alt,0,500)]);
  $id=(int)$pdo->lastInsertId();
 }catch(Throwable $e){@unlink($file);throw $e;}
 return 'https://cms.8media.hu/media-public.php?id='.$id.'&name='.rawurlencode($relative);
}
function imp_localize_article_images(PDO $pdo,array $task,array &$data):void{
 $delta=$data['richtext']['ops']??[];
 $featured=(string)($data['featured_image']??'');
 $all=imp_rich_media_urls(['ops'=>$delta],$featured);
 $altByUrl=[];
 if($featured)$altByUrl[$featured]=(string)($data['featured_meta']['alt']??'');
 foreach($delta as $op)if(is_array($op['insert']['cmsFigure']??null)){
  $fig=$op['insert']['cmsFigure'];$altByUrl[(string)($fig['src']??'')]=(string)($fig['alt']??'');
 }
 $map=[];$failed=0;
 // Bounded by cost and time: a story with >18 images requires manual follow-up.
 foreach(array_slice($all,0,18) as $url){
  try{$map[$url]=imp_import_image_asset($pdo,(int)$task['site_id'],isset($task['created_by'])?(int)$task['created_by']:null,$url,$altByUrl[$url]??'');}
  catch(Throwable $e){$failed++;error_log('comesy image import '.substr($url,0,150).' '.$e->getMessage());}
 }
 if(count($all)>18)$failed+=count($all)-18;
 foreach($delta as &$op){
  if(!is_array($op['insert']['cmsFigure']??null))continue;
  $src=(string)($op['insert']['cmsFigure']['src']??'');
  if(isset($map[$src])){$op['insert']['cmsFigure']['source_url']=$src;$op['insert']['cmsFigure']['src']=$map[$src];}
 }
 unset($op);
 $data['richtext']['ops']=$delta;
 $data['featured_image']=$map[$featured]??$featured;
 $data['media_warnings']=$failed;
 $data['media_copied']=count($map);
 $data['media_resolved']=$map;
}
