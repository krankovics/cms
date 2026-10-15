<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-health.php';
function check_worker(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$path=sys_get_temp_dir().'/comesy-worker-test-'.bin2hex(random_bytes(8)).'.json';
try{
 check_worker(imp_worker_status_read($path)===null,'Absent worker heartbeat must not be healthy.');
 $now=time();
 imp_worker_status_write(['state'=>'running','started_at'=>$now-20,'processed'=>0],$path);
 $data=imp_worker_status_read($path);
 check_worker(is_array($data)&&$data['state']==='running','Heartbeat should be written atomically.');
 check_worker(imp_worker_status_view($data,1,1,$now)['state']==='running','Recent running heartbeat should be active.');
 imp_worker_status_write(['state'=>'ok','started_at'=>$now-40,'finished_at'=>$now-10,'processed'=>3,'duration_ms'=>30000],$path);
 $data=imp_worker_status_read($path);
 check_worker($data['processed']===3,'Processed count must persist.');
 check_worker(imp_worker_status_view($data,1,0,$now)['state']==='ready','Recent successful execution should be ready.');
 check_worker(imp_worker_status_view($data,1,0,$now+400)['state']==='stale','Stale heartbeat with queued work must alert.');
 check_worker(imp_worker_status_view($data,0,0,$now+400)['state']==='stale','Idle stale worker must not be misreported healthy.');
 check_worker(imp_worker_status_view(['state'=>'running','started_at'=>$now-400],1,0,$now)['state']==='stale','Crashed running process must become stale.');
 check_worker(imp_worker_status_view(['state'=>'error','started_at'=>$now-40,'finished_at'=>$now],0,0,$now)['state']==='error','Worker failures must be visible.');
 file_put_contents($path,'{invalid-json');
 check_worker(imp_worker_status_read($path)===null,'Corrupt status must fail closed.');
 echo "PASS: worker heartbeat, atomic persistence, stale/failed/running states.\n";
}finally{if(is_file($path))unlink($path);}
