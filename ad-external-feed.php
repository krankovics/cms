<?php
declare(strict_types=1);
// Public, read-only inventory feed. Does not deliver advertising or execute stored HTML.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');
function external_fail(int $status,string $message):never {
 http_response_code($status);echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);exit;
}
function external_host(string $value):string {
 $value=strtolower(trim($value));
 return preg_replace('/^www\./','',parse_url(str_contains($value,'://')?$value:'https://'.$value,PHP_URL_HOST)?:'');
}
function external_server(string $code):string {
 if(preg_match('~\bserver\s*:\s*["\x27]([a-zA-Z0-9.-]+)["\x27]~',$code,$m) &&
    preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.adocean\.pl$/D',strtolower($m[1])))
    return strtolower($m[1]);
 return '';
}
if($_SERVER['REQUEST_METHOD']!=='GET')external_fail(405,'Only GET is supported.');
$siteId=(int)($_GET['site_id']??0);
$masterId=(int)($_GET['master_node']??0);
$folderId=(int)($_GET['folder_node']??0);
$raw=(string)($_GET['nodes']??'');
if($siteId<1||$masterId<0||$folderId<0||strlen($raw)>1100||!preg_match('/^[0-9]+(?:,[0-9]+){0,49}$/D',$raw))external_fail(400,'Érvénytelen azonosítók; legfeljebb 50 pozíció választható.');
$nodeIds=array_map('intval',explode(',',$raw));
if(in_array(0,$nodeIds,true)||count(array_unique($nodeIds))!==count($nodeIds))external_fail(400,'Hibás vagy ismétlődő pozíció.');
try{
 $cfg=require __DIR__.'/app/config.php';
 $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
 $q=$db->prepare('SELECT domain FROM sites WHERE id=?');$q->execute([$siteId]);$domain=$q->fetchColumn();
 if(!$domain)external_fail(404,'Ismeretlen webhely.');
 $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
 if($origin!==''){
  $scheme=parse_url($origin,PHP_URL_SCHEME);
  $host=external_host($origin);
  if($scheme!=='https'||$host===''||$host!==external_host((string)$domain))external_fail(403,'A célwebhely Origin címe nem egyezik.');
  header('Access-Control-Allow-Origin: '.$origin);
 }
 if($masterId){
  $q=$db->prepare("SELECT n.import_id,n.folder_source_id,n.master_code_id,n.source_code
    FROM ad_external_nodes n JOIN ad_external_sites m ON m.import_id=n.import_id AND m.folder_source_id=n.folder_source_id
    WHERE m.site_id=? AND m.enabled=1 AND n.source_id=? AND n.node_type='MASTER' AND n.is_active=1 LIMIT 1");
  $q->execute([$siteId,$masterId]);$master=$q->fetch();
  if(!$master)external_fail(404,'Inaktív vagy hiányzó MASTER.');
  $importId=(int)$master['import_id'];$folder=(int)$master['folder_source_id'];
  $masterCode=(string)$master['master_code_id'];$server=external_server((string)$master['source_code']);
 }else{
  if(!$folderId)external_fail(400,'Önálló pozícióhoz folder_node szükséges.');
  $q=$db->prepare('SELECT import_id FROM ad_external_sites WHERE site_id=? AND folder_source_id=? AND enabled=1 ORDER BY import_id DESC LIMIT 1');
  $q->execute([$siteId,$folderId]);$importId=(int)$q->fetchColumn();
  if(!$importId)external_fail(404,'Inaktív forráswebhely.');
  $folder=$folderId;$masterCode='';$server='';
 }
 $marks=implode(',',array_fill(0,count($nodeIds),'?'));
 $q=$db->prepare("SELECT source_id,leaf_code_id,master_code_id,is_slave,source_code
   FROM ad_external_nodes WHERE import_id=? AND folder_source_id=? AND node_type='LEAF' AND is_active=1 AND source_id IN ($marks)");
 $q->execute([$importId,$folder,...$nodeIds]);$found=[];
 foreach($q->fetchAll() as $r)$found[(int)$r['source_id']]=$r;
 if(count($found)!==count($nodeIds))external_fail(404,'Egy vagy több pozíció nem található.');
 $positions=[];
 foreach($nodeIds as $id){
  $r=$found[$id];$isSlave=(bool)$r['is_slave'];
  if($isSlave&&(!$masterCode||!hash_equals($masterCode,(string)$r['master_code_id'])))external_fail(400,'A SLAVE eltérő MASTER-hez tartozik.');
  if(!$isSlave){
   $leafServer=external_server((string)$r['source_code']);
   if(!$leafServer)external_fail(400,'A pozíció szervere nem ellenőrizhető.');
   if($server!==''&&$server!==$leafServer)external_fail(400,'A pozíciók eltérő kiszolgálókhoz tartoznak.');
   $server=$leafServer;
  }
  $code=(string)$r['leaf_code_id'];
  if(!preg_match('/^[A-Za-z0-9_.-]{1,190}$/D',$code))external_fail(500,'Érvénytelen forrásazonosító.');
  $positions[]=['node_id'=>$id,'code_id'=>$code,'kind'=>$isSlave?'slave':'placement'];
 }
 if(!$server)external_fail(500,'AdOcean szerver nincs megadva.');
 if($masterCode!==''&&!preg_match('/^[A-Za-z0-9_.-]{1,190}$/D',$masterCode))external_fail(500,'Érvénytelen MASTER-kód.');
 echo json_encode(['provider'=>'adocean','site_id'=>$siteId,'server'=>$server,'master'=>$masterCode?:null,
  'positions'=>$positions],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(PDOException $e){error_log('external feed DB: '.$e->getMessage());external_fail(503,'Kiszolgálási adat nem elérhető.');}
catch(Throwable $e){error_log('external feed: '.$e->getMessage());external_fail(503,'Kiszolgálási hiba.');}
