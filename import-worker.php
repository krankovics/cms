<?php
declare(strict_types=1);
// Invoke with a system cron or supervisor, never via HTTP.
// Example: php /absolute/path/to/import-worker.php --max=5
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Forbidden');}
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/import-worker.php';
$max=5;
foreach($argv as $argument)if(preg_match('/^--max=(\d+)$/',$argument,$m))$max=max(1,min(100,(int)$m[1]));
$count=0;$start=microtime(true);
try{
 $pdo=db();
 while($count<$max&&microtime(true)-$start<120){
  if(!imp_work_once($pdo))break;
  $count++;
  if($count<$max)sleep(1);
 }
 echo "comesy import worker: processed $count tasks\n";
}catch(Throwable $e){
 error_log('comesy import worker: '.$e->getMessage());
 fwrite(STDERR,"Import worker error: ".$e->getMessage()."\n");
 exit(1);
}
