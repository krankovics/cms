<?php
declare(strict_types=1);
require_once __DIR__.'/import-rich.php';
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
/**
 * A sitemap may contain homepages, archives and feeds. These are not articles.
 * Path-only checks stay conservative: one-segment article slugs are valid.
 */
function imp_is_article_candidate_url(string $url):bool{
 $p=parse_url($url);
 $path=rawurldecode((string)($p['path']??'/'));
 $path=rtrim($path,'/');
 if($path===''||preg_match('~^/(?:index\.(?:php|html?)|home|feed|rss|atom|sitemap(?:index)?(?:\.xml)?|robots\.txt)$~i',$path))return false;
 if(preg_match('~\.(?:xml|xml\.gz|rss|atom|json|txt)$~i',$path))return false;
 if(preg_match('~^/(?:kereses|search|cimke|tag|tags)(?:/|$)~i',$path))return false;
 return true;
}
/** Latest dated child sitemap first; undated sources keep their original order. */
function imp_sitemap_priority(string $url,string $lastmod=''):int{
 if(preg_match('/^((?:19|20)\d{2})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])/',trim($lastmod),$m))
  return (int)($m[1].$m[2].$m[3]);
 $path=(string)(parse_url($url,PHP_URL_PATH)??'');
 if(preg_match('~(?:^|/)((?:19|20)\d{2})(0[1-9]|1[0-2])[_-]sitemap(?:\d+)?\.xml(?:\.gz)?$~i',$path,$m))
  return (int)($m[1].$m[2].'01');
 return 0;
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
   CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,
   CURLOPT_ENCODING=>'', // Decompress HTTP gzip while enforcing the byte limit on the decoded stream.
   CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',
   CURLOPT_RESOLVE=>["$host:$port:$ip"],
   CURLOPT_USERAGENT=>'comesyImporter/1.0 (+https://cms.8media.hu/)',
   CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){if(str_contains($line,':')){$parts=explode(':',$line,2);$headers[strtolower(trim($parts[0]))]=trim($parts[1]);}return strlen($line);},
   CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body,$maxBytes,&$limitExceeded){if(strlen($body)+strlen($part)>$maxBytes){$limitExceeded=true;return 0;}$body.=$part;return strlen($part);}
  ]);
  $ok=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
  if($limitExceeded)throw new RuntimeException('A forrás nagyobb a biztonságos '.(int)floor($maxBytes/1048576).' MiB-os feldolgozási korlátnál. Próbálj kisebb sitemapet vagy külön forrás-URL-t.');
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
function imp_fetch(string $url,int $maxBytes=3145728):array{
 imp_validate_url($url);
 if(!imp_allowed_by_robots($url))throw new RuntimeException('A robots.txt vagy a forráselérés tiltja az importot.');
 $result=imp_get($url,$maxBytes);
 if(!imp_allowed_by_robots($result['url']))throw new RuntimeException('Az átirányított URL robots.txt szerint tiltott.');
 if(in_array($result['status'],[429,503],true))throw new RuntimeException('FORRÁS_TÚLTERHELT: '.$result['status']);
 if($result['status']!==200)throw new RuntimeException('Forrás HTTP hibakód: '.$result['status']);
 return $result;
}
/** Permanent content-quality rejection, distinct from a retryable download/worker error. */
class ImportContentRejected extends RuntimeException{}

