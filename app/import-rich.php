<?php
declare(strict_types=1);
/**
 * Structured, allowlisted rich-content extraction for imported public articles.
 * All URLs are validated; raw source HTML is never sent to the editor or frontend.
 */
function imp_rich_image_url(DOMElement $node,string $base):string{
 $raw='';
 foreach(['data-src','data-original','data-lazy-src','src'] as $attr){$raw=trim($node->getAttribute($attr));if($raw&&!str_starts_with($raw,'data:'))break;$raw='';}
 if(!$raw){$srcset=$node->getAttribute('srcset');if($srcset)$raw=trim(explode(' ',trim(explode(',',$srcset)[0]))[0]);}
 if(!$raw)return '';
 try{$url=imp_resolve_url($raw,$base);return imp_url_metadata($url);}catch(Throwable $e){return '';}
}
function imp_rich_embed_url(string $url,string $base):?array{
 try{$url=imp_url_metadata(imp_resolve_url($url,$base));}catch(Throwable $e){return null;}
 $host=strtolower((string)parse_url($url,PHP_URL_HOST));$path=(string)parse_url($url,PHP_URL_PATH);
 $provider='';
 if(preg_match('/(^|\.)youtube\.com$|(^|\.)youtube-nocookie\.com$|^youtu\.be$/',$host))$provider='youtube';
 elseif(preg_match('/(^|\.)instagram\.com$/',$host))$provider='instagram';
 elseif(preg_match('/(^|\.)facebook\.com$|^fb\.watch$/',$host))$provider='facebook';
 elseif(preg_match('/(^|\.)open\.spotify\.com$/',$host))$provider='spotify';
 elseif(preg_match('/(^|\.)vimeo\.com$|^player\.vimeo\.com$/',$host))$provider='vimeo';
 elseif(preg_match('/(^|\.)soundcloud\.com$/',$host))$provider='soundcloud';
 elseif(preg_match('/(^|\.)podcasts\.apple\.com$/',$host))$provider='apple-podcasts';
 elseif(preg_match('/(^|\.)tiktok\.com$/',$host))$provider='tiktok';
 elseif(in_array($host,['x.com','twitter.com','www.twitter.com','www.x.com'],true))$provider='x';
 if(!$provider)return null;
 if($provider==='youtube'){
  $id='';
  if($host==='youtu.be')$id=trim($path,'/');
  elseif(preg_match('~/(?:embed|shorts|live)/([a-zA-Z0-9_-]{11})~',$path,$m))$id=$m[1];
  else {parse_str((string)parse_url($url,PHP_URL_QUERY),$query);$id=(string)($query['v']??'');}
  if(!preg_match('/^[a-zA-Z0-9_-]{11}$/',$id))return null;
  $url='https://www.youtube-nocookie.com/embed/'.$id;
 }
 if($provider==='spotify'){
  if(!preg_match('~^/(?:embed/)?(episode|show|track|playlist)/([A-Za-z0-9]{15,35})/?$~',$path,$m))return null;
  $url='https://open.spotify.com/embed/'.$m[1].'/'.$m[2];
 }
 if($provider==='vimeo'){
  if(!preg_match('~/(?:video/)?([0-9]{6,15})~',$path,$m))return null;
  $url='https://player.vimeo.com/video/'.$m[1];
 }
 return ['provider'=>$provider,'url'=>$url];
}
function imp_rich_meta_image(DOMXPath $xpath,string $base):string{
 foreach(['og:image:secure_url','og:image','twitter:image'] as $name){
  $raw=trim((string)$xpath->evaluate('string(//meta[@property="'.$name.'" or @name="'.$name.'"]/@content)'));
  if(!$raw)continue;
  try{return imp_url_metadata(imp_resolve_url($raw,$base));}catch(Throwable $e){}
 }
 return '';
}
function imp_rich_ng_data(DOMXPath $xpath,string $title,string $url):?array{
 $node=$xpath->query('//script[@id="ng-state" and @type="application/json"]')->item(0);
 if(!$node)return null;
 $raw=(string)$node->textContent;
 if(!$raw||strlen($raw)>4194304)return null;
 $tree=json_decode($raw,true,128);
 if(!is_array($tree))return null;
 $path=trim(rawurldecode((string)(parse_url($url,PHP_URL_PATH)??'')),'/');
 $slug=mb_strtolower(basename($path));
 $expected=mb_strtolower(trim((string)preg_replace('/[^\pL\pN]+/u',' ',$title)));
 if(!$slug||mb_strlen($expected)<8)return null;
 foreach($tree as $entry){
  $data=$entry['b']['data']??null;
  if(!is_array($data)||array_is_list($data)||!is_array($data['body']??null))continue;
  if(mb_strtolower(trim((string)($data['slug']??''),'/'))!==$slug)continue;
  $candidate=mb_strtolower(trim((string)preg_replace('/[^\pL\pN]+/u',' ',(string)($data['title']??''))));
  if(!$candidate||($candidate!==$expected&&!str_contains($candidate,$expected)&&!str_contains($expected,$candidate)))continue;
  foreach(['isPaywalled','isProtectedContent','isRestricted'] as $flag)if(!empty($data[$flag]))continue 2;
  if(!empty($data['canonicalUrl'])){
   $canonical=trim(rawurldecode((string)(parse_url((string)$data['canonicalUrl'],PHP_URL_PATH)??'')),'/');
   if($canonical&&$canonical!==$path)continue;
  }
  return $data;
 }
 return null;
}
function imp_rich_ng_html(array $data):string{
 $html='';
 foreach(array_slice($data['body']??[],0,200) as $component){
  if(!is_array($component))continue;
  foreach(array_slice($component['details']??[],0,40) as $detail){
   $fragment=$detail['value']??null;
   if(is_string($fragment)&&strlen($fragment)<=300000&&preg_match('~<(?:p|h[1-6]|figure|img|table|iframe|blockquote|ul|ol|div)\b~i',$fragment))$html.=$fragment."\n";
  }
  // Some image/video/audio elements live in nested subcomponents.
  foreach(array_slice((array)($component['subComponents']??[]),0,40) as $sub){
   if(!is_array($sub))continue;
   foreach((array)($sub['details']??[]) as $detail){
    $fragment=$detail['value']??null;
    if(is_string($fragment)&&strlen($fragment)<=100000&&str_contains($fragment,'<'))$html.=$fragment."\n";
   }
  }
 }
 return $html;
}
function imp_rich_inline(DOMNode $node,array $marks,array &$ops,string $base):void{
 if($node instanceof DOMText){
  $text=$node->nodeValue;
  if($text!==''){$op=['insert'=>$text];if($marks)$op['attributes']=$marks;$ops[]=$op;}
  return;
 }
 if(!$node instanceof DOMElement)return;
 $tag=strtolower($node->tagName);
 if(in_array($tag,['script','style','noscript','iframe','img','video','audio','figure','table'],true))return;
 if($tag==='br'){$ops[]=['insert'=>"\n"];return;}
 $next=$marks;
 if(in_array($tag,['strong','b'],true))$next['bold']=true;
 if(in_array($tag,['em','i'],true))$next['italic']=true;
 if($tag==='u')$next['underline']=true;
 if(in_array($tag,['s','del','strike'],true))$next['strike']=true;
 if($tag==='mark')$next['background']='#fff1a8';
 if($tag==='a'&&$node->hasAttribute('href')){
  try{$next['link']=imp_url_metadata(imp_resolve_url($node->getAttribute('href'),$base));}catch(Throwable $e){}
 }
 if($tag==='span'){
  $style=strtolower($node->getAttribute('style'));
  if(preg_match('/font-weight:\s*(?:bold|[7-9]00)/',$style))$next['bold']=true;
  if(str_contains($style,'font-style:italic'))$next['italic']=true;
  if(str_contains($style,'text-decoration:underline'))$next['underline']=true;
  if(str_contains($style,'background-color:'))$next['background']='#fff1a8';
 }
 foreach($node->childNodes as $child)imp_rich_inline($child,$next,$ops,$base);
}
function imp_rich_walk(DOMNode $node,string $base,array &$ops,int $depth=0):void{
 if($depth>28||!$node instanceof DOMElement)return;
 $tag=strtolower($node->tagName);
 if(in_array($tag,['script','style','noscript','nav','aside','form'],true))return;
 $classes=strtolower($node->getAttribute('class').' '.$node->getAttribute('id'));
 if(preg_match('/(?:related|recommended|share-buttons|social-share|newsletter|comments|advertisement|ad-slot|cookie-banner)/',$classes))return;
 if($tag==='figure'){
  $img=$node->getElementsByTagName('img')->item(0);
  if($img instanceof DOMElement){$src=imp_rich_image_url($img,$base);if($src){
   $fig=new DOMXPath($node->ownerDocument);
   $caption=trim((string)$fig->evaluate('string(.//figcaption[1])',$node));
   if(!$caption)$caption=trim((string)$fig->evaluate('string(.//*[contains(@class,"caption")][1])',$node));
   $ops[]=['insert'=>['cmsFigure'=>['src'=>$src,'alt'=>mb_substr($img->getAttribute('alt'),0,500),'caption'=>mb_substr($caption,0,2000)]]];
   $ops[]=['insert'=>"\n"];return;
  }}
 }
 if($tag==='img'){
  $src=imp_rich_image_url($node,$base);
  if($src){$ops[]=['insert'=>['cmsFigure'=>['src'=>$src,'alt'=>mb_substr($node->getAttribute('alt'),0,500),'caption'=>'']]];$ops[]=['insert'=>"\n"];}
  return;
 }
 if($tag==='iframe'){
  $embed=imp_rich_embed_url($node->getAttribute('src'),$base);
  if($embed){$ops[]=['insert'=>['cmsEmbed'=>$embed]];$ops[]=['insert'=>"\n"];}
  return;
 }
 if($tag==='blockquote'&&preg_match('/instagram-media|twitter-tweet|tiktok-embed|fb-post/',$classes)){
  foreach($node->getElementsByTagName('a') as $link){$embed=imp_rich_embed_url($link->getAttribute('href'),$base);if($embed){$ops[]=['insert'=>['cmsEmbed'=>$embed]];$ops[]=['insert'=>"\n"];return;}}
 }
 if($tag==='table'){
  $rows=[];
  foreach($node->getElementsByTagName('tr') as $tr){
   $cells=[];foreach($tr->childNodes as $cell)if($cell instanceof DOMElement&&in_array(strtolower($cell->tagName),['td','th'],true)){
    $cells[]=['text'=>mb_substr(trim((string)preg_replace('/\s+/u',' ',$cell->textContent)),0,2000),'header'=>strtolower($cell->tagName)==='th','colspan'=>max(1,min(10,(int)$cell->getAttribute('colspan')))];
   }
   if($cells)$rows[]=array_slice($cells,0,15);
   if(count($rows)>=60)break;
  }
  if($rows){$ops[]=['insert'=>['cmsTable'=>['rows'=>$rows]]];$ops[]=['insert'=>"\n"];}
  return;
 }
 if(in_array($tag,['p','h1','h2','h3','h4','h5','h6','li','blockquote'],true)){
  if($tag==='li'&&$node->parentNode instanceof DOMElement&&strtolower($node->parentNode->tagName)==='ul')$list='bullet';
  elseif($tag==='li')$list='ordered';else $list=null;
  $text=trim((string)preg_replace('/\s+/u',' ',$node->textContent));
  if($text==='')return;
  foreach($node->childNodes as $child)imp_rich_inline($child,[],$ops,$base);
  $attr=[];
  if(preg_match('/^h([1-6])$/',$tag,$m))$attr['header']=min(3,(int)$m[1]);
  if($tag==='blockquote')$attr['blockquote']=true;
  if($list)$attr['list']=$list;
  $last=['insert'=>"\n"];if($attr)$last['attributes']=$attr;$ops[]=$last;
  return;
 }
 // Known social cards frequently appear as anchor wrappers outside blockquotes.
 if($tag==='a'&&preg_match('/(?:instagram|facebook|spotify|youtube|soundcloud|tiktok)-embed/',$classes)){
  $embed=imp_rich_embed_url($node->getAttribute('href'),$base);
  if($embed){$ops[]=['insert'=>['cmsEmbed'=>$embed]];$ops[]=['insert'=>"\n"];return;}
 }
 foreach($node->childNodes as $child)imp_rich_walk($child,$base,$ops,$depth+1);
}
function imp_rich_delta(string $fragment,string $base):array{
 if(strlen($fragment)>2500000)return ['ops'=>[]];
 $doc=new DOMDocument('1.0','UTF-8');
 $before=libxml_use_internal_errors(true);
 $ok=$doc->loadHTML('<?xml encoding="utf-8"?><body><div id="comesy-rich-root">'.$fragment.'</div></body>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();libxml_use_internal_errors($before);
 if(!$ok)return ['ops'=>[]];
 $root=(new DOMXPath($doc))->query('//*[@id="comesy-rich-root"]')->item(0);
 $ops=[];if($root instanceof DOMElement)imp_rich_walk($root,$base,$ops);
 return ['ops'=>array_slice($ops,0,4500)];
}
function imp_rich_media_urls(array $delta,string $featured=''):array{
 $urls=[];
 if($featured)$urls[]=$featured;
 foreach($delta['ops']??[] as $op){$figure=$op['insert']['cmsFigure']??null;if(is_array($figure)&&!empty($figure['src']))$urls[]=$figure['src'];}
 return array_values(array_unique($urls));
}
