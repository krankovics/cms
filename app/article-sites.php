<?php
declare(strict_types=1);

/**
 * A migration must create article_sites before multi-site publishing is enabled.
 * Legacy installations continue to work with the primary articles.site_id.
 */
function article_sites_ready(PDO $pdo):bool {
 static $cache=[];
 $key=spl_object_id($pdo);
 if(array_key_exists($key,$cache))return $cache[$key];
 try{$pdo->query('SELECT article_id FROM article_sites LIMIT 0');return $cache[$key]=true;}
 catch(PDOException $e){return $cache[$key]=false;}
}
function article_site_ids(PDO $pdo,array $article):array {
 $primary=(int)$article['site_id'];
 if(!article_sites_ready($pdo))return [$primary];
 $q=$pdo->prepare('SELECT site_id FROM article_sites WHERE article_id=? ORDER BY site_id');
 $q->execute([(int)$article['id']]);
 $ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
 if(!in_array($primary,$ids,true))$ids[]=$primary;
 return array_values(array_unique($ids));
}
function article_site_save(PDO $pdo,int $articleId,int $primary,array $ids):void {
 $ids=array_values(array_unique(array_map('intval',$ids)));
 if(!$ids||!in_array($primary,$ids,true))throw new RuntimeException('A cikk elsődleges webhelyének kiválasztva kell maradnia.');
 if(!article_sites_ready($pdo)){
  if(count($ids)>1)throw new RuntimeException('Több webhely használatához előbb futtasd a Beállítások / Adatbázis-migrációt.');
  return;
 }
 $q=$pdo->prepare('DELETE FROM article_sites WHERE article_id=?');$q->execute([$articleId]);
 $q=$pdo->prepare('INSERT INTO article_sites(article_id,site_id) VALUES(?,?)');
 foreach($ids as $sid)$q->execute([$articleId,$sid]);
}
function article_site_visible_sql(PDO $pdo,string $alias='a'):string {
 if(article_sites_ready($pdo)){
  return '(EXISTS (SELECT 1 FROM article_sites asit WHERE asit.article_id='.$alias.'.id AND asit.site_id IN (%s)) OR '.$alias.'.site_id IN (%s))';
 }
 return $alias.'.site_id IN (%s)';
}
