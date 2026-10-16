<?php
declare(strict_types=1);
require_once __DIR__.'/import-engine.php';

/** Capture rich image metadata without importing arbitrary external markup. */
function cms_import_media_candidates(array $blocks,string $cover='',string $sourceUrl=''):array{
 $items=[];$index=0;
 $add=static function(string $url,string $role,string $alt='',string $caption='',string $credit='')use(&$items,&$index):void{
  $url=trim($url);if(!$url||strlen($url)>1000)return;
  try{$url=imp_url_metadata($url);}catch(Throwable $e){return;}
  if(!preg_match('~^https?://~i',$url))return;
  $key=$role.'|'.hash('sha256',$url);
  if(isset($items[$key]))return;
  $clean=static fn(string $s,int $max):string=>mb_substr(trim((string)preg_replace('/\s+/u',' ',strip_tags($s))),0,$max);
  $items[$key]=['url'=>$url,'role'=>$role,'alt'=>$clean($alt,500),'caption'=>$clean($caption,1800),'credit'=>$clean($credit,500),'index'=>$index++];
 };
 if($cover!=='')$add($cover,'cover');
 foreach($blocks as $block){
  if(!is_array($block))continue;
  if(($block['type']??'')==='image'){
   $add((string)($block['text']??''),'inline',(string)($block['alt']??''),(string)($block['caption']??''),(string)($block['credit']??''));continue;
  }
  if(($block['type']??'')!=='richhtml')continue;
  $html=(string)($block['text']??'');if($html===''||strlen($html)>800000)continue;
  $doc=new DOMDocument('1.0','UTF-8');$prior=libxml_use_internal_errors(true);
  $parsed=$doc->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
  libxml_clear_errors();libxml_use_internal_errors($prior);
  if(!$parsed)continue;
  $xpath=new DOMXPath($doc);
  foreach($xpath->query('//img[@src]') as $img){
   if(!$img instanceof DOMElement)continue;
   $url=(string)$img->getAttribute('src');
   $fig=$xpath->query('ancestor::figure[1]',$img)->item(0);
   $caption='';
   if($fig instanceof DOMElement){
    $cap=$xpath->query('.//figcaption[1]',$fig)->item(0);
    if($cap)$caption=(string)$cap->textContent;
   }
   $credit=(string)($img->getAttribute('data-credit')?:$img->getAttribute('title'));
   if($caption!==''&&preg_match('/(?:fotó|fénykép|photo|kép|forrás)\s*:\s*([^|;,]{2,190})/iu',$caption,$m))$credit=trim($m[1]);
   $add($url,'inline',$img->getAttribute('alt'),$caption,$credit);
  }
 }
 return array_values($items);
}
/** Source assets remain private and their legal status is explicitly unknown. */
function cms_import_media_one(PDO $pdo,int $site,int $article,string $articleUrl,array $item):int{
 $url=(string)$item['url'];$hash=hash('sha256',$url);
 $find=$pdo->prepare('SELECT media_id FROM media_import_sources WHERE site_id=? AND source_url_hash=?');
 $find->execute([$site,$hash]);$mediaId=(int)$find->fetchColumn();
 if(!$mediaId){
  // Identical SSRF and robots rules used by the article importer apply here.
  $response=imp_fetch($url,10485760);
  if((int)$response['status']!==200)throw new RuntimeException('A kép letöltése nem sikerült (HTTP '.$response['status'].').');
  $raw=(string)$response['body'];$length=strlen($raw);
  if($length<100||$length>10485760)throw new RuntimeException('Kép mérete kívül esik a korláton.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
  $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
  if(!isset($types[$mime]))throw new RuntimeException('Nem engedélyezett képfájl.');
  $dimensions=@getimagesizefromstring($raw);
  if(!$dimensions||($dimensions['mime']??'')!==$mime)throw new RuntimeException('A kép nem értelmezhető.');
  [$w,$h]=$dimensions;
  if($w<1||$h<1||$w>20000||$h>20000||$w*$h>80000000)throw new RuntimeException('Túl nagy képfelbontás.');
  $dir=dirname(__DIR__).'/storage/media';
  if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('A médiakönyvtár nem írható.');
  $name=bin2hex(random_bytes(20)).'.'.$types[$mime];
  $path=$dir.'/'.$name;
  if(file_put_contents($path,$raw,LOCK_EX)!==$length)throw new RuntimeException('A képfájl nem menthető.');
  chmod($path,0640);
  $original=rawurldecode((string)basename((string)parse_url($url,PHP_URL_PATH)));
  $original=mb_substr(preg_replace('/[^\pL\pN. _-]+/u','_',trim($original))?:'import-image.'.$types[$mime],0,255);
  try{
   $pdo->beginTransaction();
   $q=$pdo->prepare('INSERT INTO media_assets(site_id,uploader_id,original_name,storage_path,mime_type,size_bytes,alt_text) VALUES(?,NULL,?,?,?,?,?)');
   $q->execute([$site,$original,$name,$mime,$length,(string)$item['alt']]);
   $mediaId=(int)$pdo->lastInsertId();
   $q=$pdo->prepare('INSERT INTO media_import_sources(media_id,site_id,source_url,source_url_hash,source_host,source_article_url,width_px,height_px,sha256,photographer) VALUES(?,?,?,?,?,?,?,?,?,?)');
   // Record the publisher's hostname even if the image bytes came from a CDN.
   $publisherHost=(string)(parse_url($articleUrl,PHP_URL_HOST)?:parse_url($url,PHP_URL_HOST));
   $q->execute([$mediaId,$site,$url,$hash,mb_substr(strtolower($publisherHost),0,255),$articleUrl,$w,$h,hash('sha256',$raw),(string)$item['credit']]);
   $pdo->commit();
  }catch(Throwable $e){
   if($pdo->inTransaction())$pdo->rollBack();
   @unlink($path);throw $e;
  }
 }
 $q=$pdo->prepare("INSERT INTO article_media_uses(site_id,article_id,media_id,role,original_url,alt_text,caption,photo_credit,position_index) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE alt_text=VALUES(alt_text),caption=VALUES(caption),photo_credit=VALUES(photo_credit),position_index=VALUES(position_index)");
 $q->execute([$site,$article,$mediaId,$item['role'],$url,$item['alt'],$item['caption'],$item['credit'],(int)$item['index']]);
 return $mediaId;
}
function cms_import_media_enqueue(PDO $pdo,int $site,int $article):void{
 $q=$pdo->prepare("INSERT INTO media_import_queue(site_id,article_id,status,available_at,last_error,next_offset,attempts,found_count,imported_count,failed_count) VALUES(?,?,'pending',NOW(),'',0,0,0,0,0) ON DUPLICATE KEY UPDATE status='pending',available_at=NOW(),last_error='',next_offset=0,attempts=0,found_count=0,imported_count=0,failed_count=0");
 $q->execute([$site,$article]);
}
function cms_import_media_work_once(PDO $pdo):bool{
 $pdo->beginTransaction();
 try{
  $pdo->exec("UPDATE media_import_queue SET status='pending' WHERE status='processing' AND updated_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)");
  $q=$pdo->query("SELECT site_id,article_id,next_offset,imported_count,failed_count FROM media_import_queue WHERE status='pending' AND available_at<=NOW() ORDER BY updated_at LIMIT 1 FOR UPDATE SKIP LOCKED");
  $job=$q->fetch();
  if(!$job){$pdo->commit();return false;}
  $pdo->prepare("UPDATE media_import_queue SET status='processing',attempts=attempts+1 WHERE site_id=? AND article_id=?")->execute([$job['site_id'],$job['article_id']]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 $site=(int)$job['site_id'];$article=(int)$job['article_id'];
 try{
  $q=$pdo->prepare('SELECT a.content_json,m.og_image_url,m.canonical_url FROM articles a LEFT JOIN article_meta m ON m.article_id=a.id WHERE a.id=? AND a.site_id=? LIMIT 1');
  $q->execute([$article,$site]);$row=$q->fetch();
  if(!$row)throw new RuntimeException('A cikk időközben eltűnt.');
  $blocks=json_decode((string)$row['content_json'],true);
  if(!is_array($blocks))$blocks=[];
  $items=cms_import_media_candidates($blocks,(string)($row['og_image_url']??''),(string)($row['canonical_url']??''));
  $total=count($items);$offset=(int)$job['next_offset'];
  $success=(int)$job['imported_count'];$failed=(int)$job['failed_count'];$lastError='';
  // Max six remote assets per cron iteration; no prolonged work or request storm.
  foreach(array_slice($items,$offset,6) as $item){
   try{cms_import_media_one($pdo,$site,$article,(string)($row['canonical_url']??''),$item);$success++;}
   catch(Throwable $e){$failed++;$lastError=mb_substr($e->getMessage(),0,450);error_log('comesy image '.$site.'/'.$article.': '.$lastError);}
  }
  $offset=min($total,$offset+6);
  $status=$offset<$total?'pending':'done';
  $pdo->prepare('UPDATE media_import_queue SET status=?,found_count=?,imported_count=?,failed_count=?,next_offset=?,last_error=?,available_at=NOW() WHERE site_id=? AND article_id=?')
   ->execute([$status,$total,$success,$failed,$offset,$lastError,$site,$article]);
 }catch(Throwable $e){
  $q=$pdo->prepare("UPDATE media_import_queue SET status='failed',last_error=? WHERE site_id=? AND article_id=?");
  $q->execute([mb_substr($e->getMessage(),0,500),$site,$article]);
  error_log('comesy media import '.$site.'/'.$article.': '.$e->getMessage());
 }
 return true;
}
