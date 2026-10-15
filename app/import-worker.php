<?php
declare(strict_types=1);
require_once __DIR__.'/import-engine.php';
function imp_requeue_orphaned(PDO $pdo):void{
 $pdo->exec("UPDATE import_items i JOIN import_jobs j ON j.id=i.job_id SET i.status='pending',i.available_at=NOW(),i.claimed_at=NULL WHERE i.status='processing' AND i.claimed_at<DATE_SUB(NOW(),INTERVAL 20 MINUTE) AND j.status IN ('running','queued')");
}
function imp_claim(PDO $pdo):?array{
 $pdo->beginTransaction();
 try{
  $q=$pdo->query("SELECT i.*,j.mode,j.source_url AS job_source,j.max_items,j.created_by FROM import_items i JOIN import_jobs j ON j.id=i.job_id WHERE i.status='pending' AND i.available_at<=NOW() AND j.status IN ('queued','running') ORDER BY i.id LIMIT 1 FOR UPDATE SKIP LOCKED");
  $task=$q->fetch();
  if(!$task){$pdo->commit();return null;}
  $pdo->prepare("UPDATE import_items SET status='processing',attempts=attempts+1,claimed_at=NOW() WHERE id=?")->execute([$task['id']]);
  $pdo->prepare("UPDATE import_jobs SET status='running' WHERE id=? AND status='queued'")->execute([$task['job_id']]);
  $pdo->commit();return $task;
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function imp_queue_discovery(PDO $pdo,array $task,array $urls,bool $index):int{
 $jobId=(int)$task['job_id'];$site=(int)$task['site_id'];$depth=(int)$task['depth'];$limit=(int)$task['max_items'];
 $q=$pdo->prepare("SELECT COUNT(*) FROM import_items WHERE job_id=? AND task_type='article'");$q->execute([$jobId]);$used=(int)$q->fetchColumn();
 $queue=$pdo->prepare('INSERT IGNORE INTO import_items(job_id,site_id,source_url,source_url_hash,task_type,depth) VALUES(?,?,?,?,?,?)');
 $count=0;
 foreach($urls as $url){
  if(!$index&&$used>=$limit)break;
  if($index&&$depth>=3)continue;
  $type=$index?'discover':'article';
  try{
   $url=imp_url_metadata((string)$url);
   if(parse_url($url,PHP_URL_HOST)!==parse_url($task['job_source'],PHP_URL_HOST))continue;
   // Home pages and feeds may legitimately appear in urlset sitemaps.
   // Never enqueue them as articles just because they have a <loc>.
   if(!$index&&!imp_is_article_candidate_url($url))continue;
  }catch(Throwable $e){continue;}
  $queue->execute([$jobId,$site,$url,hash('sha256',$url),$type,$depth+1]);
  if($queue->rowCount()){ $count++;if(!$index)$used++; }
 }
 return $count;
}
function imp_snapshot(string $raw,int $site,int $job):string{
 $hash=hash('sha256',$raw);
 $relative='storage/import/'.$site.'/'.$job.'/'.$hash.'.html';
 $dest=dirname(__DIR__).'/'.$relative;
 $dir=dirname($dest);
 if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Nem lehet forráspillanatképet menteni.');
 if(!is_file($dest)&&file_put_contents($dest,$raw,LOCK_EX)===false)throw new RuntimeException('Forráspillanatkép mentési hiba.');
 chmod($dest,0640);return $relative;
}
function imp_save_article(PDO $pdo,array $task,array $data,string $html):array{
 $source=$task['source_url'];$site=(int)$task['site_id'];$actor=$task['created_by']?(int)$task['created_by']:null;
 $urlHash=hash('sha256',$source);$contentHash=hash('sha256',$html);
 $json=json_encode($data['blocks'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $snapshot=imp_snapshot($html,$site,(int)$task['job_id']);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT id,article_id,source_hash FROM import_article_map WHERE site_id=? AND source_url_hash=? FOR UPDATE');
  $q->execute([$site,$urlHash]);$map=$q->fetch();
  $articleId=(int)($map['article_id']??0);
  $status='completed';$message=null;
  if($map&&$map['source_hash']===$contentHash&&$articleId){$status='skipped';}
  else{
   $currentStatus=null;
   if($articleId){
    $q=$pdo->prepare('SELECT status FROM articles WHERE id=? AND site_id=? FOR UPDATE');
    $q->execute([$articleId,$site]);$currentStatus=$q->fetchColumn();
    if($currentStatus===false)$articleId=0;
   }
   if($articleId&&$currentStatus!=='draft'&&$currentStatus!=='review'){
    $status='skipped';$message='A forrás változott, de a publikált cikk csak kézi jóváhagyással írható felül.';
   }else{
    if($articleId){
     $pdo->prepare('UPDATE articles SET title=?,`lead`=?,content_json=?,updated_at=NOW() WHERE id=? AND site_id=?')
      ->execute([$data['title'],$data['lead'],$json,$articleId,$site]);
    }else{
     $slug=mb_strtolower($data['title']);
     $slug=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$slug)?:$slug;
     $slug=substr(trim((string)preg_replace('/[^a-z0-9]+/','-',$slug),'-'),0,175).'-'.substr($urlHash,0,14);
     $q=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,`lead`,content_json,status) VALUES(?,?,?,?,?,?,'draft')");
     $q->execute([$site,$actor,$data['title'],$slug,$data['lead'],$json]);$articleId=(int)$pdo->lastInsertId();
    }
    $q=$pdo->prepare("INSERT INTO article_meta(article_id,seo_description,canonical_url,og_image_url) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE seo_description=VALUES(seo_description),canonical_url=VALUES(canonical_url),og_image_url=IF(VALUES(og_image_url)<>'',VALUES(og_image_url),og_image_url)");
    $q->execute([$articleId,mb_substr($data['lead'],0,500),$source,(string)($data['og_image_url']??'')]);
    $q=$pdo->prepare('INSERT IGNORE INTO article_sites(article_id,site_id) VALUES(?,?)');$q->execute([$articleId,$site]);
    $q=$pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM article_revisions WHERE article_id=?');$q->execute([$articleId]);$rev=(int)$q->fetchColumn();
    $q=$pdo->prepare("INSERT INTO article_revisions(article_id,editor_id,revision_number,title,`lead`,content_json,status) VALUES(?,?,?,?,?,?,'draft')");
    $q->execute([$articleId,$actor,$rev,$data['title'],$data['lead'],$json]);
    if(!$map){
     $q=$pdo->prepare('INSERT INTO import_article_map(site_id,source_url,source_url_hash,article_id,source_hash,snapshot_path) VALUES(?,?,?,?,?,?)');
     $q->execute([$site,$source,$urlHash,$articleId,$contentHash,$snapshot]);
    }else{
     $q=$pdo->prepare('UPDATE import_article_map SET article_id=?,source_hash=?,snapshot_path=?,updated_at=NOW() WHERE id=?');
     $q->execute([$articleId,$contentHash,$snapshot,$map['id']]);
    }
    // Original image URLs and internal links stay attached to the editable article.
    $pdo->prepare('DELETE FROM import_asset_refs WHERE article_id=?')->execute([$articleId]);
    $ref=$pdo->prepare('INSERT IGNORE INTO import_asset_refs(site_id,article_id,source_url,source_url_hash,kind,internal_link) VALUES(?,?,?,?,?,?)');
    $origin=strtolower((string)parse_url($source,PHP_URL_HOST));
    if(!empty($data['og_image_url'])&&strlen((string)$data['og_image_url'])<=1000){
     $img=(string)$data['og_image_url'];
     $ref->execute([$site,$articleId,$img,hash('sha256',$img),'image',(int)(strtolower((string)parse_url($img,PHP_URL_HOST))===$origin)]);
    }
    foreach($data['blocks'] as $block)if(($block['type']??'')==='image'){
     $img=(string)($block['text']??'');
     if(!filter_var($img,FILTER_VALIDATE_URL))continue;
     $ref->execute([$site,$articleId,$img,hash('sha256',$img),'image',(int)(strtolower((string)parse_url($img,PHP_URL_HOST))===$origin)]);
    }
    // Extract image and embed/link references embedded inside a full rich HTML
    // body, not only legacy standalone image blocks.
    foreach($data['blocks'] as $block)if(($block['type']??'')==='richhtml'){
     $found=cms_rich_references((string)($block['text']??''));
     foreach($found['images'] as $img){
      if(strlen($img)>1000)continue; // Existing import_asset_refs URL column limit.
      $ref->execute([$site,$articleId,$img,hash('sha256',$img),'image',(int)(strtolower((string)parse_url($img,PHP_URL_HOST))===$origin)]);
     }
     foreach($found['links'] as $urlValue){
      if(strlen($urlValue)>1000)continue;
      $ref->execute([$site,$articleId,$urlValue,hash('sha256',$urlValue),'link',(int)(strtolower((string)parse_url($urlValue,PHP_URL_HOST))===$origin)]);
     }
    }
    foreach(($data['links']??[]) as $link){
     if(!is_array($link)||!isset($link['url']))continue;
     $urlValue=(string)$link['url'];if(!filter_var($urlValue,FILTER_VALIDATE_URL))continue;
     $ref->execute([$site,$articleId,$urlValue,hash('sha256',$urlValue),'link',(int)!empty($link['internal'])]);
    }
    $pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)')
     ->execute([$actor,'import.article','article',$articleId,json_encode(['source_url'=>$source,'job_id'=>$task['job_id'],'revision'=>$rev],JSON_THROW_ON_ERROR)]);
   }
  }
  $pdo->commit();return ['status'=>$status,'article_id'=>$articleId,'hash'=>$contentHash,'error'=>$message];
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function imp_process(PDO $pdo,array $task):array{
 $url=(string)$task['source_url'];$mode=(string)$task['mode'];$type=(string)$task['task_type'];
 if($type==='discover'){
  if($mode==='site'){
   $p=parse_url($url);$url=$p['scheme'].'://'.$p['host'].'/sitemap.xml';
  }
  // XML sitemaps can legitimately be far larger than an HTML article.
  // Keep the 3 MiB article limit; allow a bounded 32 MiB for discovery.
  $result=imp_fetch($url,33554432);
  if($mode==='demo'&&!str_contains(strtolower((string)($result['headers']['content-type']??'')),'xml')){
   $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
   $doc->loadHTML('<?xml encoding="utf-8"?>'.$result['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
   libxml_clear_errors();libxml_use_internal_errors($previous);
   $links=[];
   foreach($doc->getElementsByTagName('a') as $anchor){$href=$anchor->getAttribute('href');if($href)$links[]=imp_resolve_url($href,$result['url']);}
   $discovered=['urls'=>$links,'index'=>false];
  }else{$discovered=imp_discover($result['body'],$result['url'],min(1000,(int)$task['max_items']));}
  $added=imp_queue_discovery($pdo,$task,$discovered['urls'],$discovered['index']);
  $pdo->prepare('UPDATE import_jobs SET discovered=discovered+? WHERE id=?')->execute([$added,$task['job_id']]);
  $pdo->prepare("UPDATE import_items SET status='completed',claimed_at=NULL WHERE id=?")->execute([$task['id']]);
  return ['result'=>'discovered','response_bytes'=>strlen($result['body']),'quality_flags'=>[]];
 }
 if(!imp_is_article_candidate_url($url))throw new RuntimeException('A forrás címlap, gyűjtőoldal vagy adatfolyam, nem cikk URL.');
 $result=imp_fetch($url);
 $contentType=strtolower((string)($result['headers']['content-type']??'text/html'));
 if(!str_contains($contentType,'html'))throw new RuntimeException('A forrás nem HTML-cikk.');
 $data=imp_extract($result['body'],$result['url']);
 $saved=imp_save_article($pdo,$task,$data,$result['body']);
 $pdo->prepare('UPDATE import_items SET status=?,article_id=?,source_hash=?,last_error=?,claimed_at=NULL WHERE id=?')
  ->execute([$saved['status'],$saved['article_id'],$saved['hash'],$saved['error'],$task['id']]);
 $col=$saved['status']==='skipped'?'skipped':'completed';
 $pdo->prepare('UPDATE import_jobs SET '.$col.'='.$col.'+1 WHERE id=?')->execute([$task['job_id']]);
 return [
  'result'=>$saved['status']==='skipped'?'unchanged':'imported',
  'response_bytes'=>strlen($result['body']),
  'block_count'=>imp_editorial_block_count($data['blocks']),
  'image_count'=>imp_rich_images($data['blocks']),
  'link_count'=>count($data['links']??[]),
  'quality_flags'=>imp_quality_flags($data)
 ];
}
/**
 * Store one bounded quality and resource measurement per queue item.
 * These are transfer and application timings, not server CPU cost estimates.
 */
function imp_store_metrics(PDO $pdo,array $task,array $stats,int $durationMs):void{
 $flags=$stats['quality_flags']??[];
 if(!is_array($flags))$flags=[];
 $result=(string)($stats['result']??'failed');
 if(!in_array($result,['imported','unchanged','discovered','retry','failed'],true))$result='failed';
 $sql='INSERT INTO import_item_metrics(item_id,job_id,site_id,result,response_bytes,duration_ms,block_count,image_count,link_count,quality_flags) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE result=VALUES(result),response_bytes=VALUES(response_bytes),duration_ms=VALUES(duration_ms),block_count=VALUES(block_count),image_count=VALUES(image_count),link_count=VALUES(link_count),quality_flags=VALUES(quality_flags),processed_at=CURRENT_TIMESTAMP';
 $pdo->prepare($sql)->execute([
  (int)$task['id'],(int)$task['job_id'],(int)$task['site_id'],$result,
  max(0,min(PHP_INT_MAX,(int)($stats['response_bytes']??0))),
  max(0,min(4294967295,$durationMs)),
  max(0,min(65535,(int)($stats['block_count']??0))),
  max(0,min(65535,(int)($stats['image_count']??0))),
  max(0,min(65535,(int)($stats['link_count']??0))),
  json_encode(array_slice(array_values(array_unique(array_map('strval',$flags))),0,20),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)
 ]);
}
function imp_editorial_block_count(array $blocks):int{
 $count=0;
 foreach($blocks as $b){
  if(($b['type']??'')==='richhtml'){
   $count+=max(1,(int)preg_match_all('~<(?:p|h[2-6]|figure|table|iframe|blockquote|ul|ol|video|audio)\\b~i',(string)($b['text']??'')));
  }else $count++;
 }
 return $count;
}
function imp_rich_images(array $blocks):int{
 $images=[];
 foreach($blocks as $b){
  if(($b['type']??'')==='image'&&!empty($b['text']))$images[(string)$b['text']]=true;
  elseif(($b['type']??'')==='richhtml'){
   foreach(cms_rich_references((string)($b['text']??''))['images'] as $src)$images[$src]=true;
  }
 }
 return count($images);
}
function imp_quality_flags(array $article):array{
 $flags=[];
 if(mb_strlen(trim((string)($article['lead']??'')))<60)$flags[]='short_lead';
 if(imp_editorial_block_count($article['blocks']??[])<3)$flags[]='few_blocks';
 if(imp_rich_images($article['blocks']??[])===0)$flags[]='no_images';
 if(!count(array_filter($article['blocks']??[],fn($b)=>($b['type']??'')==='heading'||(($b['type']??'')==='richhtml'&&preg_match('~<h[2-6]\\b~i',(string)($b['text']??''))))))$flags[]='no_headings';
 return $flags;
}
function imp_work_once(PDO $pdo):bool{
 imp_requeue_orphaned($pdo);
 $task=imp_claim($pdo);
 if(!$task)return false;
 $started=microtime(true);
 try{
  $metrics=imp_process($pdo,$task);
  try{imp_store_metrics($pdo,$task,$metrics,(int)round((microtime(true)-$started)*1000));}
  catch(Throwable $metricError){error_log('comesy import metric save #'.$task['id'].': '.$metricError->getMessage());}
 }
 catch(Throwable $e){
  $message=mb_substr($e->getMessage(),0,1000);
  error_log('comesy import task #'.$task['id'].': '.$message);
  // A missing article body is not a temporary transport error: retrying it
  // every minute wastes requests and does not make missing source text appear.
  if($e instanceof ImportContentRejected){
   $pdo->prepare("UPDATE import_items SET status='skipped',claimed_at=NULL,last_error=? WHERE id=?")->execute([$message,$task['id']]);
   $pdo->prepare('UPDATE import_jobs SET skipped=skipped+1 WHERE id=?')->execute([$task['job_id']]);
   try{imp_store_metrics($pdo,$task,['result'=>'failed','quality_flags'=>['source_content_rejected']],(int)round((microtime(true)-$started)*1000));}
   catch(Throwable $metricError){error_log('comesy import metric save #'.$task['id'].': '.$metricError->getMessage());}
  }else{
  $attempt=(int)$task['attempts']+1;$retry=$attempt<3;
  if($retry){
   $delay=min(3600,60*(2**$attempt));
   $pdo->prepare("UPDATE import_items SET status='pending',available_at=DATE_ADD(NOW(), INTERVAL ? SECOND),claimed_at=NULL,last_error=? WHERE id=?")
    ->execute([$delay,$message,$task['id']]);
  }else{
   $pdo->prepare("UPDATE import_items SET status='failed',claimed_at=NULL,last_error=? WHERE id=?")->execute([$message,$task['id']]);
   $pdo->prepare('UPDATE import_jobs SET failed=failed+1,last_error=? WHERE id=?')->execute([$message,$task['job_id']]);
  }
  try{imp_store_metrics($pdo,$task,['result'=>$retry?'retry':'failed','quality_flags'=>[$retry?'retry_pending':'processing_failed']],(int)round((microtime(true)-$started)*1000));}
  catch(Throwable $metricError){error_log('comesy import metric save #'.$task['id'].': '.$metricError->getMessage());}
  }
 }
 $q=$pdo->prepare("SELECT COUNT(*) FROM import_items WHERE job_id=? AND status IN ('pending','processing')");
 $q->execute([$task['job_id']]);$remaining=(int)$q->fetchColumn();
 if($remaining===0){
  $pdo->prepare("UPDATE import_jobs SET status=IF(failed>0,'failed','finished') WHERE id=? AND status='running'")->execute([$task['job_id']]);
  $pdo->prepare("UPDATE import_asset_refs r JOIN import_article_map m ON m.site_id=r.site_id AND m.source_url_hash=r.source_url_hash SET r.target_article_id=m.article_id,r.status='ready' WHERE r.site_id=? AND r.kind='link' AND r.internal_link=1 AND r.target_article_id IS NULL AND m.article_id IS NOT NULL")->execute([$task['site_id']]);
 }
 return true;
}
