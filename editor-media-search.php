<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
$u=require_user();
try{
 if($_SERVER['REQUEST_METHOD']!=='GET')throw new RuntimeException('Nem támogatott művelet.');
 $pdo=db();
 $hasImportMeta=(bool)$pdo->query("SHOW TABLES LIKE 'media_import_sources'")->fetchColumn()&&(bool)$pdo->query("SHOW TABLES LIKE 'article_media_uses'")->fetchColumn();
 $ids=allowed_resource_sites($pdo,$u,'media');
 if(!$ids){echo json_encode(['items'=>[],'total'=>0,'page'=>1,'has_more'=>false],JSON_UNESCAPED_UNICODE);exit;}
 $search=mb_substr(trim((string)($_GET['q']??'')),0,100);
 $page=max(1,min(10000,(int)($_GET['page']??1)));
 $limit=48;
 $where='m.site_id IN ('.implode(',',array_map('intval',$ids)).')';
 $params=[];
 if($search!==''){
  if($hasImportMeta){$where.=" AND (m.original_name LIKE ? OR m.alt_text LIKE ? OR s.name LIKE ? OR EXISTS(SELECT 1 FROM media_import_sources mi WHERE mi.media_id=m.id AND (mi.source_host LIKE ? OR mi.source_url LIKE ? OR mi.photographer LIKE ? OR mi.copyright_holder LIKE ?)) OR EXISTS(SELECT 1 FROM article_media_uses am JOIN articles a ON a.id=am.article_id WHERE am.media_id=m.id AND (am.caption LIKE ? OR am.photo_credit LIKE ? OR am.alt_text LIKE ? OR a.title LIKE ?)))";}
  else{$where.=' AND (m.original_name LIKE ? OR m.alt_text LIKE ? OR s.name LIKE ?)';}
  $like='%'.addcslashes($search,'\\%_').'%';$params=array_fill(0,$hasImportMeta?11:3,$like);
 }
 $count=$pdo->prepare('SELECT COUNT(*) FROM media_assets m JOIN sites s ON s.id=m.site_id WHERE '.$where);
 $count->execute($params);$total=(int)$count->fetchColumn();
 $maxPage=max(1,(int)ceil($total/$limit));
 $page=min($page,$maxPage);
 $offset=($page-1)*$limit;
 $stmt=$pdo->prepare('SELECT m.id,m.site_id,m.original_name,m.alt_text,s.name AS site_name FROM media_assets m JOIN sites s ON s.id=m.site_id WHERE '.$where.' ORDER BY m.id DESC LIMIT '.$limit.' OFFSET '.$offset);
 $stmt->execute($params);
 $items=[];
 foreach($stmt->fetchAll() as $row)$items[]=[
  'id'=>(int)$row['id'],
  'url'=>'/media-file.php?id='.(int)$row['id'],
  'site_id'=>(int)$row['site_id'],
  'name'=>$row['original_name'],
  'alt'=>$row['alt_text'],
  'site'=>$row['site_name']
 ];
 echo json_encode(['items'=>$items,'total'=>$total,'page'=>$page,'has_more'=>$page*$limit<$total],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
 error_log('comesy media picker: '.$e->getMessage());
 http_response_code(500);
 echo json_encode(['error'=>'A médiatár betöltése sikertelen.'],JSON_UNESCAPED_UNICODE);
}
