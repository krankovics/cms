<?php
declare(strict_types=1);
/**
 * Restricted, streaming XLSX reader for AdOcean "nodes" export.
 * No macros, formulas, remote URLs, or arbitrary XML entities are processed.
 */
function external_xlsx_rows(string $filename):array {
 if(!class_exists(ZipArchive::class)||!class_exists(XMLReader::class))throw new RuntimeException('ZipArchive és XMLReader PHP-kiegészítő szükséges.');
 if(!is_file($filename)||filesize($filename)>15*1024*1024)throw new RuntimeException('A fájl hiányzik vagy túl nagy (max. 15 MB).');
 $zip=new ZipArchive();
 if($zip->open($filename)!==true)throw new RuntimeException('Nem olvasható XLSX.');
 try {
  $sheetName='xl/worksheets/sheet1.xml';
  $info=$zip->statName($sheetName);
  if(!$info||($info['size']??0)>30*1024*1024)throw new RuntimeException('A munkalap nem található vagy túl nagy.');
  $shared=[];
  if(($si=$zip->statName('xl/sharedStrings.xml'))!==false){
   if(($si['size']??0)>15*1024*1024)throw new RuntimeException('Túl nagy XLSX szótár.');
   $xml=$zip->getFromName('xl/sharedStrings.xml');
   if(!is_string($xml))throw new RuntimeException('Nem olvasható szótár.');
   $r=new XMLReader();$r->XML($xml,null,LIBXML_NONET|LIBXML_COMPACT);
   while($r->read()){
    if($r->nodeType===XMLReader::ELEMENT&&$r->localName==='si'){
     $fragment=$r->readOuterXML();
     $element=simplexml_load_string($fragment,'SimpleXMLElement',LIBXML_NONET);
     if($element===false)throw new RuntimeException('Hibás szótár.');
     $text='';
     foreach($element->xpath('.//*[local-name()="t"]')?:[] as $t)$text.=(string)$t;
     $shared[]=$text;
    }
   }
   $r->close();
  }
  $xml=$zip->getFromName($sheetName);
  if(!is_string($xml))throw new RuntimeException('Nem olvasható munkalap.');
  $r=new XMLReader();$r->XML($xml,null,LIBXML_NONET|LIBXML_COMPACT);
  $rows=[];$header=null;
  while($r->read()){
   if($r->nodeType!==XMLReader::ELEMENT||$r->localName!=='row')continue;
   $fragment=$r->readOuterXML();
   $el=simplexml_load_string($fragment,'SimpleXMLElement',LIBXML_NONET);
   if($el===false)throw new RuntimeException('Hibás XLSX sor.');
   $values=[];
   foreach($el->xpath('./*[local-name()="c"]')?:[] as $cell){
    $addr=(string)$cell['r'];if(!preg_match('/^([A-Z]+)[0-9]+$/D',$addr,$m))continue;
    $col=0;foreach(str_split($m[1]) as $ch)$col=$col*26+ord($ch)-64;
    if($col<1||$col>13)continue;
    $type=(string)$cell['t'];
    $v=$cell->xpath('./*[local-name()="v"]');
    $raw=isset($v[0])?(string)$v[0]:'';
    if($type==='s')$raw=$shared[(int)$raw]??'';
    elseif($type==='inlineStr'){$t=$cell->xpath('.//*[local-name()="t"]');$raw=implode('',array_map('strval',$t?:[]));}
    // Other types are either literals or untrusted formula caches, not evaluated.
    $values[$col-1]=$raw;
   }
   $values=array_replace(array_fill(0,13,''),$values);
   if($header===null){
    $header=array_map(fn($v)=>strtolower(trim((string)$v)),$values);
    $needed=['node id','parent id','node name','node type','node active','master/slave','node slave','master name','node depth','master code id','leaf code id','node path','code'];
    if($header!==$needed)throw new RuntimeException('Ismeretlen AdOcean-oszlopstruktúra.');
    continue;
   }
   if(implode('',$values)==='')continue;
   if(count($rows)>=30000)throw new RuntimeException('Legfeljebb 30 000 sor importálható.');
   $rows[]=array_combine($header,$values);
  }
  $r->close();
  return $rows;
 }finally{$zip->close();}
}
function external_validate_nodes(array $rows):array {
 $byId=[];$folders=[];$counts=['FOLDER'=>0,'MASTER'=>0,'LEAF'=>0];
 foreach($rows as $i=>$row){
  $id=(string)$row['node id'];$parent=(string)$row['parent id'];$type=(string)$row['node type'];
  if(!preg_match('/^[1-9][0-9]{0,17}$/D',$id)||isset($byId[$id])||
     !preg_match('/^[0-9]{1,18}$/D',$parent)||!isset($counts[$type]))throw new RuntimeException('Hibás vagy duplikált node azonosító a(z) '.($i+2).'. sorban.');
  if(mb_strlen((string)$row['node name'])>255||strlen((string)$row['code'])>8192)throw new RuntimeException('Túl hosszú érték a(z) '.($i+2).'. sorban.');
  foreach(['master code id','leaf code id'] as $field){
   $value=(string)$row[$field];
   if($value!==''&&!preg_match('/^[A-Za-z0-9_.-]{1,190}$/D',$value))throw new RuntimeException('Érvénytelen AdOcean azonosító a(z) '.($i+2).'. sorban.');
  }
  if($type==='MASTER'&&$row['master code id']==='')throw new RuntimeException('MASTER kód nélkül: '.$id);
  if($type==='LEAF'&&$row['leaf code id']==='')throw new RuntimeException('LEAF kód nélkül: '.$id);
  $byId[$id]=$row;$counts[$type]++;
 }
 $folderFor=[];$resolving=[];
 $resolve=function(string $id)use(&$resolve,&$folderFor,&$resolving,$byId):int{
  if(array_key_exists($id,$folderFor))return $folderFor[$id];
  if(isset($resolving[$id]))throw new RuntimeException('Körkörös node-hierarchia: '.$id);
  $resolving[$id]=true;$row=$byId[$id];$parent=(string)$row['parent id'];
  if((string)$row['node depth']==='2'&&$row['node type']==='FOLDER')$folder=(int)$id;
  elseif(!isset($byId[$parent]))$folder=0;
  else $folder=$resolve($parent);
  unset($resolving[$id]);
  return $folderFor[$id]=$folder;
 };
 $masters=[];
 foreach($byId as $id=>$row)if($row['node type']==='MASTER')$masters[(string)$row['master code id']]=(int)$resolve($id);
 foreach($byId as $id=>$row){
  $folder=$resolve($id);
  if($folder===0&&$row['node depth']!=='1')throw new RuntimeException('Árva csomópont: '.$id);
  if($row['node type']==='FOLDER'&&$row['node depth']==='2')$folders[$id]=$row['node name'];
  if($row['node type']==='LEAF'&&$row['node slave']==='Y'){
   $master=(string)$row['master code id'];
   if(!isset($masters[$master])||$masters[$master]!==$folder)throw new RuntimeException('Hiányzó vagy idegen MASTER a SLAVE mögött: '.$id);
  }
 }
 return ['rows'=>$byId,'folders'=>$folders,'folder_for'=>$folderFor,'counts'=>$counts];
}
/**
 * Same SHA is idempotent: retain mapping/activation, do not rewrite.
 * New uploads start entirely unmapped and disabled. No delivery code is run.
 */
