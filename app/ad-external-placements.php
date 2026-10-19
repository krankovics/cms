<?php
declare(strict_types=1);
/**
 * AdOcean inventory is a source of typed placement references, never executable
 * markup inside a template. Presentation and delivery stay separate.
 */
function cms_external_layout_reference(array $block):array {
 $mode=(string)($block['external_device_mode']??'all');
 if(!in_array($mode,['all','desktop','mobile','responsive'],true))
  throw new RuntimeException('Ismeretlen AdOcean eszközmegjelenítés.');
 $keys=['external_import_id','external_folder_id','external_master_id','external_leaf_id',
        'external_mobile_master_id','external_mobile_leaf_id'];
 $ref=[];
 foreach($keys as $key){
  $raw=$block[$key]??0;
  if(!is_int($raw)&&!(is_string($raw)&&preg_match('/^(?:0|[1-9][0-9]{0,17})$/D',$raw)))
   throw new RuntimeException('Érvénytelen AdOcean azonosító: '.$key);
  $number=(int)$raw;
  if($number<0||(string)$number!==(string)$raw)throw new RuntimeException('Túl nagy AdOcean azonosító: '.$key);
  $ref[$key]=$number;
 }
 if($ref['external_import_id']<=0||$ref['external_folder_id']<=0||$ref['external_leaf_id']<=0)
  throw new RuntimeException('Az AdOcean blokkhoz forráswebhely és LEAF pozíció szükséges.');
 if($mode==='responsive'&&$ref['external_mobile_leaf_id']<=0)
  throw new RuntimeException('A desktop + mobil hirdetési helyhez mindkét LEAF pozíciót add meg.');
 $ref['external_device_mode']=$mode;
 if($mode!=='responsive'){$ref['external_mobile_master_id']=0;$ref['external_mobile_leaf_id']=0;}
 return $ref;
}
/** Only inventory connected to THIS comesy site can appear in the editor. */
function cms_external_editor_options(PDO $pdo,int $siteId):array {
 $query=$pdo->prepare("SELECT m.import_id,m.folder_source_id,m.folder_name,m.enabled,
      n.source_id,n.name,n.node_type,n.is_active,n.is_slave,n.master_code_id
      FROM ad_external_sites m
      JOIN ad_external_nodes n ON n.import_id=m.import_id AND n.folder_source_id=m.folder_source_id
      WHERE m.site_id=? AND n.node_type IN ('MASTER','LEAF')
      ORDER BY m.import_id DESC,m.folder_name,n.node_type,n.name LIMIT 10000");
 $query->execute([$siteId]);
 $groups=[];
 foreach($query->fetchAll() as $row){
  $key=(int)$row['import_id'].':'.(int)$row['folder_source_id'];
  if(!isset($groups[$key]))$groups[$key]=[
   'import_id'=>(int)$row['import_id'],
   'folder_id'=>(int)$row['folder_source_id'],
   'name'=>(string)$row['folder_name'],
   'enabled'=>(bool)$row['enabled'],
   'masters'=>[],'leaves'=>[]
  ];
  $node=['id'=>(int)$row['source_id'],'name'=>(string)$row['name'],
    'active'=>(bool)$row['is_active'],'master_code'=>(string)$row['master_code_id']];
  if($row['node_type']==='MASTER')$groups[$key]['masters'][]=$node;
  else {$node['slave']=(bool)$row['is_slave'];$groups[$key]['leaves'][]=$node;}
 }
 return array_values($groups);
}
/** Verify site ownership and MASTER/LEAF integrity at save time and at render time. */
function cms_external_resolve_reference(PDO $pdo,int $siteId,array $block,bool $delivery=false):?array {
 $ref=cms_external_layout_reference($block);
 $stmt=$pdo->prepare("SELECT m.enabled mapped_enabled,l.is_active leaf_active,l.is_slave,
         l.master_code_id leaf_master_code,l.leaf_code_id,l.name leaf_name,
         a.source_id master_id,a.is_active master_active,a.master_code_id actual_master_code,a.name master_name
         FROM ad_external_sites m
         JOIN ad_external_nodes l ON l.import_id=m.import_id AND l.folder_source_id=m.folder_source_id
         AND l.source_id=? AND l.node_type='LEAF'
         LEFT JOIN ad_external_nodes a ON a.import_id=m.import_id AND a.folder_source_id=m.folder_source_id
         AND a.source_id=? AND a.node_type='MASTER'
         WHERE m.site_id=? AND m.import_id=? AND m.folder_source_id=? LIMIT 1");
 $stmt->execute([$ref['external_leaf_id'],$ref['external_master_id'],$siteId,$ref['external_import_id'],$ref['external_folder_id']]);
 $row=$stmt->fetch();
 if(!$row)return null;
 $slave=(bool)$row['is_slave'];
 if($slave){
  if($ref['external_master_id']<1||!(int)$row['master_id']||
     !hash_equals((string)$row['actual_master_code'],(string)$row['leaf_master_code']))return null;
 }elseif($ref['external_master_id']!==0)return null;
 if($delivery&&(!(bool)$row['mapped_enabled']||!(bool)$row['leaf_active']||($slave&&!(bool)$row['master_active'])))return null;
 return [
  'import_id'=>$ref['external_import_id'],'folder_id'=>$ref['external_folder_id'],
  'master_node_id'=>$ref['external_master_id'],'leaf_node_id'=>$ref['external_leaf_id'],
  'code_id'=>(string)$row['leaf_code_id'],
  'master_name'=>(string)($row['master_name']??''),
  'leaf_name'=>(string)($row['leaf_name']??'')
 ];
}
/**
 * "all" preserves old published layouts; explicit "desktop" and "mobile"
 * are mutually exclusive, and "responsive" is a single editorial slot with
 * two independently validated provider references.
 */
function cms_external_block_variants(PDO $pdo,int $siteId,array $block,bool $delivery=false):?array {
 $ref=cms_external_layout_reference($block);
 $mode=$ref['external_device_mode'];
 $main=cms_external_resolve_reference($pdo,$siteId,$ref,$delivery);
 if(!$main)return null;
 if($mode!=='responsive')return [['device'=>$mode,'position'=>$main]];
 $mobileBlock=$ref;
 $mobileBlock['external_master_id']=$ref['external_mobile_master_id'];
 $mobileBlock['external_leaf_id']=$ref['external_mobile_leaf_id'];
 $mobile=cms_external_resolve_reference($pdo,$siteId,$mobileBlock,$delivery);
 if(!$mobile)return null;
 return [['device'=>'desktop','position'=>$main],['device'=>'mobile','position'=>$mobile]];
}
function cms_external_validate_template(PDO $pdo,int $siteId,string $json):void {
 $blocks=json_decode($json,true,64,JSON_THROW_ON_ERROR);
 foreach($blocks as $block){
  if(!is_array($block)||($block['type']??'')!=='adocean')continue;
  if(cms_external_block_variants($pdo,$siteId,$block)===null)
   throw new RuntimeException('Az AdOcean pozíció nem tartozik ehhez a webhelyhez, vagy a MASTER/LEAF kapcsolat hibás.');
 }
}
/**
 * Every published variant is rechecked against the owning site and active
 * mapping. A stale responsive pair fails closed rather than serving one half.
 * Only the matching device is requested by the consent-gated browser adapter.
 */
function cms_external_page_slots(PDO $pdo,int $siteId,array $layout,bool $enabled):array {
 $empty=['slots'=>[],'groups'=>[],'devices'=>[],'variants'=>[]];
 if(!$enabled)return $empty;
 $slots=[];$groups=[];$devices=[];$variants=[];
 foreach($layout as $index=>$block){
  if(!is_array($block)||($block['type']??'')!=='adocean')continue;
  try{$selected=cms_external_block_variants($pdo,$siteId,$block,true);}
  catch(Throwable $e){error_log('publisher AdOcean invalid block: '.$e->getMessage());continue;}
  if(!$selected)continue;
  $slots[$index]='comesy-ao-slot-'.$index;
  $devices[$index]=$selected[0]['device'];
  $variants[$index]=[];
  foreach($selected as $variant){
   $position=$variant['position'];$device=$variant['device'];
   $sourceName=$position['master_name']?:$position['leaf_name'];
   $inferred=preg_match('/(?:^|[_\s-])desktop(?:[_\s-]|$)/i',$sourceName)?'desktop':(
    preg_match('/(?:^|[_\s-])(?:mobil|mobile)(?:[_\s-]|$)/i',$sourceName)?'mobile':'auto');
   $domId=$slots[$index].($device==='all'?'':'-'.$device);
   $variants[$index][]=array_merge($position,['device'=>$device,'dom_id'=>$domId,'preview_device'=>$inferred]);
   $key=$position['import_id'].':'.$position['folder_id'].':'.$position['master_node_id'];
   if(!isset($groups[$key]))$groups[$key]=[
    'import_id'=>$position['import_id'],
    'folder_node'=>$position['folder_id'],
    'master_node'=>$position['master_node_id'],'positions'=>[]
   ];
   $groups[$key]['positions'][]=['nodeId'=>$position['leaf_node_id'],'element'=>'#'.$domId,'device'=>$device];
  }
 }
 return ['slots'=>$slots,'groups'=>array_values($groups),'devices'=>$devices,'variants'=>$variants];
}
