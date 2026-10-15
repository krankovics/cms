<?php
declare(strict_types=1);
/* Public-source import utilities. No arbitrary remote URL may bypass the public-IP check. */
function imp_url_metadata(string $url):string{
 $url=trim($url);
 if(strlen($url)>1000||!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Érvénytelen forrás URL.');
 $p=parse_url($url);$host=(string)($p['host']??'');
 if(!in_array(strtolower((string)($p['scheme']??'')),['http','https'],true)||isset($p['user'])||isset($p['pass'])||isset($p['port'])&&!in_array((int)$p['port'],[80,443],true))throw new RuntimeException('Nem támogatott URL.');
 if(!$host||!preg_match('/^[a-z0-9.-]+$/i',$host)||str_ends_with($host,'.local')||$host==='localhost')throw new RuntimeException('Nem nyilvános forráshost.');
 if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Privát vagy fenntartott IP nem engedélyezett.');
 return preg_replace('/#.*/','',$url);
}
function imp_validate_url(string $url):string{
 $url=trim($url);
 if(strlen($url)>1000||!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('Érvénytelen vagy túl hosszú forrás URL.');
 $p=parse_url($url);
 if(!in_array(strtolower((string)($p['scheme']??'')),['http','https'],true)||isset($p['user'])||isset($p['pass']))throw new RuntimeException('Csak nyilvános HTTP(S) oldalak olvashatók.');
 if(isset($p['port'])&&!in_array((int)$p['port'],[80,443],true))throw new RuntimeException('Nem támogatott URL-port.');
 $host=(string)($p['host']??'');
 if(!$host||!preg_match('/^[a-z0-9.-]+$/i',$host))throw new RuntimeException('Nem támogatott forráshost.');
 $ips=gethostbynamel($host)?:[];
 if(filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))$ips=[$host];
 if(!$ips)throw new RuntimeException('A forráshost nem oldható fel.');
 foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Privát vagy fenntartott hálózati cím nem importálható.');
 return preg_replace('/#.*/','',$url);
}
function imp_resolve_url(string $url,string $base):string{
 if(preg_match('~^https?://~i',$url))return $url;
 $p=parse_url($base);
 $root=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
 if(str_starts_with($url,'//'))return $p['scheme'].':'.$url;
 if(str_starts_with($url,'/'))return $root.$url;
 if(str_starts_with($url,'?'))return $root.($p['path']??'/').$url;
 $path=$p['path']??'/';
 $path=substr($path,0,strrpos($path,'/')+1);
 return $root.$path.$url;
}
function imp_get(string $url,int $maxBytes=3145728):array{
 if(!extension_loaded('curl'))throw new RuntimeException('A cURL PHP-kiegészítő nem telepített.');
 for($redirect=0;$redirect<4;$redirect++){
  $url=imp_validate_url($url);$p=parse_url($url);$host=$p['host'];
  $ip=gethostbyname($host);
  if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('Nem publikus célszerver.');
  $port=(int)($p['port']??($p['scheme']==='https'?443:80));
  $body='';$headers=[];$limitExceeded=false;
  $ch=curl_init($url);
  curl_setopt_array($ch,[
   CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,
   CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',
   CURLOPT_RESOLVE=>["$host:$port:$ip"],
   CURLOPT_USERAGENT=>'comesyImporter/1.0 (+https://cms.8media.hu/)',
   CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(str_contains($line,':')){$parts=explode(':',$line,2);$headers[strtolower(trim($parts[0]))]=trim($parts[1]);}return strlen($line);},
   CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body,$maxBytes,&$limitExceeded){if(strlen($body)+strlen($part)>$maxBytes){$limitExceeded=true;return 0;}$body.=$part;return strlen($part);}
  ]);
  $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  if($limitExceeded)throw new RuntimeException('A forrás túl nagy a biztonságos importhoz.');
  if($ok===false)throw new RuntimeException('Forrás letöltése sikertelen: '.mb_substr($error,0,120));
  if($http>=300&&$http<400&&isset($headers['location'])){
   $url=imp_resolve_url($headers['location'],$url);continue;
  }
  return ['status'=>$http,'url'=>$url,'body'=>$body,'headers'=>$headers];
 }
 throw new RuntimeException('Túl sok HTTP-átirányítás.');
}
/** Determine robots.txt policy from matching User-agent groups.
 * Longest matching path directive wins, with Allow winning ties.
 */
