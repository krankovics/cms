<?php
declare(strict_types=1);
/**
 * Read-only, cached publisher news for the draft-preview sidebar.
 * The importer enforces public-IP validation and robots.txt. Nothing is published
 * to the CMS; failed refreshes use the previous cache without changing the draft.
 */
function cms_preview_latest_news(string $host,string $currentSource):array{
 if(!in_array($host,['magyarnemzet.hu','www.magyarnemzet.hu'],true))return [];
 $path=dirname(__DIR__).'/storage/import/preview-news-magyarnemzet.json';
 $cached=[];
 if(is_file($path)){
  $v=json_decode((string)@file_get_contents($path),true);
  if(is_array($v))$cached=$v;
  if($cached&&time()-(int)@filemtime($path)<1800)return $cached;
 }
 try{
  require_once __DIR__.'/import-engine.php';
  $response=imp_fetch('https://magyarnemzet.hu/publicapi/hu/rss/magyar_nemzet/articles',262144);
  if((int)($response['status']??200)>=400)throw new RuntimeException('A hírfolyam nem elérhető.');
  $doc=new DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);
  $ok=$doc->loadXML((string)($response['body']??''),LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
  libxml_clear_errors();libxml_use_internal_errors($old);
  if(!$ok)throw new RuntimeException('Érvénytelen hírfolyam.');
  $xpath=new DOMXPath($doc);$items=[];
  foreach($xpath->query('/rss/channel/item') as $item){
   $name=trim((string)$xpath->evaluate('string(./title)',$item));
   $url=cms_rich_url(trim((string)$xpath->evaluate('string(./link)',$item)));
   if(!$url||!in_array(strtolower((string)parse_url($url,PHP_URL_HOST)),['magyarnemzet.hu','www.magyarnemzet.hu'],true))continue;
   if($url===$currentSource||mb_strlen($name)<15||mb_strlen($name)>260)continue;
   $thumbnail='';
   foreach($xpath->query('./enclosure[@url]',$item) as $attachment){
    if(!$attachment instanceof DOMElement||!str_starts_with(strtolower($attachment->getAttribute('type')),'image/'))continue;
    $candidate=cms_rich_url($attachment->getAttribute('url'));
    if($candidate&&in_array(strtolower((string)parse_url($candidate,PHP_URL_HOST)),['cdn.magyarnemzet.hu','magyarnemzet.hu'],true))$thumbnail=$candidate;
    break;
   }
   $section=explode('/',trim((string)parse_url($url,PHP_URL_PATH),'/'))[0]??'';
   $tags=['belfold'=>'Belföld','kulfold'=>'Külföld','gazdasag'=>'Gazdaság','kultura'=>'Kultúra','sport'=>'Sport'];
   $items[]=['title'=>mb_substr($name,0,180),'url'=>$url,'image'=>$thumbnail,'label'=>$tags[$section]??'Friss hír'];
   if(count($items)>=6)break;
  }
  if(count($items)<2)throw new RuntimeException('Kevés használható hír.');
  $dir=dirname($path);
  if(!is_dir($dir))@mkdir($dir,0750,true);
  if(is_dir($dir)&&is_writable($dir)){
   $tmp=@tempnam($dir,'.news-');
   if($tmp){
    @file_put_contents($tmp,json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX);
    @chmod($tmp,0640);@rename($tmp,$path);
   }
  }
  return $items;
 }catch(Throwable $e){
  error_log('comesy preview latest news: '.$e->getMessage());
  return $cached;
 }
}
