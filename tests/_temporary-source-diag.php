<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-engine.php';
// Temporary private diagnostic: output structure only, never raw article paragraphs.
$url='https://magyarnemzet.hu/kulfold/2026/10/gyerekeket-nyomott-agyon-a-leszakadt-tetozet';
try{$res=imp_get($url,3145728);}catch(Throwable $e){echo "Source fetch failed: ".$e->getMessage()."\n";exit(0);}
echo 'HTTP '.$res['status'].' bytes='.strlen($res['body'])."\n";
$html=$res['body'];
foreach(['A diákok 6 és 12','katasztrófavédelem','articleBody','json-ld','article-body','story-body','article__body','story-content'] as $term){
 echo 'source contains '.json_encode($term).'='.((mb_stripos($html,$term)!==false)?'yes':'no')."\n";
}
$doc=new DOMDocument();
$old=libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
libxml_clear_errors();libxml_use_internal_errors($old);
$xp=new DOMXPath($doc);
$ranked=[];
foreach($xp->query('//article | //main | //*[@itemprop="articleBody"] | //div[contains(@class,"article") or contains(@class,"story") or contains(@class,"body") or contains(@class,"content")]') as $node){
 if(!$node instanceof DOMElement)continue;
 $para=$xp->query('.//p',$node);
 $stats=imp_body_stats($xp,$node);
 $ranked[]=[$stats['total'],$node->tagName,substr($node->getAttribute('class'),0,120),substr($node->getAttribute('id'),0,50),$para->length,$stats['long']];
}
usort($ranked,static fn($a,$b)=>$b[0]<=>$a[0]);
foreach(array_slice($ranked,0,18) as $item)echo "candidate ".json_encode($item,JSON_UNESCAPED_UNICODE)."\n";
foreach($xp->query('//text()') as $node){
 if(mb_stripos($node->nodeValue,'A diákok 6 és 12')===false)continue;
 $p=$node->parentNode;$parents=[];
 while($p&&count($parents)<7){if($p instanceof DOMElement)$parents[]=$p->tagName.'['.substr($p->getAttribute('class'),0,95).']';$p=$p->parentNode;}
 echo "NARRATIVE_NODE ".json_encode($parents,JSON_UNESCAPED_UNICODE)."\n";break;
}
echo "body selection: ".(imp_select_article_body($xp)?'found':'missing')."\n";

foreach($xp->query('//script') as $script){
 $text=(string)$script->textContent;
 if(mb_stripos($text,'A diákok 6 és 12')===false)continue;
 echo "SCRIPT containing narrative: id=".($script->attributes?->getNamedItem('id')?->nodeValue??'').
   ' type='.($script->attributes?->getNamedItem('type')?->nodeValue??'').
   ' len='.strlen($text)."\\n";
 echo "SCRIPT first 180: ".substr($text,0,180)."\\n";
 $idx=mb_stripos($text,'A diákok 6 és 12');
 echo "SCRIPT preceding 115 bytes: ".substr($text,max(0,$idx-115),115)."\\n";
 echo "SCRIPT succeeding 115 bytes: ".substr($text,$idx+80,115)."\\n";
 foreach(['articleBody','articleText','body','content','html','data','paragraphs','__NUXT__','__NEXT_DATA__','window.__','bodyContent'] as $label){
  echo " SCRIPT_MARKER ".json_encode($label)." ".(str_contains($text,$label)?'present':'absent')."\\n";
 }
 $plain=json_decode($text,true);
 echo ' SCRIPT_JSON '.(is_array($plain)?'yes':'no')."\\n";
 if(is_array($plain)){
  $todo=[[$plain,'root']];$count=0;
  while($todo&&$count++<200000){
   [$val,$key]=array_pop($todo);
   if(is_string($val)){if(mb_stripos($val,'A diákok 6 és 12')!==false)echo ' JSON_PATH '.$key.' length='.mb_strlen($val)."\\n";continue;}
   if(!is_array($val))continue;
   foreach($val as $k=>$v)if(is_array($v)||is_string($v))$todo[]=[$v,$key.'/'.$k];
  }
 }
}

$state=$xp->query('//script[@id="ng-state"]')->item(0);
if($state){
 $j=json_decode($state->textContent,true);
 echo 'TOP KEYS COUNT '.count($j). "\\n";
 foreach($j as $key=>$outer){
  $data=$outer['b']['data']??null;
  if(!is_array($data)||!array_key_exists('body',$data))continue;
  echo "ENTRY $key DATA KEYS ".json_encode(array_keys($data),JSON_UNESCAPED_UNICODE)."\\n";
  foreach(['title','headline','slug','url','canonicalUrl','contentType','lead','id','body'] as $f)if(isset($data[$f])){
   $val=$data[$f];
   echo "ENTRY FIELD $f ".(is_array($val)?'array count='.count($val):'len='.mb_strlen((string)$val).' starts='.mb_substr((string)$val,0,75))."\\n";
  }
  foreach(array_slice($data['body'],0,7) as $i=>$blk){
   echo "BODY $i KEYS ".json_encode(array_keys((array)$blk))."\\n";
   foreach(($blk['details']??[]) as $t=>$detail){
    $v=(string)($detail['value']??'');
    echo ' DETAIL '. $t.' KEYS '.json_encode(array_keys((array)$detail)).
     ' value-length='.mb_strlen($v).' has-HTML='.(str_contains($v,'<p')?'true':'false').' starts='.substr($v,0,55)."\\n";
   }
  }
 }
}
