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