/** Read full articleBody only from an actual NewsArticle/Article JSON-LD record. */
function imp_jsonld_article_blocks(DOMXPath $xpath,string $title):array{
 $jsonScripts=$xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"application/ld+json")]');
 foreach($jsonScripts as $script){
  $raw=trim((string)$script->textContent);
  if($raw===''||strlen($raw)>1048576)continue;
  $tree=json_decode($raw,true);
  if(!is_array($tree))continue;
  $queue=[$tree];$visited=0;
  while($queue&&++$visited<=80){
   $node=array_shift($queue);
   if(!is_array($node))continue;
   if(array_is_list($node)){foreach($node as $child)$queue[]=$child;continue;}
   foreach(['@graph','mainEntity'] as $key)if(isset($node[$key])&&is_array($node[$key]))$queue[]=$node[$key];
   $types=$node['@type']??[];
   if(is_string($types))$types=[$types];
   if(!is_array($types)||!array_intersect($types,['Article','NewsArticle','BlogPosting','ReportageNewsArticle','AnalysisNewsArticle']))continue;
   $headline=trim((string)($node['headline']??''));
   if($headline!==''){
    $cleanTitle=mb_strtolower(trim((string)preg_replace('/[^\pL\pN]+/u',' ',$title)));
    $cleanHeadline=mb_strtolower(trim((string)preg_replace('/[^\pL\pN]+/u',' ',$headline)));
    if($cleanTitle!==$cleanHeadline&&!str_contains($cleanTitle,$cleanHeadline)&&!str_contains($cleanHeadline,$cleanTitle))continue;
   }
   $body=$node['articleBody']??null;
   if(!is_string($body)||strlen($body)>300000)continue;
   $text=preg_replace('~</(?:p|div|li|h[1-6])>|<br\s*/?>~iu',"\n\n",$body);
   $text=html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8');
   $paragraphs=preg_split('/\n\s*\n+/u',$text)?:[];
   $blocks=[];$length=0;$sentences=0;
   foreach($paragraphs as $paragraph){
    $paragraph=trim((string)preg_replace('/\s+/u',' ',$paragraph));
    if(mb_strlen($paragraph)<40)continue;
    $length+=mb_strlen($paragraph);
    $sentences+=preg_match_all('/[.!?](?:\s|$)/u',$paragraph);
    $blocks[]=['type'=>'paragraph','text'=>mb_substr($paragraph,0,300000)];
    if(count($blocks)>=200)break;
   }
   // Summaries (usually 1–2 sentences) must not masquerade as full articles.
   if($length>=450&&$sentences>=3)return $blocks;
  }
 }
 return [];
}
function imp_blocks_are_substantive(array $blocks):bool{
 $textLength=0;$longParagraphs=0;
 foreach($blocks as $block)if(($block['type']??'')==='paragraph'){
  $length=mb_strlen((string)($block['text']??''));
  $textLength+=$length;
  if($length>=70)$longParagraphs++;
 }
 return $textLength>=200&&($longParagraphs>=2||$textLength>=280);
}

/**
 * NewsGraph/Angular hydration source (script id ng-state).
 * Use only the article whose exact slug and title match this page.
 * Never harvest unrelated stories from this large site-wide JSON blob.
 */
