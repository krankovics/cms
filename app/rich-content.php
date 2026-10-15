<?php
declare(strict_types=1);

/**
 * Shared allowlist HTML pipeline for editorial imports, editor saves and public output.
 * Never store untrusted scripts, event handlers, CSS, arbitrary iframes or executable URLs.
 */
function cms_rich_url(string $raw,string $base=''):string{
 $raw=trim(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'));
 if($raw===''||strlen($raw)>1800||preg_match('/[\x00-\x1F\x7F]/',$raw))return '';
 if(str_starts_with($raw,'//'))$raw='https:'.$raw;
 elseif(!preg_match('~^https?://~i',$raw)){
  if($base===''||!preg_match('~^https?://~i',$base))return '';
  $b=parse_url($base);
  if(!is_array($b)||empty($b['host']))return '';
  if(str_starts_with($raw,'#'))return '';
  $root=strtolower((string)$b['scheme']).'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'');
  if(str_starts_with($raw,'/'))$raw=$root.$raw;
  else{
   $basePath=(string)($b['path']??'/');
   $raw=$root.substr($basePath,0,strrpos($basePath,'/')+1).$raw;
  }
 }
 $u=parse_url($raw);
 if(!is_array($u)||!in_array(strtolower((string)($u['scheme']??'')),['https','http'],true)||empty($u['host'])||isset($u['user'])||isset($u['pass']))return '';
 $host=strtolower(rtrim((string)$u['host'],'.'));
 if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local'))return '';
 if(filter_var($host,FILTER_VALIDATE_IP)){
  if(!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return '';
 }
 $p=(string)($u['path']??'/');
 $segments=[];
 foreach(explode('/',$p) as $segment){
  if($segment==='..')array_pop($segments);
  elseif($segment!=='.')$segments[]=$segment;
 }
 $prefix=strtolower((string)$u['scheme']).'://'.$host.(isset($u['port'])?':'.$u['port']:'');
 return $prefix.implode('/',$segments).(isset($u['query'])?'?'.$u['query']:'').(isset($u['fragment'])?'#'.$u['fragment']:'');
}
function cms_rich_embed_url(string $url):string{
 $url=cms_rich_url($url);
 if($url==='')return '';
 $u=parse_url($url);$host=strtolower((string)$u['host']);$path=(string)($u['path']??'');
 if(in_array($host,['youtube.com','www.youtube.com','www.youtube-nocookie.com'],true)&&preg_match('~^/embed/[a-zA-Z0-9_-]{6,}$~',$path))return $url;
 if($host==='player.vimeo.com'&&preg_match('~^/video/\d+~',$path))return $url;
 if(in_array($host,['open.spotify.com','embed.spotify.com'],true)&&preg_match('~^/embed/(?:track|episode|show|playlist|album)/[a-zA-Z0-9]+~',$path))return $url;
 if($host==='w.soundcloud.com'&&str_starts_with($path,'/player'))return $url;
 if(in_array($host,['facebook.com','www.facebook.com','web.facebook.com'],true)&&str_starts_with($path,'/plugins/'))return $url;
 if(in_array($host,['instagram.com','www.instagram.com'],true)&&preg_match('~^/(?:p|reel|tv)/[a-zA-Z0-9_-]+/embed~',$path))return $url;
 if(in_array($host,['www.tiktok.com','tiktok.com'],true)&&preg_match('~^/embed/(?:v2/)?\d+~',$path))return $url;
 if($host==='player.simplecast.com'&&preg_match('~^/[a-zA-Z0-9_-]+~',$path))return $url;
 if($host==='embed.podcasts.apple.com'&&str_starts_with($path,'/'))return $url;
 if($host==='www.podbean.com'&&str_starts_with($path,'/player-v2/'))return $url;
 return '';
}
function cms_rich_sanitize_html(string $html,string $base=''):string{
 if($html===''||strlen($html)>800000)return '';
 $dom=new DOMDocument('1.0','UTF-8');$prior=libxml_use_internal_errors(true);
 $ok=$dom->loadHTML('<?xml encoding="utf-8"?><div id="cms-rich-root">'.$html.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();libxml_use_internal_errors($prior);
 if(!$ok)return '';
 $xp=new DOMXPath($dom);$root=$xp->query('//*[@id="cms-rich-root"]')->item(0);
 if(!$root)return '';
 $tags=array_fill_keys(explode(' ','p div span strong b em i u mark s del small sup sub h2 h3 h4 h5 h6 br ul ol li blockquote pre code figure figcaption img table caption thead tbody tfoot tr th td a iframe audio video source hr'),true);
 $void=['br'=>true,'img'=>true,'source'=>true,'hr'=>true];
 $drop=array_fill_keys(explode(' ','script style noscript form input button select textarea object embed svg math meta link canvas template iframeX'),true);
 $attr=static fn(string $key,string $value):string=>' '. $key.'="'.htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"';
 $walk=static function(DOMNode $node)use(&$walk,$tags,$void,$drop,$base,$attr):string{
  if($node instanceof DOMText)return htmlspecialchars($node->nodeValue??'',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  if(!$node instanceof DOMElement)return '';
  $tag=strtolower($node->tagName);
  if(isset($drop[$tag]))return '';
  $inside='';
  foreach($node->childNodes as $child)$inside.=$walk($child);
  if(!isset($tags[$tag]))return $inside;
  if($tag==='iframe'){
   $url=cms_rich_embed_url((string)$node->getAttribute('src'));
   if($url==='')return '';
   $title=trim((string)$node->getAttribute('title'))?:'Beágyazott tartalom';
   return '<iframe'.$attr('src',$url).$attr('title',mb_substr($title,0,180)).' loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
  }
  if($tag==='img'||$tag==='source'){
   $raw=(string)($node->getAttribute('data-src')?:$node->getAttribute('src'));
   $url=cms_rich_url($raw,$base);
   if($url==='')return '';
   $out='<'.$tag.$attr('src',$url);
   if($tag==='img')$out.=$attr('alt',mb_substr((string)$node->getAttribute('alt'),0,400)).' loading="lazy"';
   if($tag==='source'&&$node->hasAttribute('type')&&preg_match('~^(?:audio|video)/[a-z0-9.+-]+$~i',$node->getAttribute('type')))$out.=$attr('type',$node->getAttribute('type'));
   return $out.'>';
  }
  if($tag==='a'){
   $href=cms_rich_url((string)$node->getAttribute('href'),$base);
   return $href?'<a'.$attr('href',$href).' rel="noopener noreferrer" target="_blank">'.$inside.'</a>':$inside;
  }
  $attrs='';
  if($tag==='audio'||$tag==='video'){
   $src=cms_rich_url((string)$node->getAttribute('src'),$base);
   if($src!=='')$attrs.=$attr('src',$src);
   $attrs.=' controls preload="none"';
   if($tag==='video'){
    $poster=cms_rich_url((string)$node->getAttribute('poster'),$base);
    if($poster)$attrs.=$attr('poster',$poster);
   }
  }
  if(in_array($tag,['td','th'],true)){
   foreach(['colspan','rowspan'] as $name){
    $val=(int)$node->getAttribute($name);
    if($val>=2&&$val<=50)$attrs.=$attr($name,(string)$val);
   }
   if($tag==='th'&&in_array($node->getAttribute('scope'),['col','row','colgroup','rowgroup'],true))$attrs.=$attr('scope',$node->getAttribute('scope'));
  }
  if(isset($void[$tag]))return '<'.$tag.$attrs.'>';
  return '<'.$tag.$attrs.'>'.$inside.'</'.$tag.'>';
 };
 $out='';foreach($root->childNodes as $child)$out.=$walk($child);
 return $out;
}
function cms_rich_content_chars(string $html):int{
 $plain=trim((string)preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8')));
 return mb_strlen($plain);
}

/** Deterministic asset references from already sanitized, rich imported HTML. */
function cms_rich_references(string $html):array{
 if($html===''||strlen($html)>800000)return ['images'=>[],'links'=>[]];
 $dom=new DOMDocument('1.0','UTF-8');$prior=libxml_use_internal_errors(true);
 $ok=$dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();libxml_use_internal_errors($prior);
 if(!$ok)return ['images'=>[],'links'=>[]];
 $xpath=new DOMXPath($dom);$images=[];$links=[];
 foreach($xpath->query('//img[@src]') as $node){
  $url=cms_rich_url((string)$node->getAttribute('src'));if($url)$images[$url]=$url;
 }
 foreach($xpath->query('//a[@href] | //iframe[@src] | //audio[@src] | //video[@src] | //source[@src]') as $node){
  $attr=$node->tagName==='a'?'href':'src';
  $url=cms_rich_url((string)$node->getAttribute($attr));if($url)$links[$url]=$url;
 }
 return ['images'=>array_values($images),'links'=>array_values($links)];
}
