<?php
declare(strict_types=1);
/** Assign source editorial taxonomy to native comesy terms without deleting
 * manually edited associations. Source metadata is never a menu or script. */
function cms_import_native_terms(PDO $pdo,int $site,int $article,string $source,string $html):void{
 if($site<1||$article<1||strlen($html)>4000000)return;
 $host=strtolower((string)parse_url($source,PHP_URL_HOST));
 if(!in_array($host,['magyarnemzet.hu','www.magyarnemzet.hu'],true))return;
 $segments=explode('/',trim((string)parse_url($source,PHP_URL_PATH),'/'));
 $categoryKeys=['belfold'=>'Belföld','kulfold'=>'Külföld','gazdasag'=>'Gazdaság','kultura'=>'Kultúra','sport'=>'Sport','velemeny'=>'Vélemény'];
 $suggested=[];
 if(isset($categoryKeys[$segments[0]??'']))$suggested[]=['category',$categoryKeys[$segments[0]],$segments[0]];
 $doc=new DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);
 $ok=$doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();libxml_use_internal_errors($old);
 if($ok){
  $xpath=new DOMXPath($doc);
  foreach($xpath->query('//app-article//*[contains(concat(" ",normalize-space(@class)," ")," article-tags ")]//a[@href]') as $a){
   if(!$a instanceof DOMElement)continue;
   $path=(string)parse_url($a->getAttribute('href'),PHP_URL_PATH);
   if(!preg_match('~^/cimke/([a-z0-9-]{2,100})/?$~D',$path,$m))continue;
   $name=trim(preg_replace('/\s+/u',' ',(string)$a->textContent));
   if(mb_strlen($name)<2||mb_strlen($name)>100)continue;
   $suggested[]=['tag',$name,$m[1]];
   if(count($suggested)>=15)break;
  }
 }
 if(!$suggested)return;
 $create=$pdo->prepare('INSERT IGNORE INTO taxonomy_terms(site_id,type,name,slug) VALUES(?,?,?,?)');
 $find=$pdo->prepare('SELECT id FROM taxonomy_terms WHERE site_id=? AND type=? AND slug=? LIMIT 1');
 $link=$pdo->prepare('INSERT IGNORE INTO article_terms(article_id,term_id) VALUES(?,?)');
 foreach($suggested as [$type,$name,$slug]){
  $create->execute([$site,$type,$name,$slug]);
  $find->execute([$site,$type,$slug]);
  $termId=(int)$find->fetchColumn();
  if($termId)$link->execute([$article,$termId]);
 }
}