function imp_ng_state_article_blocks(DOMXPath $xpath,string $title,string $url):array{
 $script=$xpath->query('//script[@id="ng-state" and translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/json"]')->item(0);
 if(!$script)return [];
 $raw=(string)$script->textContent;
 if($raw===''||strlen($raw)>4194304)return [];
 $state=json_decode($raw,true,128);
 if(!is_array($state))return [];
 $path=trim(rawurldecode((string)(parse_url($url,PHP_URL_PATH)??'')),'/');
 $slug=mb_strtolower((string)basename($path));
 $expectedTitle=mb_strtolower(trim((string)preg_replace('/[^\pL\pN]+/u',' ',$title)));
 if(!$slug||mb_strlen($expectedTitle)<8)return [];
 foreach($state as $entry){
  $data=$entry['b']['data']??null;
  if(!is_array($data)||array_is_list($data)||!isset($data['body'])||!is_array($data['body']))continue;
  $candidateSlug=mb_strtolower(trim((string)($data['slug']??''),'/'));
  if($candidateSlug!==$slug)continue;
  $actualTitle=mb_strtolower(trim((string)preg_replace('/[^\pL\pN]+/u',' ',(string)($data['title']??''))));
  if(!$actualTitle||($expectedTitle!==$actualTitle&&!str_contains($expectedTitle,$actualTitle)&&!str_contains($actualTitle,$expectedTitle)))continue;
  // A publicly embedded blob may describe protected stories too.
  if(!empty($data['isPaywalled'])&&!in_array($data['isPaywalled'],[false,0,'0'],true))continue;
  if(!empty($data['isProtectedContent'])&&!in_array($data['isProtectedContent'],[false,0,'0'],true))continue;
  if(!empty($data['isRestricted'])&&!in_array($data['isRestricted'],[false,0,'0'],true))continue;
  if(!empty($data['canonicalUrl'])){
   $canonical=(string)$data['canonicalUrl'];
   $canonicalPath=trim(rawurldecode((string)(parse_url($canonical,PHP_URL_PATH)??'')),'/');
   if($canonicalPath!==''&&$canonicalPath!==$path)continue;
  }
  $blocks=[];
  foreach(array_slice($data['body'],0,200) as $component){
   if(!is_array($component))continue;
   foreach(array_slice((array)($component['details']??[]),0,30) as $detail){
    if(!is_array($detail)||!is_string($detail['value']??null))continue;
    $html=$detail['value'];
    if($html===''||strlen($html)>300000||!preg_match('~<(?:p|h[2-4]|ul|ol|blockquote)\b~i',$html))continue;
    $doc=new DOMDocument('1.0','UTF-8');
    $before=libxml_use_internal_errors(true);
    $ok=$doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>',LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
    libxml_clear_errors();libxml_use_internal_errors($before);
    if(!$ok)continue;
    $xp=new DOMXPath($doc);
    foreach($xp->query('//p|//h2|//h3|//h4|//ul|//ol|//blockquote') as $node){
     $tag=strtolower($node->nodeName);
     if($tag==='p'&&$xp->evaluate('boolean(ancestor::ul or ancestor::ol or ancestor::blockquote)',$node))continue;
     if(preg_match('/(?:related|recommend|sidebar|most-read|latest-news)/i',($node->parentNode instanceof DOMElement)?$node->parentNode->getAttribute('class'):''))continue;
     $text=trim((string)preg_replace('/\s+/u',' ',$node->textContent));
     if($text===''||mb_strlen($text)>300000)continue;
     $type=match($tag){'h2','h3','h4'=>'heading','blockquote'=>'quote','ul','ol'=>'list',default=>'paragraph'};
     if($type==='paragraph'&&mb_strlen($text)<20)continue;
     $blocks[]=['type'=>$type,'text'=>$text];
     if(count($blocks)>=200)break 3;
    }
   }
  }
  if(imp_blocks_are_substantive($blocks))return $blocks;
 }
 return [];
}
/**
 * Choose the narrative article body, not a card/related-news <article>.
 * Fail closed for thin pages rather than creating a misleading "successful" draft.
 */
