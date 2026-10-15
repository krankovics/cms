<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-engine.php';
foreach([
 'https://magyarnemzet.hu/kulfold/2026/10/tirana-langol-a-diaktuntetesek-utan',
 'https://magyarnemzet.hu/sport/2026/10/toth-balazs-blackburn-rovers-bristol-city-kapus-bayern',
 'https://magyarnemzet.hu/kulfold/2026/10/gyerekeket-nyomott-agyon-a-leszakadt-tetozet'
] as $url){
 try{
  $f=imp_fetch($url);
  $doc=new DOMDocument('1.0','UTF-8');$before=libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="utf-8"?>'.$f['body'],LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
  libxml_clear_errors();libxml_use_internal_errors($before);
  $xp=new DOMXPath($doc);
  $title=trim((string)$xp->evaluate('string(//meta[@property="og:title"]/@content)'));
  $data=imp_rich_ng_data($xp,$title,$url);
  echo "SOURCE ".parse_url($url,PHP_URL_PATH)." body_count=".count($data['body']??[])."\n";
  foreach(($data['body']??[]) as $i=>$c){
   echo " COMP[$i] ".json_encode(['type'=>$c['type']??null,'keys'=>array_keys($c),'sub_count'=>count((array)($c['subComponents']??[]))])."\n";
   foreach(($c['details']??[]) as $d){
    $v=$d['value']??null;
    echo "  detail ".json_encode(['type'=>$d['type']??null,'key'=>$d['key']??null,'value_type'=>gettype($v),'length'=>is_string($v)?strlen($v):0,'tags'=>is_string($v)?array_unique(array_map('strtolower',array_slice(array_map(static fn($q)=>$q[1],array_filter((array)($m[0]??[]))),0,1))):[]])."\n";
   }
   foreach(array_slice((array)($c['subComponents']??[]),0,5) as $sub){
    echo "  SUB ".json_encode(['type'=>$sub['type']??null,'keys'=>array_keys((array)$sub),'details'=>array_map(static fn($d)=>['key'=>$d['key']??null,'type'=>$d['type']??null,'value_type'=>gettype($d['value']??null)],(array)($sub['details']??[]))])."\n";
   }
  }
  foreach(['thumbnail','metaThumbnail','secondaryThumbnail','thumbnailInfo'] as $key){
   $v=$data[$key]??null;
   echo "META $key ".json_encode(is_array($v)?['keys'=>array_keys($v)]:gettype($v))."\n";
  }
  $out=imp_extract($f['body'],$url);
  $types=[];foreach($out['richtext']['ops'] as $op){$x=$op['insert']??null;if(is_array($x)){$key=array_key_first($x);$types[$key]=($types[$key]??0)+1;}}
  echo 'RICH '.json_encode(['type_counts'=>$types,'featured'=>$out['featured_image']!=='','ops'=>count($out['richtext']['ops'])])."\n";
 }catch(Throwable $e){echo 'ERROR '.get_class($e).' '.$e->getMessage()."\n";}
}
