<?php
declare(strict_types=1);
require_once __DIR__.'/article-sites.php';
require_once __DIR__.'/source-taxonomy.php';

/**
 * Find articles imported from this site's EXACT hostname, regardless of the
 * original import target. They are merely suggestions until an admin assigns
 * them: a visual template does not silently publish or relocate content.
 */
function cms_site_import_candidates(PDO $pdo,int $siteId,string $domain,int $limit=500):array{
 if($siteId<1)return [];
 $host=strtolower(preg_replace('/^www\./i','',trim($domain)));
 if(!preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D',$host))return [];
 $limit=max(1,min(500,$limit));
 $patterns=['https://'.$host.'/%','https://www.'.$host.'/%','http://'.$host.'/%','http://www.'.$host.'/%'];
 $where=implode(' OR ',array_fill(0,count($patterns),'m.source_url LIKE ?'));
 $hasLinks=article_sites_ready($pdo);
 $excluded=$hasLinks?' AND NOT EXISTS(SELECT 1 FROM article_sites x WHERE x.article_id=a.id AND x.site_id=?)':'';
 $sql='SELECT DISTINCT a.id,a.title,m.source_url,m.snapshot_path FROM import_article_map m JOIN articles a ON a.id=m.article_id
 WHERE ('.$where.') AND a.site_id<>?'.$excluded.' ORDER BY a.id DESC LIMIT '.($limit*3);
 $q=$pdo->prepare($sql);
 $q->execute(array_merge($patterns,[$siteId],$hasLinks?[$siteId]:[]));
 $matches=[];
 foreach($q->fetchAll() as $item){
  $sourceHost=strtolower(preg_replace('/^www\./i','',(string)parse_url($item['source_url'],PHP_URL_HOST)));
  if($sourceHost!==$host)continue;
  $matches[(int)$item['id']]=['id'=>(int)$item['id'],'title'=>(string)$item['title'],'source_url'=>(string)$item['source_url'],'snapshot_path'=>(string)($item['snapshot_path']??'')];
  if(count($matches)>=$limit)break;
 }
 return array_values($matches);
}
function cms_site_link_import_candidates(PDO $pdo,array $site,int $actor):int{
 $siteId=(int)($site['id']??0);
 if(!article_sites_ready($pdo))throw new RuntimeException('Előbb futtasd az adatbázis-migrációt a webhelyek közötti cikkhozzárendeléshez.');
 $candidates=cms_site_import_candidates($pdo,$siteId,(string)($site['domain']??''));
 if(!$candidates)return 0;
 $pdo->beginTransaction();
 try{
  $ins=$pdo->prepare('INSERT IGNORE INTO article_sites(article_id,site_id) VALUES(?,?)');
  $count=0;
  foreach($candidates as $candidate){
   $articleId=(int)$candidate['id'];
   $ins->execute([$articleId,$siteId]);$count+=$ins->rowCount();
   $html='';
   $rel=(string)($candidate['snapshot_path']??'');
   // Only importer-owned snapshots beneath storage/import, never arbitrary paths.
   if(preg_match('~^storage/import/[0-9]+/[0-9]+/[a-f0-9]{64}\\.html$~D',$rel)){
    $path=dirname(__DIR__).'/'.$rel;
    if(is_file($path)&&filesize($path)<3500000)$html=(string)file_get_contents($path);
   }
   cms_import_native_terms($pdo,$siteId,$articleId,(string)$candidate['source_url'],$html);
  }
  $audit=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
  $audit->execute([$actor,'site.import_articles_linked','site',$siteId,json_encode(['count'=>$count,'source_host'=>$site['domain']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  $pdo->commit();
  return $count;
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
