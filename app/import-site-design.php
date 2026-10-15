<?php
declare(strict_types=1);
/**
 * Extract a limited, auditable source-site shell. Never copy arbitrary DOM,
 * event handlers, scripts, consent dialogs, advertising or tracking markup.
 * Every value is plain text or a public HTTP(S) URL.
 */
function cms_source_design_text(string $text,int $max=150):string{
 $text=trim((string)preg_replace('/\s+/u',' ',strip_tags($text)));
 return mb_substr($text,0,$max);
}
function cms_source_design_url(string $raw,string $page):string{
 $raw=trim(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'));
 if($raw===''||strlen($raw)>1000||preg_match('~^(?:javascript|data|mailto|tel|blob):~i',$raw))return '';
 try{
  $url=imp_resolve_url($raw,$page);
  return imp_url_metadata($url);
 }catch(Throwable $e){return '';}
}
function cms_source_design_menu(DOMXPath $xpath,DOMElement $node,string $source):array{
 $host=strtolower((string)parse_url($source,PHP_URL_HOST));
 $items=[];
 foreach($xpath->query('.//a[@href]',$node) as $a){
  if(!$a instanceof DOMElement)continue;
  $label=cms_source_design_text($a->getAttribute('aria-label')?:$a->textContent,60);
  $url=cms_source_design_url($a->getAttribute('href'),$source);
  if(mb_strlen($label)<2||!$url||strtolower((string)parse_url($url,PHP_URL_HOST))!==$host)continue;
  if(preg_match('~^(?:belépés|bejelentkezés|regisztráció|login|sign in|sign up|menü|menu|search|keresés|facebook|instagram|x|tiktok|youtube)$~iu',$label))continue;
  $key=mb_strtolower($label);
  if(isset($items[$key]))continue;
  $items[$key]=['label'=>$label,'url'=>$url];
  if(count($items)>=14)break;
 }
 return array_values($items);
}
function cms_source_design_extract(string $html,string $source):array{
 $fallback=['name'=>strtolower((string)parse_url($source,PHP_URL_HOST)),'logo_url'=>'',
  'header_links'=>[],'footer_links'=>[],'footer_text'=>'','copyright'=>''];
 if(strlen($html)>3500000||!class_exists(DOMDocument::class))return $fallback;
 $doc=new DOMDocument('1.0','UTF-8');$prior=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();libxml_use_internal_errors($prior);
 $xpath=new DOMXPath($doc);
 // Explicit publisher identity beats arbitrary visible logo text.
 $name=cms_source_design_text((string)$xpath->evaluate('string(//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:site_name"]/@content)'),100);
 if($name==='')$name=cms_source_design_text((string)$xpath->evaluate('string(//header//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"logo")][1]/@aria-label)'),100);
 if($name==='')$name=cms_source_design_text((string)$xpath->evaluate('string(//title)'),100);
 if($name!=='')$fallback['name']=preg_replace('/\s+[|–—-]\s+.+$/u','',$name);
 // Logo: header and site-logo elements only, never the article's OG cover.
 $logos=$xpath->query('//header//img | //a[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"logo")]//img | //img[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"site-logo")]');
 foreach($logos as $img){
  if(!$img instanceof DOMElement)continue;
  $context=strtolower($img->getAttribute('class').' '.$img->getAttribute('alt').' '.$img->parentNode?->nodeName);
  $src=$img->getAttribute('data-src')?:$img->getAttribute('src');
  if($src===''||str_starts_with($src,'data:'))continue;
  if(!preg_match('/(?:logo|brand|header)/',$context)&&$img->parentNode?->nodeName!=='a')continue;
  $logo=cms_source_design_url($src,$source);
  if($logo==='')continue;
  $fallback['logo_url']=$logo;
  if(preg_match('/(?:logo|brand)/',$context))break;
 }
 if($fallback['logo_url']===''){
  // Some modern publisher headers render their logo only client-side. Prefer
  // a declared logo asset, falling back to the site's icon without copying SVG DOM.
  foreach($xpath->query('//meta[@property="og:logo"] | //link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"icon")]') as $tag){
   if(!$tag instanceof DOMElement)continue;
   $raw=$tag->tagName==='meta'?$tag->getAttribute('content'):$tag->getAttribute('href');
   $logo=cms_source_design_url($raw,$source);
   if($logo!==''){$fallback['logo_url']=$logo;break;}
  }
 }
 // Prefer explicit main navigation in header; avoid footers, menus nested in article.
 $navs=$xpath->query('//header//nav | //nav[@role="navigation"] | //nav[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"main")] | //nav[contains(translate(@aria-label,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"primary")]');
 $best=[];$score=-1;
 foreach($navs as $nav){
  if(!$nav instanceof DOMElement)continue;
  $items=cms_source_design_menu($xpath,$nav,$source);
  $attrs=strtolower($nav->getAttribute('class').' '.$nav->getAttribute('aria-label'));
  $weight=count($items)+((bool)preg_match('/(?:main|primary|desktop|mainnav|főmenü)/',$attrs)?8:0);
  if($weight>$score&&count($items)>=2){$best=$items;$score=$weight;}
 }
 if(!$best){
  $headers=$xpath->query('//header[1]');
  if($headers->length)$best=cms_source_design_menu($xpath,$headers->item(0),$source);
 }
 $fallback['header_links']=$best;
 $footers=$xpath->query('//footer[not(ancestor::article)] | //*[@role="contentinfo"]');
 if($footers->length){
  $footer=$footers->item($footers->length-1);
  if($footer instanceof DOMElement){
   $fallback['footer_links']=array_slice(cms_source_design_menu($xpath,$footer,$source),0,10);
   $copyText='';
   foreach($xpath->query('.//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"copyright")] | .//small | .//p',$footer) as $node){
    $txt=cms_source_design_text((string)$node->textContent,240);
    if(preg_match('/(?:©|\bcopyright\b|\ball rights reserved\b)/iu',$txt)){$copyText=$txt;break;}
   }
   $fallback['copyright']=$copyText;
   $plain=cms_source_design_text((string)$footer->textContent,420);
   if($plain!=='')$fallback['footer_text']=$plain;
  }
 }
 return $fallback;
}
/** DOM-derived metadata only. A manually curated design is never overwritten. */
function cms_source_design_merge(array $page,array $home):array{
 foreach(['name','logo_url','header_links','footer_links','footer_text','copyright'] as $key){
  if($key==='name'){
   if(!empty($home[$key]))$page[$key]=$home[$key];
  }elseif(!empty($home[$key]))$page[$key]=$home[$key];
 }
 return $page;
}
