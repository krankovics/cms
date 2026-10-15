<?php
declare(strict_types=1);
/**
 * Filesystem-only worker heartbeat. Keep this outside the public CMS API:
 * the protected storage/import directory is excluded from direct web access.
 */
function imp_worker_status_path():string{
 return dirname(__DIR__).'/storage/import/worker-status.json';
}
function imp_worker_status_write(array $record,?string $path=null):void{
 $path=$path??imp_worker_status_path();
 $directory=dirname($path);
 if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))throw new RuntimeException('Importállapot-könyvtár nem hozható létre.');
 $record=[
  'state'=>in_array($record['state']??'', ['running','ok','error'],true)?$record['state']:'error',
  'started_at'=>max(0,(int)($record['started_at']??0)),
  'finished_at'=>isset($record['finished_at'])?max(0,(int)$record['finished_at']):null,
  'processed'=>max(0,(int)($record['processed']??0)),
  'duration_ms'=>max(0,(int)($record['duration_ms']??0)),
 ];
 $json=json_encode($record,JSON_THROW_ON_ERROR);
 $temp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
 if(file_put_contents($temp,$json,LOCK_EX)===false)throw new RuntimeException('Importállapot nem írható.');
 chmod($temp,0640);
 if(!rename($temp,$path)){@unlink($temp);throw new RuntimeException('Importállapot nem menthető.');}
}
function imp_worker_status_read(?string $path=null):?array{
 $path=$path??imp_worker_status_path();
 if(!is_file($path)||filesize($path)>4096)return null;
 $raw=@file_get_contents($path);
 if(!is_string($raw)||$raw==='')return null;
 $data=json_decode($raw,true);
 if(!is_array($data)||!in_array($data['state']??'', ['running','ok','error'],true))return null;
 return $data;
}
/** Interpret a filesystem heartbeat and the actionable per-site queue size. */
function imp_worker_status_view(?array $status,int $waiting,int $processing,?int $now=null):array{
 $now=$now??time();
 if(!$status)return ['state'=>'unknown','label'=>'Nincs ellenőrzött workerjel','detail'=>'A cron/supervisor futása nem igazolt.'];
 $last=(int)($status['finished_at']??$status['started_at']??0);
 $age=$last>0?max(0,$now-$last):PHP_INT_MAX;
 if(($status['state']??'')==='error')return ['state'=>'error','label'=>'Workerhiba észlelve','detail'=>'A szervernapló ellenőrzése szükséges.'];
 if(($status['state']??'')==='running' && $age<=180)return ['state'=>'running','label'=>'Worker dolgozik','detail'=>'Feldolgozási kör folyamatban.'];
 if($age>300)return ['state'=>'stale','label'=>$waiting+$processing>0?'Elakadt vagy nem fut a worker':'A worker futása nem igazolt','detail'=>'Nem érkezett friss workerjel öt percen belül.'];
 return ['state'=>'ready','label'=>'Worker nemrég sikeresen futott','detail'=>'A legutóbbi feldolgozási kör sikeres volt.'];
}