function imp_robots_permits(string $robots,string $path,string $agent='comesyimporter'):bool{
 $groups=[];$agents=[];$rules=[];$hasDirectives=false;
 $flush=static function()use(&$groups,&$agents,&$rules,&$hasDirectives):void{
  if($agents)$groups[]=['agents'=>$agents,'rules'=>$rules];
  $agents=[];$rules=[];$hasDirectives=false;
 };
 foreach(preg_split('/\\r?\\n/',$robots) as $line){
  $line=trim(explode('#',$line,2)[0]);
  if(!preg_match('/^([a-z-]+)\\s*:\\s*(.*)$/i',$line,$match))continue;
  $field=strtolower($match[1]);$value=trim($match[2]);
  if($field==='user-agent'){
   if($hasDirectives)$flush();
   if($value!=='')$agents[]=strtolower($value);
   continue;
  }
  if(!in_array($field,['allow','disallow'],true)||!$agents)continue;
  $hasDirectives=true;
  if($value==='')continue; // Empty Disallow means nothing is blocked.
  $rules[]=['type'=>$field,'pattern'=>$value];
 }
 $flush();
 $agent=strtolower($agent);
 $matching=[];
 foreach($groups as $group){
  foreach($group['agents'] as $name){
   if($name!==$agent&&$name!=='*')continue;
   $matching[$name][]=$group['rules'];
  }
 }
 $chosen=$matching[$agent]??$matching['*']??[];
 $best=-1;$allowed=true;
 foreach($chosen as $groupRules)foreach($groupRules as $rule){
  $pattern=$rule['pattern'];
  $anchor=str_ends_with($pattern,'$');
  if($anchor)$pattern=substr($pattern,0,-1);
  $parts=explode('*',$pattern);
  $regex='~^'.implode('.*',array_map(static fn(string $part):string=>preg_quote($part,'~'),$parts)).($anchor?'$':'').'~u';
  if(!preg_match($regex,$path))continue;
  $specificity=mb_strlen(str_replace('*','',$pattern));
  $isAllow=$rule['type']==='allow';
  if($specificity>$best||($specificity===$best&&$isAllow)){
   $best=$specificity;$allowed=$isAllow;
  }
 }
 return $allowed;
}
function imp_allowed_by_robots(string $url):bool{
 static $cache=[];
 $p=parse_url($url);$base=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
 if(!array_key_exists($base,$cache)){
  try{
   $response=imp_get($base.'/robots.txt',131072);
   if($response['status']===404)$cache[$base]='';
   elseif($response['status']!==200)$cache[$base]=false;
   else $cache[$base]=$response['body'];
  }catch(Throwable $e){$cache[$base]=false;}
 }
 if($cache[$base]===false)return false; // Fail closed on robots errors.
 $path=($p['path']??'/').(isset($p['query'])?'?'.$p['query']:'');
 return imp_robots_permits((string)$cache[$base],$path);
}
function imp_fetch(string $url):array{
 imp_validate_url($url);
 if(!imp_allowed_by_robots($url))throw new RuntimeException('A robots.txt vagy a forráselérés tiltja az importot.');
 $result=imp_get($url);
 if(!imp_allowed_by_robots($result['url']))throw new RuntimeException('Az átirányított URL robots.txt szerint tiltott.');
 if(in_array($result['status'],[429,503],true))throw new RuntimeException('FORRÁS_TÚLTERHELT: '.$result['status']);
 if($result['status']!==200)throw new RuntimeException('Forrás HTTP hibakód: '.$result['status']);
 return $result;
}
function imp_extract(string $html,string $url):array{
 if(!extension_loaded('dom'))throw new RuntimeException('A DOM PHP-kiegészítő szükséges.');
 $doc=new DOMDocument('1.0','UTF-8');
 $before=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
 libxml_clear_errors();libxml_use_internal_errors($before);
 $xpath=new DOMXPath($doc);
 foreach($xpath->query('//script|//style|//noscript|//iframe|//nav|//footer|//aside|//form') as $node)$node->parentNode?->removeChild($node);
 $head=(string)$xpath->evaluate('string(//meta[@property="og:title"]/@content)');
 $title=trim($head?:$xpath->evaluate('string(//article//h1[1])')?:$xpath->evaluate('string(//h1[1])')?:$xpath->evaluate('string(//title)'));
 $lead=trim((string)$xpath->evaluate('string(//meta[@name="description"]/@content)'));
 $article=$xpath->query('//article[1]')->item(0)??$xpath->query('//main[1]')->item(0)??$xpath->query('//body[1]')->item(0);
 if(!$article||mb_strlen($title)<3)throw new RuntimeException('A cikk fő tartalma vagy címe nem ismerhető fel.');
 $blocks=[];$seen=[];
 $nodes=$xpath->query('.//h2|.//h3|.//h4|.//p|.//ul|.//ol|.//blockquote|.//img',$article);
 foreach($nodes as $node){
  $tag=strtolower($node->nodeName);
  if(in_array($tag,['p','img'],true)&&$xpath->evaluate('boolean(ancestor::ul or ancestor::ol or ancestor::blockquote)',$node))continue;
  $text=trim(preg_replace('/\s+/u',' ',$node->textContent));
  $type=match($tag){'h2','h3','h4'=>'heading','blockquote'=>'quote','ul','ol'=>'list','img'=>'image',default=>'paragraph'};
  if($tag==='img'){
   $text=(string)($node->getAttribute('data-src')?:$node->getAttribute('src'));
   if(!$text)continue;
   $text=imp_resolve_url($text,$url);
   try{imp_url_metadata($text);}catch(Throwable $e){continue;}
  }
  if(!$text||isset($seen[$type.$text])||mb_strlen($text)>300000)continue;
  $seen[$type.$text]=true;$blocks[]=['type'=>$type,'text'=>$text];
  if(count($blocks)>=200)break;
 }
 if(!$blocks)throw new RuntimeException('Nincs biztonságosan kinyerhető cikkblokk.');
 $links=[];$sourceHost=strtolower((string)parse_url($url,PHP_URL_HOST));
 foreach($xpath->query('.//a[@href]',$article) as $linkNode){
  $raw=trim($linkNode->getAttribute('href'));
  if(!$raw||str_starts_with($raw,'#'))continue;
  $href=imp_resolve_url($raw,$url);
  try{$href=imp_url_metadata($href);}catch(Throwable $e){continue;}
  $links[$href]=['url'=>$href,'internal'=>strtolower((string)parse_url($href,PHP_URL_HOST))===$sourceHost];
  if(count($links)>=500)break;
 }
 $date=trim((string)$xpath->evaluate('string(//meta[@property="article:published_time"]/@content)'));
 return ['title'=>mb_substr($title,0,500),'lead'=>mb_substr($lead,0,10000),'blocks'=>$blocks,'links'=>array_values($links),'source_url'=>$url,'published_at'=>$date];
}
function imp_discover(string $xml,string $base,int $max=100):array{
 if(!extension_loaded('dom'))throw new RuntimeException('A DOM-kiegészítő szükséges.');
 $doc=new DOMDocument();
 $before=libxml_use_internal_errors(true);
 $ok=$doc->loadXML($xml,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
 libxml_clear_errors();libxml_use_internal_errors($before);
 if(!$ok)throw new RuntimeException('Érvénytelen RSS/sitemap XML.');
 $root=strtolower($doc->documentElement->localName);
 $xp=new DOMXPath($doc);
 if(!in_array($root,['rss','feed','urlset','sitemapindex'],true))throw new RuntimeException('Az URL nem támogatott RSS/Atom/sitemap dokumentum.');
 $query=$root==='rss'?'//*[local-name()="item"]/*[local-name()="link"]':($root==='feed'?'//*[local-name()="entry"]/*[local-name()="link"]/@href':'//*[local-name()="loc"]');
 $urls=[];
 foreach($xp->query($query) as $node){
  $link=trim((string)($node->value??$node->textContent));
  if(!$link)continue;
  $link=imp_resolve_url($link,$base);
  try{imp_url_metadata($link);}catch(Throwable $e){continue;}
  $urls[$link]=$link;if(count($urls)>=$max)break;
 }
 return ['urls'=>array_values($urls),'index'=>$root==='sitemapindex'];
}
