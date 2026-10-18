<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layouts.php';
require_once __DIR__.'/app/rich-content.php';
require_once __DIR__.'/app/site-design-templates.php';
require_once __DIR__.'/app/native-site-chrome.php';
require_once __DIR__.'/app/ad-placements.php';
require_once __DIR__.'/app/ad-external-placements.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Robots-Tag: noindex, nofollow');
$pdo=db();
$siteSlug=trim((string)($_GET['site']??''));
if(!$siteSlug||strlen($siteSlug)>100){http_response_code(400);exit('Hiányzó webhely.');}
$q=$pdo->prepare('SELECT id,name,slug,domain,settings_json FROM sites WHERE slug=? LIMIT 1');$q->execute([$siteSlug]);$site=$q->fetch();
if(!$site){http_response_code(404);exit('Nem található a webhely.');}
$siteId=(int)$site['id'];$settings=cms_site_settings($site);
$appearance=cms_design_template_for_site($site);
$appearanceDesign=is_array($appearance['site_design']??null)?$appearance['site_design']:[];
$appearanceHost=strtolower((string)($appearance['host']??''));
$appearanceLogo=cms_rich_url((string)($appearanceDesign['logo_url']??''));
if(in_array($appearanceHost,['magyarnemzet.hu','www.magyarnemzet.hu'],true))$appearanceLogo='/assets/source-logos/magyarnemzet.svg';
$effectiveLogo=(string)$settings['logo_url']?:$appearanceLogo;
$appearanceCss=(string)($appearance['css']??'');
// Only allowlisted declarations collected by our importer are used here.
if(preg_match('~[<>]|@import|url\\s*\\(~i',$appearanceCss))$appearanceCss='';
$preview=($_GET['preview']??'')==='1';
if($preview){
 require __DIR__.'/app/site-access.php';
 $u=require_user();
 if(!in_array($u['role'],['platform_admin','site_admin','editor'],true)||!in_array($siteId,allowed_primary_sites($pdo,$u),true)){http_response_code(403);exit('Nincs előnézeti jogosultság.');}
}
$searchQuery=is_string($_GET['q']??null)?trim((string)$_GET['q']):'';
if(mb_strlen($searchQuery)>100){http_response_code(400);exit('A keresőkifejezés túl hosszú.');}
$searchMode=$searchQuery!=='';
$searchResults=$searchMode?cms_native_search($pdo,$siteId,$searchQuery,$preview):[];
$articleSlug=$searchMode?'':trim((string)($_GET['article']??''));
$sectionSlug=trim((string)($_GET['section']??''));
$tagSlug=trim((string)($_GET['tag']??''));
$pageSlug=trim((string)($_GET['page']??''));
$readerLogin=($_GET['login']??'')==='1';
foreach([$sectionSlug,$tagSlug,$pageSlug] as $requestedSlug)if($requestedSlug!==''&&!preg_match('/^[a-z0-9][a-z0-9-]{0,188}$/D',$requestedSlug)){http_response_code(400);exit('Érvénytelen oldalazonosító.');}
$kind=$articleSlug!==''?'article':($pageSlug!==''?'page':($readerLogin?'login':($tagSlug!==''?'tag':($sectionSlug!==''?'section':'home'))));
$termSlug=$kind==='tag'?$tagSlug:$sectionSlug;
$termType=$kind==='tag'?'tag':'category';
$term=null;$staticPage=null;
if(in_array($kind,['section','tag'],true)&&$termSlug!==''){
 $t=$pdo->prepare('SELECT t.id,t.name,t.slug,m.description FROM taxonomy_terms t LEFT JOIN taxonomy_term_meta m ON m.term_id=t.id WHERE t.type=? AND t.slug=? AND t.site_id IN (0,?) ORDER BY t.site_id DESC LIMIT 1');
 $t->execute([$termType,$termSlug,$siteId]);$term=$t->fetch();
 if(!$term){http_response_code(404);exit('A rovat vagy címke nem található.');}
}
if($kind==='page'&&$pageSlug!==''){
 $t=$pdo->prepare('SELECT title,body_html,status FROM site_pages WHERE site_id=? AND slug=? LIMIT 1');
 $t->execute([$siteId,$pageSlug]);$staticPage=$t->fetch();
 if(!$staticPage||(!$preview&&$staticPage['status']!=='published')){http_response_code(404);exit('A statikus oldal nem érhető el.');}
}
if($preview&&isset($_GET['template'])&&in_array($_GET['template'],['home','section','tag','article','page','login'],true))$kind=$_GET['template'];
$article=null;
$articleScope='(a.site_id=? OR EXISTS(SELECT 1 FROM article_sites x WHERE x.article_id=a.id AND x.site_id=?))';
$publication=$preview?'':" AND a.status='published' AND (a.published_at IS NULL OR a.published_at<=NOW())";
if($articleSlug!==''){
 $q=$pdo->prepare('SELECT a.*,u.name author_name,m.seo_title,m.seo_description,m.canonical_url,m.og_image_url FROM articles a LEFT JOIN users u ON u.id=a.author_id LEFT JOIN article_meta m ON m.article_id=a.id WHERE '.$articleScope.' AND a.slug=?'.$publication.' LIMIT 1');
 $q->execute([$siteId,$siteId,$articleSlug]);$article=$q->fetch();
 if(!$article){http_response_code(404);exit('A cikk nem érhető el.');}
}
$template=null;$menu=[];
try{
 $q=$pdo->prepare('SELECT draft_json,published_json FROM site_templates WHERE site_id=? AND template_key=?');$q->execute([$siteId,$kind]);$template=$q->fetch();
 $menu=cms_native_site_menu($pdo,$siteId);
}catch(PDOException $e){
 error_log('frontend layout fallback: '.$e->getMessage());
}
$layout=$template?json_decode((string)($preview?$template['draft_json']:$template['published_json']),true):null;
if(!is_array($layout)||!$layout)$layout=cms_default_layout($kind);
if($kind==='home'&&$appearance&&!array_filter($layout,static fn($b)=>is_array($b)&&($b['type']??'')==='latest')){
 $layout[]=['type'=>'latest','title'=>'Legfrissebb cikkek','limit'=>18,'columns'=>3];
}
if(!$menu)$menu=[['label'=>'Nyitóoldal','url'=>'/']];
function pub_h(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pub_href(string $url,string $slug):string{
 $base='/public.php?site='.rawurlencode($slug);
 if($url==='/')return $base;
 if(preg_match('~^/section/([a-z0-9_-]+)$~i',$url,$m))return $base.'&section='.rawurlencode($m[1]);
 if(preg_match('~^/article/([a-z0-9_-]+)$~i',$url,$m))return $base.'&article='.rawurlencode($m[1]);
 return $url;
}
function pub_article_list(PDO $pdo,int $siteId,string $slug,int $limit,string $section,bool $preview,string $termType='category',int $offset=0):array{
 $scope='(a.site_id=? OR EXISTS(SELECT 1 FROM article_sites link WHERE link.article_id=a.id AND link.site_id=?))';
 $filter=$preview?'':" AND a.status='published' AND (a.published_at IS NULL OR a.published_at<=NOW())";
 if($section!=='')$filter.=" AND EXISTS(SELECT 1 FROM article_terms at JOIN taxonomy_terms tt ON tt.id=at.term_id WHERE at.article_id=a.id AND tt.type=? AND tt.slug=? AND tt.site_id IN (0,?))";
 $sql='SELECT a.id,a.title,a.slug,a.`lead`,a.status,a.published_at,a.updated_at,m.og_image_url FROM articles a LEFT JOIN article_meta m ON m.article_id=a.id WHERE '.$scope.$filter.' ORDER BY COALESCE(a.published_at,a.updated_at) DESC,a.id DESC LIMIT '.max(1,min(30,$limit)).' OFFSET '.max(0,min(150,$offset));
 $q=$pdo->prepare($sql);$q->execute($section!==''?[$siteId,$siteId,$termType,$section,$siteId]:[$siteId,$siteId]);return $q->fetchAll();
}
function pub_blocks(string $json):void{
 $blocks=json_decode($json,true);
 if(!is_array($blocks))return;
 foreach($blocks as $block){
  $type=(string)($block['type']??'');$text=(string)($block['text']??'');
  if($type==='richhtml'){
   // Stored markup is sanitized at write time and again on public render.
   $safe=cms_rich_sanitize_html($text);
   if($safe!=='')echo '<div class="pub-rich-content">'.$safe.'</div>';
   continue;
  }
  if($type==='richtext'){
   $delta=json_decode($text,true);if(!is_array($delta))continue;
   echo '<div class="pub-article-rich">';
   foreach($delta['ops']??[] as $op){
    if(is_string($op['insert']??null))echo nl2br(pub_h($op['insert']));
    elseif(is_array($op['insert']??null)&&isset($op['insert']['image'])){
     $src=(string)$op['insert']['image'];if(filter_var($src,FILTER_VALIDATE_URL)&&in_array(parse_url($src,PHP_URL_SCHEME),['http','https'],true))echo '<img loading="lazy" alt="" src="'.pub_h($src).'">';
    }
   }
   echo '</div>';continue;
  }
  if($type==='image'&&filter_var($text,FILTER_VALIDATE_URL)&&in_array(parse_url($text,PHP_URL_SCHEME),['http','https'],true))echo '<figure><img loading="lazy" src="'.pub_h($text).'" alt=""></figure>';
  elseif($type==='heading')echo '<h2>'.pub_h($text).'</h2>';
  elseif($type==='quote')echo '<blockquote>'.pub_h($text).'</blockquote>';
  elseif($type==='list')echo '<p class="pub-list">'.pub_h($text).'</p>';
  elseif($type==='embed'&&filter_var($text,FILTER_VALIDATE_URL))echo '<p><a href="'.pub_h($text).'" rel="noopener noreferrer" target="_blank">Beágyazott tartalom megnyitása ↗</a></p>';
  elseif($type==='paragraph')echo '<p>'.pub_h($text).'</p>';
 }
}
$themeColor=preg_match('/^#[0-9a-f]{6}$/i',$settings['accent_color'])?$settings['accent_color']:'#325de6';
$title=$searchMode?('Keresés: '.$searchQuery.' | '.$site['name']):($article?($article['title'].' | '.$site['name']):(($staticPage['title']??$term['name']??($readerLogin?'Bejelentkezés':$site['name'])).' | '.$site['name']));
$staffUser=user();
$staffHref=!empty($settings['reader_accounts_enabled'])?cms_native_site_url($siteSlug,$preview).'&login=1':($preview&&$staffUser?'/profile.php':'');
$staffLabel=!empty($settings['reader_accounts_enabled'])?'Olvasói fiók':'Szerkesztőségi profil';
$adPlacements=[];
if(!$preview&&!empty($settings['ad_slots_enabled'])){
 try{$adPlacements=cms_ad_placements($pdo,$siteId,$kind);}
 catch(PDOException $e){error_log('publisher ad positions: '.$e->getMessage());}
}
$externalSlots=['slots'=>[],'groups'=>[]];
if(!empty($settings['ad_slots_enabled'])){
 // Authenticated editor previews can resolve positions without serving them automatically.
 try{$externalSlots=cms_external_page_slots($pdo,$siteId,$layout,true);}
 catch(PDOException $e){error_log('publisher external ad lookup: '.$e->getMessage());}
}
// Keep third-party permissions off pages without a VALID active external slot.
// All external ad loading still requires an explicit CMP callback.
$adProviderCsp=(!$preview&&$externalSlots['groups'])?' https://*.adocean.pl':'';
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; script-src 'self'".$adProviderCsp."; connect-src 'self'".$adProviderCsp."; font-src https://fonts.gstatic.com; img-src 'self' https: http: data:; frame-src 'self'".$adProviderCsp." https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://open.spotify.com https://embed.spotify.com https://w.soundcloud.com https://www.facebook.com https://facebook.com https://www.instagram.com https://instagram.com https://www.tiktok.com https://player.simplecast.com https://embed.podcasts.apple.com https://www.podbean.com; media-src https: http:; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");
$trackingConfig=['site_id'=>$siteId,'slug'=>$siteSlug,'kind'=>$kind,'content'=>$articleSlug?:($pageSlug?:($tagSlug?:($sectionSlug?:'home')))];
$positionConfig=['site_id'=>$siteId,'section'=>$sectionSlug,'positions'=>$adPlacements];
$jsonFlags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT;
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pub_h($title)?></title><meta name="robots" content="noindex,nofollow"><meta name="description" content="<?=pub_h($article['seo_description']??$settings['site_description'])?>">
<link rel="stylesheet" href="/assets/public.css"><link rel="stylesheet" href="/assets/native-chrome.css?v=1"><link rel="stylesheet" href="/assets/site-page-families.css?v=2"><?php if(in_array($appearanceHost,['magyarnemzet.hu','www.magyarnemzet.hu'],true)):?><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700;800&display=swap"><?php endif;?><?php if($appearance):?><link rel="stylesheet" href="/assets/site-appearance.css"><?php endif;?><style>:root{--pub-accent:<?=pub_h($themeColor)?>}</style><?php if($appearanceCss!==''):?><style id="assigned-appearance-css"><?=$appearanceCss?></style><?php endif;?><?php if(!$preview):?><script src="/assets/page-analytics.js?v=1" defer></script><?php endif;?><?php if($adPlacements):?><link rel="stylesheet" href="/assets/ad-placements.css?v=1"><script src="/assets/ad-placements.js?v=1" defer></script><?php endif;?><?php if($externalSlots['groups']&&!$preview):?><script src="/assets/ad-external.js?v=2" defer></script><script src="/assets/ad-external-page.js?v=1" defer></script><?php endif;?><?php if($preview&&$externalSlots['groups']):?><script src="/assets/ad-external-preview.js?v=2" defer></script><?php endif;?></head><body class="<?=$appearance?'pub-source-themed':''?><?=in_array($appearanceHost,['magyarnemzet.hu','www.magyarnemzet.hu'],true)?' pub-source-mno':''?> pub-kind-<?=pub_h($kind)?>">
<?php if($preview):?><div class="pub-preview-bar">Szerkesztői előnézet · Nem nyilvános kiadás <a href="/builder.php?site_id=<?=$siteId?>&amp;template=<?=pub_h($kind)?>">Vissza az oldalépítőhöz</a></div><?php endif;?>
<header class="pub-header <?=$appearance?'cms-source-header':''?>">
<div class="pub-header-inner <?=$appearance?'pub-native-header':''?>">
<details class="pub-native-search"><summary title="Keresés" aria-label="Keresés megnyitása"><svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg></summary>
<form action="/public.php" method="get" role="search"><input type="hidden" name="site" value="<?=pub_h($siteSlug)?>"><?php if($preview):?><input type="hidden" name="preview" value="1"><?php endif;?><label for="pub-native-query">Keresés a <?=pub_h($site['name'])?> cikkeiben</label><div class="pub-native-search-fields"><input id="pub-native-query" type="search" name="q" required minlength="2" maxlength="100" value="<?=pub_h($searchQuery)?>" placeholder="Keresés a cikkek között…"><button type="submit">Keresés</button></div></form></details>
<a class="pub-brand" href="<?=pub_h(cms_native_site_url($siteSlug,$preview))?>"><?php if($effectiveLogo):?><img src="<?=pub_h($effectiveLogo)?>" alt="<?=pub_h($site['name'])?>"><?php else:?><?=pub_h($site['name'])?><?php endif;?></a>
<div class="pub-native-tools">
<?php if($staffHref!==''):?><a class="pub-native-account" href="<?=pub_h($staffHref)?>" title="<?=pub_h($staffLabel)?>" aria-label="<?=pub_h($staffLabel)?>"><svg aria-hidden="true" width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/></svg></a><?php endif;?>
<details class="pub-native-menu"><summary title="Menü" aria-label="Menü megnyitása"><svg aria-hidden="true" width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg></summary>
<nav aria-label="Webhely főmenüje"><?php foreach($menu as $entry):?><a href="<?=pub_h(cms_native_href($entry['url'],$siteSlug,$preview))?>"><?=pub_h($entry['label'])?></a><?php endforeach;?></nav></details>
</div></div>
<?php if($menu):?><nav class="pub-native-topics" aria-label="Kiemelt menüpontok"><div><?php foreach(array_slice($menu,0,8) as $entry):?><a href="<?=pub_h(cms_native_href($entry['url'],$siteSlug,$preview))?>"><?=pub_h($entry['label'])?></a><?php endforeach;?></div></nav><?php endif;?>
</header>
<main id="content" class="pub-main">
<?php if($searchMode):?><section class="pub-search-results pub-section" aria-label="Keresési találatok">
<p class="pub-eyebrow"><?=pub_h($site['name'])?> · Keresés</p><h1>Találatok erre: „<?=pub_h($searchQuery)?>”</h1>
<p class="pub-search-result-count"><?=count($searchResults)?> találat<?=count($searchResults)===24?' az első 24 találatból':''?></p>
<?php if($searchResults):?><div class="pub-search-result-list"><?php foreach($searchResults as $result):?><a class="pub-search-result" href="<?=pub_h(cms_native_site_url($siteSlug,$preview).'&article='.rawurlencode($result['slug']))?>"><?php if(!empty($result['og_image_url'])):?><img src="<?=pub_h($result['og_image_url'])?>" loading="lazy" alt=""><?php endif;?><span><strong><?=pub_h($result['title'])?></strong><small><?=pub_h(mb_substr((string)($result['lead']??''),0,220))?></small></span></a><?php endforeach;?></div>
<?php else:?><p>Nem található a keresésnek megfelelő cikk. Próbálj másik kifejezést!</p><?php endif;?></section>
<?php else:?>
<?php foreach($layout as $layoutIndex=>$block):$type=$block['type']??'';$sectionTitle=trim((string)($block['title']??''));$count=max(1,min(30,(int)($block['limit']??10)));$columns=max(1,min(4,(int)($block['columns']??3)));$offset=max(0,min(150,(int)($block['offset']??0)));?>
<?php if($type==='hero'):?><section class="pub-hero"><div class="pub-hero-inner"><p class="pub-eyebrow"><?=pub_h($site['name'])?></p><h1><?=pub_h($article['title']??($staticPage['title']??$term['name']??$sectionTitle?:$site['name']))?></h1><?php if(!empty($block['text'])):?><p><?=pub_h($block['text'])?></p><?php endif;?></div></section>
<?php elseif($type==='text'):?><section class="pub-section pub-text"><?php if($sectionTitle):?><h2><?=pub_h($sectionTitle)?></h2><?php endif;?><p><?=nl2br(pub_h((string)($block['text']??'')))?></p></section>
<?php elseif($type==='image'&&!empty($block['image_url'])):?><section class="pub-section pub-image"><?php if($sectionTitle):?><h2><?=pub_h($sectionTitle)?></h2><?php endif;?><img src="<?=pub_h((string)$block['image_url'])?>" loading="lazy" alt="<?=pub_h($sectionTitle)?>"></section>
<?php elseif($type==='article'):?>
<section class="pub-article pub-section <?=$appearance?'cms-source-article':''?>"><?php if($article):?><?php if(!empty($article['og_image_url'])):?><img class="pub-cover" loading="eager" src="<?=pub_h($article['og_image_url'])?>" alt=""><?php endif;?>
<p class="pub-eyebrow"><?=pub_h($article['author_name']??'Szerkesztőség')?> · <?=pub_h($article['published_at']??'Piszkozat')?></p>
<h1><?=pub_h($article['title'])?></h1><?php if(!empty($article['lead'])):?><p class="pub-lead"><?=pub_h($article['lead'])?></p><?php endif;?><?php pub_blocks((string)$article['content_json']);?>
<?php else:?><h2>Cikkelőnézet</h2><p>Válassz egy cikket a nyitóoldalról az éles cikksablon megtekintéséhez.</p><?php endif;?></section>
<?php elseif($type==='latest'):?>
<section class="pub-section"><h2><?=pub_h($sectionTitle?:'Legfrissebb cikkek')?></h2>
<?php $listedArticles=pub_article_list($pdo,$siteId,$siteSlug,$count,in_array($kind,['section','tag'],true)?$termSlug:'',$preview,$termType,$offset);?>
<?php if(!$listedArticles):?><div class="pub-no-articles"><p><?=$preview?'Még nincs ehhez a webhelyhez rendelt cikk.':'Még nincsenek publikált cikkek ezen a webhelyen.'?></p>
<?php if($preview):?><p>A piszkozatok is megjelennek, ha ehhez a webhelyhez vannak rendelve.</p><a href="/sites.php?edit=<?=$siteId?>">Korábbi importált cikkek hozzárendelése ↗</a><?php endif;?>
</div><?php endif;?>
<div class="pub-articles" style="--pub-cols:<?=$columns?>"><?php foreach($listedArticles as $item):?>
<a class="pub-story" href="/public.php?site=<?=pub_h($siteSlug)?>&amp;article=<?=pub_h($item['slug'])?><?=$preview?'&amp;preview=1':''?>">
<?php if(!empty($item['og_image_url'])):?><img src="<?=pub_h($item['og_image_url'])?>" alt="" loading="lazy"><?php endif;?>
<span class="pub-story-body"><small><?=pub_h($item['published_at']??'')?></small><strong><?=pub_h($item['title'])?></strong><span><?=pub_h(mb_substr((string)$item['lead'],0,170))?></span></span></a>
<?php endforeach;?></div></section>
<?php elseif($type==='page_body'):?><section class="pub-section pub-static-page">
<h1><?=pub_h((string)($staticPage['title']??'Statikus oldal'))?></h1>
<?php if($staticPage):?><div class="pub-rich-content"><?=cms_rich_sanitize_html((string)$staticPage['body_html'])?></div><?php elseif($preview):?><p>Az Oldalak adminban hozhatsz létre és szerkeszthetsz statikus oldalakat.</p><?php endif;?></section>
<?php elseif($type==='reader_form'):?><section class="pub-section pub-reader-login">
<h1>Bejelentkezés</h1>
<?php if(empty($settings['reader_accounts_enabled'])):?><p>Az olvasói fiókok még nincsenek engedélyezve ezen a webhelyen.</p>
<?php elseif(!empty($_SESSION['reader_site_id'])&&(int)$_SESSION['reader_site_id']===$siteId):?><p>Be vagy jelentkezve: <?=pub_h((string)($_SESSION['reader_name']??'Olvasó'))?>.</p>
<form action="/reader-auth.php" method="post"><input type="hidden" name="csrf" value="<?=pub_h(csrf())?>"><input type="hidden" name="site" value="<?=pub_h($siteSlug)?>"><input type="hidden" name="action" value="logout"><button type="submit">Kijelentkezés</button></form>
<?php else:?><p>A regisztráció és a belépés a <?=pub_h($site['name'])?> saját olvasói rendszerében működik.</p>
<?php if(isset($_GET['auth_error'])):?><p role="alert" class="pub-auth-error">Nem sikerült a művelet. Ellenőrizd az adatokat, vagy próbáld újra később.</p><?php endif;?>
<form method="post" action="/reader-auth.php" class="pub-reader-form"><input type="hidden" name="csrf" value="<?=pub_h(csrf())?>"><input type="hidden" name="site" value="<?=pub_h($siteSlug)?>"><input type="hidden" name="action" value="login">
<label>E-mail-cím<input name="email" type="email" autocomplete="username" maxlength="190" required></label><label>Jelszó<input name="password" type="password" autocomplete="current-password" required></label><button type="submit">Bejelentkezés</button></form>
<details><summary>Új olvasói fiók létrehozása</summary><form method="post" action="/reader-auth.php" class="pub-reader-form"><input type="hidden" name="csrf" value="<?=pub_h(csrf())?>"><input type="hidden" name="site" value="<?=pub_h($siteSlug)?>"><input type="hidden" name="action" value="register"><label>Név<input name="display_name" maxlength="190" required></label><label>E-mail-cím<input name="email" type="email" autocomplete="email" required></label><label>Jelszó (minimum 12 karakter)<input name="password" type="password" autocomplete="new-password" minlength="12" required></label><button type="submit">Regisztráció</button></form></details>
<?php endif;?></section>
<?php elseif($type==='adocean'&&($preview||isset($externalSlots['slots'][$layoutIndex]))):?>
<section class="pub-section pub-ad pub-adocean" aria-label="Hirdetési pozíció"><p>Hirdetés</p>
<?php if($preview):?>
<div class="pub-adocean-preview">AdOcean pozíció: LEAF #<?=(int)($block['external_leaf_id']??0)?>, MASTER #<?=(int)($block['external_master_id']??0)?>.</div>
<?php if(isset($externalSlots['slots'][$layoutIndex])):?>
<?php $adPreviewArgs=['site_id'=>$siteId,'external_import_id'=>(int)($block['external_import_id']??0),'external_folder_id'=>(int)($block['external_folder_id']??0),'external_master_id'=>(int)($block['external_master_id']??0),'external_leaf_id'=>(int)($block['external_leaf_id']??0)];?>
<div class="pub-adocean-test-controls" data-ao-preview-control>
<label><input type="checkbox" data-ao-preview-consent> Engedélyezem a külső AdOcean-tesztkérés indítását ebben a szerkesztői előnézetben. A szolgáltató cookie-kat és más követő technikákat használhat.</label>
<button type="button" data-ao-preview-load data-preview-url="/ad-external-preview.php?<?=pub_h(http_build_query($adPreviewArgs))?>" disabled>Valódi AdOcean-hirdetés megjelenítése</button>
<div data-ao-preview-status role="status" aria-live="polite">A teszt csak külön engedélyezés után indít hirdetési kérést.</div>
<div data-ao-preview-frame></div>
</div>
<?php else:?><div class="pub-adocean-preview-error" role="status">A webhely hirdetési funkciója, a forrás-hozzárendelés vagy a pozíció inaktív. Ellenőrizd a Webhelyfunkciókat és az AdOcean importot.</div><?php endif;?>
<?php else:?><div class="pub-adocean-container" id="<?=pub_h($externalSlots['slots'][$layoutIndex])?>"></div><?php endif;?></section>
<?php elseif($type==='ad'&&!empty($block['zone'])&&!empty($settings['ad_slots_enabled'])):?><section class="pub-section pub-ad"><p>Hirdetés</p><?php if($preview):?><p class="pub-preview-ad">Hirdetési pozíció: <?=pub_h((string)$block['zone'])?> (előnézet, mérés nélkül)</p><?php else:?><iframe title="Hirdetés" loading="lazy" referrerpolicy="no-referrer" src="/ad-slot.php?site_id=<?=$siteId?>&amp;zone=<?=rawurlencode($block['zone'])?><?=$sectionSlug?'&amp;section='.rawurlencode($sectionSlug):''?>"></iframe><?php endif;?></section>
<?php endif;?>
<?php endforeach;?>
<?php endif;?>
</main><footer class="pub-footer <?=$appearance?'cms-source-footer':''?>"><p>© <?=date('Y')?> <?=pub_h($settings['publisher_name']?:$site['name'])?></p>
<?php if($appearance&&is_array($appearanceDesign['footer_links']??null)):?><nav class="pub-template-footer-links" aria-label="Kiadói lábléclinkek">
<?php foreach(array_slice($appearanceDesign['footer_links'],0,15) as $link):if(!is_array($link))continue;$href=cms_rich_url((string)($link['url']??''));if(!$href)continue;?><a target="_blank" rel="noopener noreferrer" href="<?=pub_h($href)?>"><?=pub_h(mb_substr((string)($link['label']??''),0,100))?></a><?php endforeach;?></nav><?php endif;?>
<p>comesy · Content. Media. System.</p></footer>
<?php if(!$preview):?><script id="cms-page-analytics" type="application/json"><?=json_encode($trackingConfig,$jsonFlags)?></script><?php endif;?>
<?php if($adPlacements):?><script id="cms-ad-placements" type="application/json"><?=json_encode($positionConfig,$jsonFlags)?></script><?php endif;?>
<?php if($externalSlots['groups']&&!$preview):?><script id="cms-ad-external" type="application/json"><?=json_encode(['site_id'=>$siteId,'groups'=>$externalSlots['groups']],$jsonFlags)?></script><?php endif;?>
</body></html>