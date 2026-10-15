<?php
declare(strict_types=1);
// Supervised CLI worker. Configure a server cron to run once per minute.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Forbidden');}
require __DIR__.'/app/bootstrap.php';
$limit=100;
foreach($argv as $arg)if(preg_match('/^--max=(\d+)$/',$arg,$m))$limit=max(1,min(1000,(int)$m[1]));
try{
 $pdo=db();$pdo->beginTransaction();
 $ids=$pdo->query("SELECT id,site_id FROM articles WHERE status='scheduled' AND published_at IS NOT NULL AND published_at<=UTC_TIMESTAMP() ORDER BY published_at,id LIMIT $limit FOR UPDATE SKIP LOCKED")->fetchAll();
 $q=$pdo->prepare("UPDATE articles SET status='published',updated_at=UTC_TIMESTAMP() WHERE id=? AND status='scheduled'");
 $audit=$pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(NULL,'article.publish_scheduled','article',?,?)");
 foreach($ids as $item){
  $q->execute([$item['id']]);
  if($q->rowCount())$audit->execute([$item['id'],json_encode(['site_id'=>(int)$item['site_id']])]);
 }
 $pdo->commit();
 echo 'comesy scheduled publication: '.count($ids)." due articles processed\n";
}catch(Throwable $e){
 if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
 error_log('scheduled publish worker: '.$e->getMessage());
 fwrite(STDERR,"Scheduled publishing failed\n");
 exit(1);
}