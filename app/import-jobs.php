<?php
declare(strict_types=1);
require_once __DIR__.'/import-engine.php';
require_once __DIR__.'/site-access.php';
function imp_allowed_sites(PDO $pdo,array $user):array{
 if(!in_array($user['role'],['platform_admin','site_admin','editor'],true))return [];
 return allowed_primary_sites($pdo,$user);
}
function imp_mutate(PDO $pdo,array $user,int $site,array $post):int{
 if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
 if(!in_array($site,imp_allowed_sites($pdo,$user),true))throw new RuntimeException('Nincs importálási jogosultság ehhez a webhelyhez.');
 $action=(string)($post['action']??'create');
 if(!in_array($action,['create','pause','resume','retry'],true))throw new RuntimeException('Ismeretlen importművelet.');
 $pdo->beginTransaction();
 try{
  if($action==='create'){
   if(empty($post['confirm_rights']))throw new RuntimeException('Az importhoz igazolni kell a forrástartalom felhasználási jogosultságát.');
   $mode=(string)($post['mode']??'url');if(!in_array($mode,['url','rss','sitemap','site','demo'],true))throw new RuntimeException('Érvénytelen importmód.');
   $url=imp_validate_url((string)($post['source_url']??''));
   $max=$mode==='url'?1:($mode==='demo'?10:max(1,min(1000000,(int)($post['max_items']??100))));
   $q=$pdo->prepare('INSERT INTO import_jobs(site_id,created_by,mode,source_url,max_items) VALUES(?,?,?,?,?)');
   $q->execute([$site,$user['id'],$mode,$url,$max]);$id=(int)$pdo->lastInsertId();
   $q=$pdo->prepare('INSERT INTO import_items(job_id,site_id,source_url,source_url_hash,task_type) VALUES(?,?,?,?,?)');
   $q->execute([$id,$site,$url,hash('sha256',$url),$mode==='url'?'article':'discover']);
  }else{
   $id=max(0,(int)($post['job_id']??0));
   $q=$pdo->prepare('SELECT id,status FROM import_jobs WHERE id=? AND site_id=? FOR UPDATE');
   $q->execute([$id,$site]);$job=$q->fetch();if(!$job)throw new RuntimeException('Az importfeladat nem található.');
   if($action==='pause'){
    if(!in_array($job['status'],['queued','running'],true))throw new RuntimeException('Csak futó import szüneteltethető.');
    $pdo->prepare("UPDATE import_jobs SET status='paused' WHERE id=?")->execute([$id]);
   }elseif($action==='resume'){
    if($job['status']!=='paused')throw new RuntimeException('Ez a feladat nincs szüneteltetve.');
    $pdo->prepare("UPDATE import_jobs SET status='queued' WHERE id=?")->execute([$id]);
   }else{
    if(!in_array($job['status'],['finished','failed','paused'],true))throw new RuntimeException('Az aktív feladat még nem próbálható újra.');
    $pdo->prepare("UPDATE import_items SET status='pending',attempts=0,available_at=NOW(),last_error=NULL WHERE job_id=? AND status='failed'")->execute([$id]);
    $pdo->prepare("UPDATE import_jobs SET status='queued',failed=0,last_error=NULL WHERE id=?")->execute([$id]);
   }
  }
  $q=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
  $q->execute([$user['id'],'import.'.$action,'import_job',$id,json_encode(['site_id'=>$site],JSON_THROW_ON_ERROR)]);
  $pdo->commit();return $id;
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
