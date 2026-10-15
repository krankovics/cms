<?php
declare(strict_types=1);
/**
 * Central, site-scoped source visual profiles for imported editorial articles.
 * Stores typography/colors/spacing extracted from approved CSS declarations,
 * never executable scripts, external fonts, imports, or untrusted full stylesheets.
 */
function cms_source_style_path(int $site,string $url):string{
 $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));
 if($site<1||!preg_match('/^[a-z0-9.-]{3,253}$/',$host))throw new InvalidArgumentException('Érvénytelen forráswebhely.');
 return dirname(__DIR__).'/storage/import/source-styles/'.hash('sha256',$site.'|'.$host).'.json';
}
function cms_source_style_read(int $site,string $url):?array{
 try{
  $path=cms_source_style_path($site,$url);
  if(!is_file($path))return null;
  $value=json_decode((string)file_get_contents($path),true);
  return is_array($value)&&($value['host']??'')===strtolower((string)parse_url($url,PHP_URL_HOST))&&is_string($value['css']??null)?$value:null;
 }catch(Throwable $e){return null;}
}
/** Pick only semantic article styles and a tight CSS property allowlist. */
function cms_source_style_extract_css(string $css):string{
 $css=substr($css,0,650000);
 // We do not ship arbitrary source CSS. This excludes @rules, url(), expression(),
 // CSS variables, image tracking resources, and global selectors.
 $css=preg_replace('~/\*.*?\*/~s','',$css);
 $css=preg_replace('~@(?:import|font-face|keyframes|supports|media)[^{;]*(?:;|\{(?:[^{}]*|\{[^{}]*\})*\})~is','',$css);
 $out=[];$count=0;
 preg_match_all('~([^{}]+)\{([^{}]*)\}~',$css,$rules,PREG_SET_ORDER);
 $targets=[
  'body'=>'.cms-source-article',
  'article'=>'.cms-source-article',
  'h1'=>'.cms-source-article h1',
  'h2'=>'.cms-source-article h2',
  'h3'=>'.cms-source-article h3',
  'h4'=>'.cms-source-article h4',
  'p'=>'.cms-source-article p',
  'a'=>'.cms-source-article a',
  'figure'=>'.cms-source-article figure',
  'figcaption'=>'.cms-source-article figcaption',
  'blockquote'=>'.cms-source-article blockquote',
  'img'=>'.cms-source-article img',
  'table'=>'.cms-source-article table',
  'td'=>'.cms-source-article td',
  'th'=>'.cms-source-article th',
  'ul'=>'.cms-source-article ul',
  'ol'=>'.cms-source-article ol',
  'li'=>'.cms-source-article li',
 ];
 $properties=['color'=>1,'background-color'=>1,'font-family'=>1,'font-size'=>1,'font-weight'=>1,
  'font-style'=>1,'line-height'=>1,'letter-spacing'=>1,'text-transform'=>1,'text-decoration'=>1,
  'text-align'=>1,'margin-top'=>1,'margin-bottom'=>1,'padding-top'=>1,'padding-bottom'=>1,
  'border-radius'=>1,'border-color'=>1,'border-width'=>1,'border-style'=>1];
 foreach($rules as $rule){
  if(++$count>1500||strlen(implode('',$out))>26000)break;
  $selectors=explode(',',$rule[1]);
  $mapped=[];
  foreach($selectors as $sel){
   $sel=trim($sel);
   if($sel===''||preg_match('/[@*:>\[\]#~+]/',$sel))continue;
   // An article-specific selector is preferred; other site-wide rules are
   // retained only for body/html or simple semantic element selectors.
   if(!preg_match('~(?:^|[\s.])(?:article|post|content|story|entry|body|text|cikk|main)(?:[\s_.-]|$)~i',$sel)&&
     !preg_match('/^(?:html\s+)?(?:body|h[1-4]|p|a|figure|figcaption|blockquote|img|table|td|th|ul|ol|li)$/i',$sel))continue;
   if(!preg_match('/(?:^|[\s>])(?:body|article|h[1-4]|p|a|figure|figcaption|blockquote|img|table|td|th|ul|ol|li)\s*$/i',$sel,$m))continue;
   $name=strtolower(trim($m[0]));
   if(!isset($targets[$name]))continue;
   $mapped[$targets[$name]]=true;
  }
  if(!$mapped)continue;
  $declarations=[];
  foreach(explode(';',$rule[2]) as $decl){
   if(!str_contains($decl,':'))continue;
   [$key,$val]=array_map('trim',explode(':',$decl,2));$key=strtolower($key);
   if(!isset($properties[$key])||$val===''||strlen($val)>130)continue;
   if(!preg_match('~^[#\w\s.,%()"/+!:\-]+$~u',$val)||preg_match('~(?:url|expression|var|@import|javascript|data|behavior|binding|position|calc)\s*\(~i',$val))continue;
   $val=preg_replace('/\s*!important\s*$/i','',$val);
   if(str_contains($val,'!')||str_contains($val,'--'))continue;
   $declarations[$key]=$val;
  }
  if($declarations)$out[]=implode(', ',array_keys($mapped)).'{'.implode(';',array_map(static fn($k,$v)=>$k.':'.$v,array_keys($declarations),array_values($declarations))).'}';
 }
 return implode("\n",$out);
}
function cms_source_style_inline(string $html):string{
 if(strlen($html)>3500000)return '';
 $doc=new DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
 libxml_clear_errors();libxml_use_internal_errors($old);
 $raw='';
 foreach($doc->getElementsByTagName('style') as $el){
  if(strlen($raw)>150000)break;
  $raw.=substr((string)$el->textContent,0,65000)."\n";
 }
 return cms_source_style_extract_css($raw);
}
/** Read links before article extraction strips scripts, styles and navigation. */
function cms_source_style_links(string $html,string $url):array{
 $doc=new DOMDocument('1.0','UTF-8');$old=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
 libxml_clear_errors();libxml_use_internal_errors($old);
 $links=[];$host=strtolower((string)parse_url($url,PHP_URL_HOST));
 foreach($doc->getElementsByTagName('link') as $el){
  if(!preg_match('/(?:^|\s)stylesheet(?:\s|$)/i',$el->getAttribute('rel')))continue;
  $href=trim($el->getAttribute('href'));if($href==='')continue;
  $candidate=imp_resolve_url($href,$url);
  $parts=parse_url($candidate);
  if(!is_array($parts)||empty($parts['host'])||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true))continue;
  if(strtolower((string)$parts['host'])!==$host&&strtolower((string)$parts['scheme'])!=='https')continue; // External CSS must use HTTPS.
  if(!preg_match('~\.css(?:$|\?)~i',(string)($parts['path']??'').(isset($parts['query'])?'?'.$parts['query']:'')))continue;
  $links[$candidate]=true;if(count($links)>=3)break;
 }
 return array_keys($links);
}
function cms_source_style_collect(int $site,string $html,string $url,bool $force=false):?array{
 $path=cms_source_style_path($site,$url);
 $previous=cms_source_style_read($site,$url);
 if(!$force&&$previous&&time()-(int)($previous['saved_at']??0)<604800)return $previous;
 $css=cms_source_style_inline($html);$count=0;
 foreach(cms_source_style_links($html,$url) as $href){
  try{
   // Use the existing SSRF/robots-protected importer HTTP pipeline.
   $response=imp_fetch($href,524288);
   if(!preg_match('~(?:text/css|text/plain|application/octet-stream)~i',(string)($response['headers']['content-type']??'text/css')))continue;
   $part=cms_source_style_extract_css($response['body']);
   if($part!==''){$css.="\n".$part;$count++;}
  }catch(Throwable $e){error_log('comesy source style skipped: '.$e->getMessage());}
  if(strlen($css)>32000)break;
 }
 $css=substr($css,0,36000);
 $profile=['host'=>strtolower((string)parse_url($url,PHP_URL_HOST)),'site_id'=>$site,
  'source_url'=>$url,'saved_at'=>time(),'css'=>$css,'css_files'=>$count,'version'=>1];
 $dir=dirname($path);
 if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('A forrásstílusok tárolója nem írható.');
 $tmp=tempnam($dir,'.source-');if(!$tmp)throw new RuntimeException('Forrásstílus ideiglenes fájl nem írható.');
 try{
  $json=json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Forrásstílus mentési hiba.');
  chmod($tmp,0640);
  if(!rename($tmp,$path))throw new RuntimeException('Forrásstílus atomikus mentési hiba.');
 }finally{if(is_file($tmp))@unlink($tmp);}
 return $profile;
}
