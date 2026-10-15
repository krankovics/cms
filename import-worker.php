<?php
declare(strict_types=1);
// Start this worker from server cron or supervisor only. HTTP access is forbidden.
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Forbidden');}
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/import-worker.php';
require __DIR__.'/app/import-health.php';
if(in_array('--health',$argv,true)){
 $health=imp_worker_status_view(imp_worker_status_read(),0,0);
 echo $health['label'].': '.$health['detail']."\n";
 exit(in_array($health['state'],['ready','running'],true)?0:2);
}
function imp_heartbeat_safe(array $info):void{
 try{imp_worker_status_write($info);}
 catch(Throwable $e){error_log('comesy worker heartbeat: '.$e->getMessage());}
}
$max=5;
foreach($argv as $argument)if(preg_match('/^--max=(\d+)$/',$argument,$m))$max=max(1,min(100,(int)$m[1]));
$count=0;$started=microtime(true);$startedAt=time();
try{
 $lockPath=dirname(__FILE__).'/storage/import/worker.lock';
 $lockDir=dirname($lockPath);
 if(!is_dir($lockDir)&&!mkdir($lockDir,0750,true)&&!is_dir($lockDir))throw new RuntimeException('Az import worker munkakönyvtára nem írható.');
 $lock=fopen($lockPath,'c');
 if(!$lock)throw new RuntimeException('Az import worker zárolása nem nyitható meg.');
 if(!flock($lock,LOCK_EX|LOCK_NB)){
  fclose($lock);
  echo "comesy import worker: another process is already active\n";
  exit(0);
 }
 imp_heartbeat_safe(['state'=>'running','started_at'=>$startedAt,'processed'=>0]);
 $pdo=db();
 while($count<$max&&microtime(true)-$started<120){
  if(!imp_work_once($pdo))break;
  $count++;
  if($count<$max)sleep(1);
 }
 imp_heartbeat_safe(['state'=>'ok','started_at'=>$startedAt,'finished_at'=>time(),'processed'=>$count,'duration_ms'=>(int)round((microtime(true)-$started)*1000)]);
 echo "comesy import worker: processed $count tasks\n";
 flock($lock,LOCK_UN);fclose($lock);
}catch(Throwable $e){
 error_log('comesy import worker: '.$e->getMessage());
 imp_heartbeat_safe(['state'=>'error','started_at'=>$startedAt,'finished_at'=>time(),'processed'=>$count,'duration_ms'=>(int)round((microtime(true)-$started)*1000)]);
 fwrite(STDERR,"Import worker error: ".$e->getMessage()."\n");
 exit(1);
}