function external_import(PDO $pdo,array $validated,string $filename,string $sha,int $userId):int {
 $q=$pdo->prepare('SELECT id FROM ad_external_imports WHERE sha256=?');
 $q->execute([$sha]);if($existing=$q->fetchColumn())return (int)$existing;
 $rows=$validated['rows'];$counts=$validated['counts'];
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('INSERT INTO ad_external_imports(filename,sha256,row_count,master_count,leaf_count,created_by) VALUES(?,?,?,?,?,?)');
  $q->execute([mb_substr(basename($filename),0,190),$sha,count($rows),$counts['MASTER'],$counts['LEAF'],$userId]);
  $importId=(int)$pdo->lastInsertId();
  $q=$pdo->prepare('INSERT INTO ad_external_sites(import_id,folder_source_id,folder_name) VALUES(?,?,?)');
  foreach($validated['folders'] as $id=>$name)$q->execute([$importId,(int)$id,$name]);
  $q=$pdo->prepare('INSERT INTO ad_external_nodes(import_id,source_id,parent_source_id,folder_source_id,name,node_type,source_path,is_active,is_slave,master_code_id,leaf_code_id,source_code) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
  foreach($rows as $id=>$r){
   $q->execute([$importId,(int)$id,(int)$r['parent id'],(int)$validated['folder_for'][$id],
    $r['node name'],$r['node type'],$r['node path'],$r['node active']==='Y'?1:0,$r['node slave']==='Y'?1:0,
    $r['master code id'],$r['leaf code id'],$r['code']]);
  }
  $pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)')
      ->execute([$userId,'ad.external_import','ad_external_imports',$importId,json_encode(['rows'=>count($rows),'sha256'=>$sha],JSON_THROW_ON_ERROR)]);
  $pdo->commit();return $importId;
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
