<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layouts.php';
require __DIR__.'/app/rich-content.php';
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; img-src https: http: data:; media-src https: http:; frame-src 'self' https://www.youtube-nocookie.com https://open.spotify.com https://player.vimeo.com https://www.instagram.com https://www.facebook.com https://w.soundcloud.com; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
header('X-Robots-Tag: noindex, nofollow');
$pdo=db();
$siteSlug=trim((string)($_GET['site']??''));
if(!$siteSlug||strlen($siteSlug)>100){http_response_code(400);exit('Hiányzó webhely.');}
$q=$pdo->prepare('SELECT id,name,slug,domain,settings_json FROM sites WHERE slug=? LIMIT 1');$q->execute([$siteSlug]);$site=$q->fetch();
if(!$site){http_response_code(404);exit('Nem található a webhely.');}
$siteId=(int)$site['id'];$settings=cms_site_settings($site);
$preview=($_GET['preview']??'')==='1';
if($preview){
 require __DIR__.'/app/site-access.php';
 $u=require_user();
 if(!in_array($u['role'],['platform_admin','site_admin','editor'],true)||!in_array($siteId,allowed_primary_sites($pdo,$u),true)){http_response_code(403);exit('Nincs előnézeti jogosultság.');}
}
$articleSlug=trim((string)($_GET['article']??''));
$sectionSlug=trim((string)($_GET['section']??''));
$kind=$articleSlug!==''?'article':($sectionSlug!==''?'section':'home');
if($preview&&isset($_GET['template'])&&in_array($_GET['template'],['home','section','article'],true))$kind=$_GET['template'];
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
 $q=$pdo->prepare('SELECT label,url FROM site_menu_items WHERE site_id=? AND active=1 ORDER BY position,id LIMIT 40');$q->execute([$siteId]);$menu=$q->fetchAll();
}catch(PDOException $e){
 error_log('frontend layout fallback: '.$e->getMessage());
}
$layout=$template?json_decode((string)($preview?$template['draft_json']:$template['published_json']),true):null;
if(!is_array($layout))$layout=cms_default_layout($kind);
if(!$menu)$menu=[['label'=>'Nyitóoldal','url'=>'/']];
function pub_h(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function pub_href(string $url,string $slug):string{
 $base='/public.php?site='.rawurlencode($slug);
 if($url==='/')return $base;
 if(preg_match('~^/section/([a-z0-9_-]+)$~i',$url,$m))return $base.'&section='.rawurlencode($m[1]);
 if(preg_match('~^/article/([a-z0-9_-]+)$~i',$url,$m))return $base.'&article='.rawurlencode($m[1]);
 return $url;
}
function pub_article_list(PDO $pdo,int $siteId,string $slug,int $limit,string $section,bool $preview):array{
 $scope='(a.site_id=? OR EXISTS(SELECT 1 FROM article_sites link WHERE link.article_id=a.id AND link.site_id=?))';
 $filter=$preview?'':" AND a.status='published' AND (a.published_at IS NULL OR a.published_at<=NOW())";
 if($section!=='')$filter.=" AND EXISTS(SELECT 1 FROM article_terms at JOIN taxonomy_terms tt ON tt.id=at.term_id WHERE at.article_id=a.id AND tt.type='category' AND tt.slug=? AND tt.site_id IN (0,?))";
 $sql='SELECT a.id,a.title,a.slug,a.`lead`,a.published_at,m.og_image_url FROM articles a LEFT JOIN article_meta m ON m.article_id=a.id WHERE '.$scope.$filter.' ORDER BY a.published_at DESC,a.id DESC LIMIT '.max(1,min(30,$limit));
 $q=$pdo->prepare($sql);$q->execute($section!==''?[$siteId,$siteId,$section,$siteId]:[$siteId,$siteId]);return $q->fetchAll();
}
function pub_blocks(string $json):void{
 $blocks=json_decode($json,true);
 if(!is_array($blocks))return;
 foreach($blocks as $block){
  $type=(string)($block['type']??'');$text=(string)($block['text']??'');
  if($type==='richtext'){
   echo '<div class="pub-article-rich">'.cms_rich_delta($text).'</div>';
   continue;
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
$title=$article?($article['title'].' | '.$site['name']):$site['name'];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=pub_h($title)?></title><meta name="robots" content="noindex,nofollow"><meta name="description" content="<?=pub_h($article['seo_description']??$settings['site_description'])?>">
<link rel="stylesheet" href="/assets/public.css"><style>:root{--pub-accent:<?=pub_h($themeColor)?>}</style></head><body>
<?php if($preview):?><div class="pub-preview-bar">Szerkesztői előnézet · Nem nyilvános kiadás <a href="/builder.php?site_id=<?=$siteId?>&amp;template=<?=pub_h($kind)?>">Vissza az oldalépítőhöz</a></div><?php endif;?>
<header class="pub-header"><div class="pub-header-inner"><a class="pub-brand" href="/public.php?site=<?=pub_h($siteSlug)?>">
<?php if($settings['logo_url']):?><img src="<?=pub_h($settings['logo_url'])?>" alt="<?=pub_h($site['name'])?>"><?php else:?><?=pub_h($site['name'])?><?php endif;?></a>
<nav aria-label="Főmenü"><?php foreach($menu as $entry):?><a href="<?=pub_h(pub_href($entry['url'],$siteSlug))?>"><?=pub_h($entry['label'])?></a><?php endforeach;?></nav></div></header>
<main id="content" class="pub-main">
<?php foreach($layout as $block):$type=$block['type']??'';$sectionTitle=trim((string)($block['title']??''));$count=max(1,min(30,(int)($block['limit']??10)));$columns=max(1,min(4,(int)($block['columns']??3)));?>
<?php if($type==='hero'):?><section class="pub-hero"><div class="pub-hero-inner"><p class="pub-eyebrow"><?=pub_h($site['name'])?></p><h1><?=pub_h($article['title']??($sectionSlug?:($sectionTitle?:$site['name'])))?></h1><?php if(!empty($block['text'])):?><p><?=pub_h($block['text'])?></p><?php endif;?></div></section>
<?php elseif($type==='text'):?><section class="pub-section pub-text"><?php if($sectionTitle):?><h2><?=pub_h($sectionTitle)?></h2><?php endif;?><p><?=nl2br(pub_h((string)($block['text']??'')))?></p></section>
<?php elseif($type==='image'&&!empty($block['image_url'])):?><section class="pub-section pub-image"><?php if($sectionTitle):?><h2><?=pub_h($sectionTitle)?></h2><?php endif;?><img src="<?=pub_h((string)$block['image_url'])?>" loading="lazy" alt="<?=pub_h($sectionTitle)?>"></section>
<?php elseif($type==='article'):?>
<section class="pub-article pub-section"><?php if($article):?><?php if(!empty($article['og_image_url'])):?><img class="pub-cover" loading="eager" src="<?=pub_h($article['og_image_url'])?>" alt=""><?php endif;?>
<p class="pub-eyebrow"><?=pub_h($article['author_name']??'Szerkesztőség')?> · <?=pub_h($article['published_at']??'Piszkozat')?></p>
<h1><?=pub_h($article['title'])?></h1><?php if(!empty($article['lead'])):?><p class="pub-lead"><?=pub_h($article['lead'])?></p><?php endif;?><?php pub_blocks((string)$article['content_json']);?>
<?php else:?><h2>Cikkelőnézet</h2><p>Válassz egy cikket a nyitóoldalról az éles cikksablon megtekintéséhez.</p><?php endif;?></section>
<?php elseif($type==='latest'):?>
<section class="pub-section"><h2><?=pub_h($sectionTitle?:'Legfrissebb cikkek')?></h2>
<div class="pub-articles" style="--pub-cols:<?=$columns?>"><?php foreach(pub_article_list($pdo,$siteId,$siteSlug,$count,$kind==='section'?$sectionSlug:'',$preview) as $item):?>
<a class="pub-story" href="/public.php?site=<?=pub_h($siteSlug)?>&amp;article=<?=pub_h($item['slug'])?><?=$preview?'&amp;preview=1':''?>">
<?php if(!empty($item['og_image_url'])):?><img src="<?=pub_h($item['og_image_url'])?>" alt="" loading="lazy"><?php endif;?>
<span class="pub-story-body"><small><?=pub_h($item['published_at']??'')?></small><strong><?=pub_h($item['title'])?></strong><span><?=pub_h(mb_substr((string)$item['lead'],0,170))?></span></span></a>
<?php endforeach;?></div></section>
<?php elseif($type==='ad'&&!empty($block['zone'])&&!empty($settings['ad_slots_enabled'])):?><section class="pub-section pub-ad"><p>Hirdetés</p><iframe title="Hirdetés" loading="lazy" referrerpolicy="no-referrer" src="/ad-slot.php?site_id=<?=$siteId?>&amp;zone=<?=rawurlencode($block['zone'])?><?=$sectionSlug?'&amp;section='.rawurlencode($sectionSlug):''?>"></iframe></section>
<?php endif;?>
<?php endforeach;?>
</main><footer class="pub-footer"><p>© <?=date('Y')?> <?=pub_h($settings['publisher_name']?:$site['name'])?></p><p>comesy · Content. Media. System.</p></footer></body></html>