function imp_body_stats(DOMXPath $xpath,DOMNode $node):array{
 $total=0;$long=0;$longest=0;
 foreach($xpath->query('.//p',$node) as $p){
  $text=trim((string)preg_replace('/\s+/u',' ',$p->textContent));
  $length=mb_strlen($text);
  if($length<45)continue;
  $total+=$length;$longest=max($longest,$length);
  if($length>=70)$long++;
 }
 return ['total'=>$total,'long'=>$long,'longest'=>$longest];
}
function imp_select_article_body(DOMXPath $xpath):?DOMNode{
 $best=null;$bestScore=-1;
 foreach($xpath->query('//*[@class or @id or @itemprop] | //article | //main') as $node){
  if(!$node instanceof DOMElement)continue;
  $tag=strtolower($node->tagName);
  $attrs=strtolower($node->getAttribute('class').' '.$node->getAttribute('id'));
  $semantic=strtolower($node->getAttribute('itemprop'))==='articlebody';
  $content=(bool)preg_match('/(?:article|post|entry|story|content|cikk)[\s_-]*(?:body|content|text|szoveg)|(?:body|content)[\s_-]*(?:article|story)/i',$attrs);
  if(!$semantic&&!$content&&!in_array($tag,['article','main'],true))continue;
  $stats=imp_body_stats($xpath,$node);
  if($stats['total']<200||($stats['long']<2&&$stats['longest']<280))continue;
  $score=($semantic?300000:0)+($content?200000:0)+($tag==='article'?100000:0)+min(50000,$stats['total']);
  if($score>$bestScore){$best=$node;$bestScore=$score;}
 }
 return $best;
}
function imp_extract(string $html,string $url):array{
 if(!extension_loaded('dom'))throw new RuntimeException('A DOM PHP-kiegészítő szükséges.');
 $doc=new DOMDocument('1.0','UTF-8');
 $before=libxml_use_internal_errors(true);
 $doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOWARNING|LIBXML_NOERROR);
 libxml_clear_errors();libxml_use_internal_errors($before);
 $xpath=new DOMXPath($doc);
 $featuredImage=imp_rich_meta_image($xpath,$url);
 $head=(string)$xpath->evaluate('string(//meta[@property="og:title"]/@content)');
 $title=trim($head?:$xpath->evaluate('string(//article//h1[1])')?:$xpath->evaluate('string(//h1[1])')?:$xpath->evaluate('string(//title)'));
 $lead=trim((string)$xpath->evaluate('string(//meta[@name="description"]/@content)'));
 // JSON-LD is gathered before the sanitizer removes script elements.
 $structuredBody=imp_jsonld_article_blocks($xpath,$title);
 $hydratedBody=imp_ng_state_article_blocks($xpath,$title,$url);
 $richRecord=imp_rich_ng_data($xpath,$title,$url);
 $richSource=$richRecord?imp_rich_ng_html($richRecord):'';
 // Remove navigation, recommendations and commerce widgets before scoring body candidates.
 $discard=[];
 foreach($xpath->query('//script|//style|//noscript|//iframe|//nav|//footer|//aside|//form|//*[@class or @id]') as $node){
  if(!$node instanceof DOMElement)continue;
  $tag=strtolower($node->tagName);
  $attrs=strtolower($node->getAttribute('class').' '.$node->getAttribute('id'));
  if(in_array($tag,['script','style','noscript','nav','footer','aside','form'],true)||
     preg_match('/(?:^|[\s_-])(?:related|recommended|recommendations|more-news|more-articles|latest-news|most-read|read-more|also-read|sidebar|newsletter|social-share|share-buttons|comments|advertisement|ad-slot|sticky-ad|cookie-banner)(?:$|[\s_-])/i',$attrs))$discard[]=$node;
 }
 foreach($discard as $node)if($node->parentNode)$node->parentNode->removeChild($node);
 $article=imp_select_article_body($xpath);
 if((!$article&&!$structuredBody&&!$hydratedBody)||mb_strlen($title)<3)throw new ImportContentRejected('Nem található önálló, ellenőrizhető cikktörzs: a forrásoldalon csak lead, ajánlók vagy hiányos tartalom látható.');
 $blocks=[];$seen=[];
 $nodes=$article?$xpath->query('.//h2|.//h3|.//h4|.//p|.//ul|.//ol|.//blockquote|.//img',$article):[];
 foreach($nodes as $node){
  $tag=strtolower($node->nodeName);
  if(in_array($tag,['p','img'],true)&&$xpath->evaluate('boolean(ancestor::ul or ancestor::ol or ancestor::blockquote)',$node))continue;
  $text=trim(preg_replace('/\s+/u',' ',$node->textContent));
  if(in_array($tag,['h2','h3','h4'],true)&&preg_match('/^(?:További .*híreink|Kapcsolódó cikkek|Ajánlott (?:cikkek|tartalmak)|Legfrissebb hírek|Olvassa el (?:ezeket is|még))/iu',$text))break;
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
 if($hydratedBody){$blocks=$hydratedBody;$article=null;}
 elseif(!imp_blocks_are_substantive($blocks)&&$structuredBody){$blocks=$structuredBody;$article=null;}
 if(!imp_blocks_are_substantive($blocks))
  throw new ImportContentRejected('Hiányos cikktörzs: nem található elég forrásból ellenőrizhető, önálló cikkbekezdés; csak cím és lead nem importálható teljes cikként.');
 $richHtml=$richSource!==''?$richSource:($article?$doc->saveHTML($article):'');
 $richDelta=imp_rich_delta($richHtml,$url);
 // Metadata-only sources lack a full structured HTML body. Preserve the verified text.
 if(!$richDelta['ops']&&$structuredBody){
  foreach($structuredBody as $b)$richDelta['ops'][]=['insert'=>$b['text']."\n"];
 }
 $links=[];$sourceHost=strtolower((string)parse_url($url,PHP_URL_HOST));
 if($article)foreach($xpath->query('.//a[@href]',$article) as $linkNode){
  $raw=trim($linkNode->getAttribute('href'));
  if(!$raw||str_starts_with($raw,'#'))continue;
  $href=imp_resolve_url($raw,$url);
  try{$href=imp_url_metadata($href);}catch(Throwable $e){continue;}
  $links[$href]=['url'=>$href,'internal'=>strtolower((string)parse_url($href,PHP_URL_HOST))===$sourceHost];
  if(count($links)>=500)break;
 }
 $date=trim((string)$xpath->evaluate('string(//meta[@property="article:published_time"]/@content)'));
 return ['title'=>mb_substr($title,0,500),'lead'=>mb_substr($lead,0,10000),'blocks'=>$blocks,'richtext'=>$richDelta,'featured_image'=>$featuredImage,'links'=>array_values($links),'source_url'=>$url,'published_at'=>$date];
}
function imp_discover(string $xml,string $base,int $max=100):array{
 if(!class_exists(XMLReader::class))throw new RuntimeException('Nagy sitemap importjához a PHP XMLReader bővítmény szükséges.');
 // XMLReader walks one node at a time. DOMDocument would multiply memory use
 // on large 20–50 MB sitemap files, even when only the first 1000 URLs are needed.
 $reader=new XMLReader();
 $old=libxml_use_internal_errors(true);
 try{
  if(!$reader->XML($xml,null,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new RuntimeException('Érvénytelen RSS/sitemap XML.');
  $root='';
  while($reader->read()){
   if($reader->nodeType===XMLReader::ELEMENT){$root=strtolower($reader->localName);break;}
  }
  if(!in_array($root,['rss','feed','urlset','sitemapindex'],true))throw new RuntimeException('Az URL nem támogatott RSS/Atom/sitemap dokumentum.');
  $urls=[];$indexDates=[];$indexPositions=[];$mostRecentUrl=null;
  while($reader->read()&&count($urls)<$max){
   if($reader->nodeType!==XMLReader::ELEMENT)continue;
   $node=strtolower($reader->localName);
   if($root==='sitemapindex'&&$node==='lastmod'&&$reader->depth===2&&$mostRecentUrl!==null){
    $indexDates[$mostRecentUrl]=trim($reader->readString());
    continue;
   }
   // Read only the direct <loc> child of <url> or <sitemap>.
   // Nested video:image <loc> entries must not become article URLs.
   if(($root==='urlset'||$root==='sitemapindex')&&$node==='loc'&&$reader->depth===2){
    $raw=$reader->readString();
   }elseif($root==='rss'&&$node==='link'&&$reader->depth===3){
    $raw=$reader->readString();
   }elseif($root==='feed'&&$node==='link'&&$reader->depth===2){
    $raw=(string)$reader->getAttribute('href');
   }else continue;
   $raw=trim($raw);
   if($raw==='')continue;
   $link=imp_resolve_url($raw,$base);
   try{$link=imp_url_metadata($link);}catch(Throwable $e){continue;}
   if(!isset($urls[$link]))$indexPositions[$link]=count($urls);
   $urls[$link]=$link;
   if($root==='sitemapindex')$mostRecentUrl=$link;
  }
  if($root==='sitemapindex'){
   $ordered=array_keys($urls);
   usort($ordered,static function(string $a,string $b)use($indexDates,$indexPositions):int{
    $byDate=imp_sitemap_priority($b,$indexDates[$b]??'')<=>imp_sitemap_priority($a,$indexDates[$a]??'');
    return $byDate?:($indexPositions[$a]<=>$indexPositions[$b]);
   });
   $urls=array_combine($ordered,$ordered);
  }
  foreach(libxml_get_errors() as $error)if($error->level>=LIBXML_ERR_ERROR)throw new RuntimeException('Érvénytelen RSS/sitemap XML.');
  return ['urls'=>array_values($urls),'index'=>$root==='sitemapindex'];
 }finally{
  $reader->close();
  libxml_clear_errors();
  libxml_use_internal_errors($old);
 }
}
