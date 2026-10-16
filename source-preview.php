<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require_once __DIR__.'/app/rich-content.php';
require_once __DIR__.'/app/import-source-style.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: http: data:; font-src 'none'; media-src https: http:; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://open.spotify.com https://embed.spotify.com https://w.soundcloud.com https://www.facebook.com https://www.instagram.com https://www.tiktok.com; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
$u=require_user();
$id=max(0,(int)($_GET['id']??0));
if($id===0){http_response_code(404);exit('A cikk nem található.');}
$pdo=db();
$q=$pdo->prepare('SELECT a.*,m.og_image_url FROM articles a LEFT JOIN article_meta m ON m.article_id=a.id WHERE a.id=? LIMIT 1');
$q->execute([$id]);$article=$q->fetch();
if(!$article){http_response_code(404);exit('A cikk nem található.');}
$allowed=in_array($u['role'],['platform_admin','site_admin','editor','author'],true)
 && in_array((int)$article['site_id'],allowed_primary_sites($pdo,$u),true)
 && ($u['role']!=='author'||(int)$article['author_id']===(int)$u['id']);
if(!$allowed){http_response_code(403);exit('Nincs előnézeti jogosultság.');}
$source='';$profile=null;
try{
 $q=$pdo->prepare('SELECT source_url FROM import_article_map WHERE article_id=? AND site_id=? ORDER BY id DESC LIMIT 1');
 $q->execute([$id,$article['site_id']]);$source=(string)($q->fetchColumn()?:'');
 if($source!=='')$profile=cms_source_style_read((int)$article['site_id'],$source);
}catch(PDOException $e){error_log('source preview map: '.$e->getMessage());}
$css=(string)($profile['css']??'');
$design=is_array($profile['site_design']??null)?$profile['site_design']:[];
$logoUrl=(string)($design['logo_url']??'');
$headerLinks=is_array($design['header_links']??null)?array_slice($design['header_links'],0,14):[];
$footerLinks=is_array($design['footer_links']??null)?array_slice($design['footer_links'],0,10):[];
$brandName=(string)($design['name']??($profile['host']??''));
$host=strtolower((string)parse_url($source,PHP_URL_HOST));
$isMagyarNemzet=in_array($host,['magyarnemzet.hu','www.magyarnemzet.hu'],true);
if($isMagyarNemzet){
 // Correct older automatic profiles that picked the article title as site name
 // or used the square favicon in place of the horizontal wordmark.
 if(empty($profile['site_design_reviewed'])||$brandName===''||str_contains(mb_strtolower($brandName),'imf-hitel'))$brandName='Magyar Nemzet';
 if(empty($profile['site_design_reviewed'])||$logoUrl===''||preg_match('~(?:favicon|icon|logo-mno-slightblue)~i',$logoUrl))
  $logoUrl='https://magyarnemzet.hu/assets/images/logo-mno-dark.svg';
}
$topicLinks=is_array($design['topic_links']??null)?array_slice($design['topic_links'],0,6):[];
$sectionParts=explode('/',trim((string)parse_url($source,PHP_URL_PATH),'/'));
$sectionSlug=strtolower((string)($sectionParts[0]??''));
$sectionNames=['belfold'=>'Belföld','kulfold'=>'Külföld','gazdasag'=>'Gazdaság','sport'=>'Sport','kultura'=>'Kultúra','tudomany'=>'Tudomány','velemeny'=>'Vélemény','lugas'=>'Lugas'];
$sectionName=$sectionNames[$sectionSlug]??($sectionSlug!==''?mb_convert_case(str_replace('-',' ',$sectionSlug),MB_CASE_TITLE,'UTF-8'):'');
$rootUrl=$host!==''?(parse_url($source,PHP_URL_SCHEME).'://'.$host.'/'):'';
$socialLinks=is_array($design['social_links']??null)?array_slice($design['social_links'],0,8):[];
$socialTitle=(string)($design['social_title']??'');
$footerActions=is_array($design['footer_actions']??null)?array_slice($design['footer_actions'],0,4):[];
$publisherLogo=(string)($design['publisher_logo_url']??'');
$publisherText=(string)($design['publisher_text']??'');
$regionals=is_array($design['regional_links']??null)?array_slice($design['regional_links'],0,32):[];
if($isMagyarNemzet){
 if(!$topicLinks)$topicLinks=[
  ['label'=>'Orbán Viktor','url'=>'https://magyarnemzet.hu/cimke/orban-viktor-12'],
  ['label'=>'Magyar Péter','url'=>'https://magyarnemzet.hu/cimke/magyar-peter'],
  ['label'=>'Tisza-kormány','url'=>'https://magyarnemzet.hu/cimke/tisza-kormany']];
 if(!$headerLinks)$headerLinks=[
  ['label'=>'Belföld','url'=>'https://magyarnemzet.hu/rovat/belfold'],
  ['label'=>'Külföld','url'=>'https://magyarnemzet.hu/rovat/kulfold'],
  ['label'=>'Gazdaság','url'=>'https://magyarnemzet.hu/rovat/gazdasag'],
  ['label'=>'Vélemény','url'=>'https://magyarnemzet.hu/rovat/velemeny'],
  ['label'=>'Kultúra','url'=>'https://magyarnemzet.hu/rovat/kultura'],
  ['label'=>'Sport','url'=>'https://magyarnemzet.hu/rovat/sport']];
 if(!$socialLinks)$socialLinks=[
  ['label'=>'Facebook','url'=>'https://www.facebook.com/magyarnemzet.hu'],
  ['label'=>'Instagram','url'=>'https://www.instagram.com/magyarnemzet.hu/?hl=en'],
  ['label'=>'X','url'=>'https://twitter.com/MagyarNemzetOn'],
  ['label'=>'Videa','url'=>'https://videa.hu/csatornak/magyar-nemzet-404'],
  ['label'=>'YouTube','url'=>'https://www.youtube.com/@magyarnemzet_hivatalos'],
  ['label'=>'RSS','url'=>'https://magyarnemzet.hu/publicapi/hu/rss/magyar_nemzet/articles']];
 if(!$footerActions)$footerActions=[
  ['label'=>'Feliratkozom a hírlevélre','url'=>'https://magyarnemzet.hu/hirlevel/feliratkozas'],
  ['label'=>'Előfizetek az újságra','url'=>'https://lapcentrum.hu/napilapok/12-magyar-nemzet.html']];
 if($socialTitle==='')$socialTitle='Kövesse a Magyar Nemzet híreit a közösségi médiában és csatornáinkon:';
 if($publisherLogo==='')$publisherLogo='https://magyarnemzet.hu/assets/images/mw/mwf_logo_mediaworks.png';
 if($publisherText==='')$publisherText='Portfóliónk minőségi tartalmat jelent minden olvasó számára. Egyedülálló elérést, országos lefedettséget és változatos megjelenési lehetőséget biztosít. Folyamatosan keressük az új irányokat és fejlődési lehetőségeket. Ez jövőnk záloga.';
 if(!$regionals)$regionals=[
  ['label'=>'Bács-Kiskun - baon.hu','url'=>'https://www.baon.hu'],
  ['label'=>'Baranya - bama.hu','url'=>'https://www.bama.hu'],
  ['label'=>'Békés - beol.hu','url'=>'https://www.beol.hu'],
  ['label'=>'Borsod-Abaúj-Zemplén - boon.hu','url'=>'https://www.boon.hu'],
  ['label'=>'Csongrád - delmagyar.hu','url'=>'https://www.delmagyar.hu'],
  ['label'=>'Dunaújváros - duol.hu','url'=>'https://www.duol.hu']];
}


function sp_h(string $text):string{return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function sp_content(string $json):string{
 $blocks=json_decode($json,true);if(!is_array($blocks))return '';
 $out='';
 foreach($blocks as $item){
  if(!is_array($item))continue;
  $type=(string)($item['type']??'');$text=(string)($item['text']??'');
  if($type==='richhtml')$out.='<div class="cms-source-body">'.cms_rich_sanitize_html($text).'</div>';
  elseif($type==='richtext'){
   $ops=json_decode($text,true)['ops']??[];
   if(!is_array($ops))continue;
   $part='';
   foreach($ops as $op){
    $insert=$op['insert']??null;
    if(is_string($insert))$part.=nl2br(sp_h($insert));
    elseif(is_array($insert)&&is_string($insert['image']??null)){
     $url=cms_rich_url($insert['image']);
     if($url)$part.='<img src="'.sp_h($url).'" alt="" loading="lazy">';
    }
   }
   $out.='<div class="cms-source-body">'.$part.'</div>';
  }elseif(in_array($type,['paragraph','heading','quote','list'],true)){
   $tag=['paragraph'=>'p','heading'=>'h2','quote'=>'blockquote','list'=>'p'][$type];
   $out.='<'.$tag.'>'.nl2br(sp_h($text)).'</'.$tag.'>';
  }elseif($type==='image'){
   $url=cms_rich_url($text);
   if($url)$out.='<figure><img src="'.sp_h($url).'" alt="" loading="lazy"></figure>';
  }elseif($type==='embed'){
   $url=cms_rich_url($text);
   if($url)$out.='<p><a href="'.sp_h($url).'" target="_blank" rel="noopener noreferrer">Beágyazott tartalom megnyitása</a></p>';
  }
 }
 return $out;
}
$title=(string)$article['title'];$lead=(string)$article['lead'];
$og=(string)($article['og_image_url']??'');
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=sp_h($title)?> · Piszkozat előnézet</title>
<style>
:root{color-scheme:light}*{box-sizing:border-box}
body{margin:0;background:#fff;color:#090e20;font:17px/1.65 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.cms-source-banner{position:fixed;z-index:100;bottom:12px;right:12px;max-width:250px;padding:7px 12px;border-radius:999px;background:#172440ed;color:#fff;box-shadow:0 4px 20px #0003;display:flex;align-items:center;gap:6px;font:600 10px/1.2 system-ui}
.cms-source-banner span{display:none}
.cms-source-header{width:100%;background:#fff;color:#060d20;border-bottom:1px solid #0d1930}
.cms-source-head-inner{max-width:1280px;margin:auto;display:grid;grid-template-columns:minmax(54px,1fr) auto minmax(54px,1fr);align-items:center;gap:14px;padding:14px 22px;min-height:83px}
.cms-source-brand{grid-column:2;display:flex;align-items:center;justify-content:center;text-decoration:none;color:#050c1e;font:800 30px/1.05 Georgia,serif;letter-spacing:-.055em;min-width:0;text-align:center}
.cms-source-brand img{display:block;width:auto;height:auto;max-height:62px;max-width:min(340px,53vw);object-fit:contain}
.cms-source-brand .cms-source-brand-name{display:block}
.cms-source-brand:has(img) .cms-source-brand-name{display:none}
.cms-source-brand img{min-width:100px;min-height:30px}
.cms-source-head-start{grid-column:1;grid-row:1;display:flex;align-items:center;justify-content:flex-start}
.cms-source-head-end{grid-column:3;grid-row:1;display:flex;justify-content:flex-end;align-items:center;gap:18px}
.cms-source-icon{width:25px;height:25px;flex:none;stroke:#071022;stroke-width:2;fill:none;stroke-linecap:round;stroke-linejoin:round}
.cms-source-menu-toggle{position:relative}
.cms-source-menu-toggle summary{cursor:pointer;list-style:none;display:flex;align-items:center}
.cms-source-menu-toggle summary::-webkit-details-marker{display:none}
.cms-source-menu-toggle nav{display:none;position:absolute;z-index:30;top:calc(100% + 18px);right:0;width:min(340px,85vw);max-height:70vh;overflow-y:auto;background:#fff;border:1px solid #d8dfe8;border-radius:10px;box-shadow:0 12px 40px #0002;padding:15px}
.cms-source-menu-toggle[open] nav{display:grid;gap:2px}
.cms-source-menu-toggle nav a{font:600 14px/1.5 system-ui;padding:8px 10px;text-decoration:none;color:#080e1f}
.cms-source-menu-toggle nav a:hover{background:#f3f5f8}
.cms-source-trends{border-top:1px solid #071022;border-bottom:2px solid #071022;overflow-x:auto;scrollbar-width:none}
.cms-source-trends::-webkit-scrollbar{display:none}
.cms-source-trends-inner{max-width:1280px;margin:auto;display:flex;align-items:center;gap:clamp(25px,7vw,90px);padding:10px 18px;white-space:nowrap}
.cms-source-trends a{font:800 clamp(13px,2.3vw,18px)/1.5 system-ui;color:#080e1f;text-decoration:none;text-transform:uppercase}
.cms-source-article{background:#fff;color:#090e20;max-width:1050px;padding:clamp(24px,3vw,40px);margin:38px auto 55px;font:18px/1.65 system-ui,sans-serif;min-width:0;overflow-wrap:anywhere}
.cms-source-breadcrumb{display:flex;align-items:center;gap:13px;font:600 15px/1.4 system-ui;margin:14px 0 32px;color:#717b8e}
.cms-source-breadcrumb a{color:#080f22;text-decoration:none;font-weight:750}
.cms-source-breadcrumb .cms-source-separator{color:#7791af;font-size:22px;font-weight:300}
.cms-source-article h1{font:800 clamp(30px,4.6vw,49px)/1.23 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:-.035em;color:#070c1c;margin:0 0 24px;overflow-wrap:anywhere}
.cms-source-article .cms-source-lead{font:400 clamp(17px,1.7vw,22px)/1.68 system-ui,sans-serif;color:#101626;margin:22px 0}
.cms-source-article .cms-source-body{line-height:1.75}
.cms-source-article img,.cms-source-article video{display:block;max-width:100%;height:auto}
.cms-source-article iframe{max-width:100%;width:100%;height:430px;border:0}
.cms-source-article figcaption{font:13px/1.5 system-ui;color:#66768c;margin-top:8px}
.cms-source-article table{display:block;max-width:100%;overflow:auto;border-collapse:collapse}
.cms-source-article td,.cms-source-article th{border:1px solid #d8dee8;padding:9px}
.cms-source-article blockquote{border-left:4px solid #314fd2;padding:12px 20px;background:#f6f7fa}
.cms-source-article .cms-cover{width:100%;max-height:540px;object-fit:cover;margin:22px 0}
.cms-source-footer{margin-top:35px;padding:28px 22px;background:#17243e;color:#f2f5ff}
.cms-source-footer-inner{max-width:1200px;margin:auto;display:grid;gap:16px}
.cms-source-footer nav{display:flex;flex-wrap:wrap;gap:10px 22px}
.cms-source-footer nav a{font:500 13px/1.5 system-ui;color:inherit;text-decoration:none}
.cms-source-footer p{margin:0;font:13px/1.6 system-ui}
@media(max-width:720px){
 .cms-source-head-inner{min-height:82px;gap:5px;padding:12px 18px;grid-template-columns:42px minmax(0,1fr) 78px}
 .cms-source-brand{font-size:25px;letter-spacing:-.065em}
 .cms-source-brand img{max-width:min(225px,53vw);max-height:54px}
 .cms-source-head-end{gap:14px}
 .cms-source-icon{width:23px;height:23px}
 .cms-source-trends-inner{gap:clamp(38px,9vw,58px);padding:9px 12px}
 .cms-source-trends a{font-size:14px;font-weight:800}
 .cms-source-article{max-width:100%;margin:0;padding:0 16px 38px;font-size:16px;box-shadow:none}
 .cms-source-breadcrumb{padding-top:52px;margin:0 0 32px;font-size:15px}
 .cms-source-article h1{font-size:clamp(28px,7.4vw,36px);line-height:1.24;letter-spacing:-.02em;margin-bottom:24px}
 .cms-source-article .cms-source-lead{font-size:18px;line-height:1.64;margin:20px 0}
 .cms-source-article .cms-cover{margin:20px 0}
 .cms-source-footer{margin-top:0}
 .cms-source-banner{font-size:9px;padding:6px 10px;bottom:10px;right:8px;opacity:.88}
}

/* Source-chrome components are isolated from article-content CSS. */
.cms-source-social{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:14px;margin:14px auto}
.cms-source-social a{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:#fff;color:#071122;text-decoration:none;font:bold 21px/1 system-ui;min-width:38px}
.cms-source-social a span{font-size:17px}
.cms-source-footer-top{background:#64758e;color:#fff;padding:26px 22px;display:grid;gap:18px}
.cms-source-footer-top-inner{max-width:920px;width:100%;margin:auto}
.cms-source-social-box{border:1px solid #adb8c8;border-radius:2px;text-align:center;padding:17px 15px 14px}
.cms-source-social-box h2{font:750 17px/1.32 system-ui;margin:0}
.cms-source-footer-actions{display:grid;gap:16px;margin-top:18px}
.cms-source-footer-actions a{display:flex;align-items:center;justify-content:center;text-align:center;text-decoration:none;border:1px solid #fff;border-radius:3px;min-height:43px;padding:8px 16px;font:500 16px/1.4 system-ui;color:#fff;background:transparent}
.cms-source-footer-actions a:nth-child(even){color:#0b1224;background:#f6f8fb}
.cms-source-footer-actions a:before{content:"✉";font-size:17px;margin-right:10px}
.cms-source-footer-actions a:nth-child(even):before{content:"▣"}
.cms-source-footer-legal{background:#344259;color:#fff;text-align:center;padding:23px 20px}
.cms-source-footer-legal p{font:14px/1.5 system-ui;margin:0 auto 10px;max-width:780px}
.cms-source-footer-legal img{max-width:155px;max-height:45px;vertical-align:middle;margin-left:8px}
.cms-source-footer-network{background:#000;color:#fff;padding:50px 22px 60px;font:14px/1.55 system-ui}
.cms-source-footer-network-inner{max-width:1000px;margin:auto}
.cms-source-footer-network img{display:block;max-width:185px;max-height:90px;object-fit:contain;margin:0 0 26px}
.cms-source-footer-network p{color:#a1a1a1;margin:0 0 32px;max-width:650px;font:14px/1.5 system-ui}
.cms-source-regional summary{display:flex;justify-content:center;align-items:center;gap:14px;font:500 19px/1.5 system-ui;list-style:none;cursor:pointer;margin:15px auto}
.cms-source-regional summary::-webkit-details-marker{display:none}
.cms-source-regional summary:after{content:"⌄";font-size:30px;line-height:.75}
.cms-source-regional nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 26px}
.cms-source-regional nav a{color:#fff;text-decoration:none;font:14px/1.5 system-ui;overflow-wrap:anywhere}
 .cms-source-footer:not([data-variant="publisher"]){margin-top:30px}
.cms-source-footer[data-variant="publisher"]{margin-top:0;padding:0;background:transparent}
.cms-source-banner{position:static;max-width:none;border-radius:0;box-shadow:none;padding:5px 10px;justify-content:center;font-size:10px;background:#172440;color:#fff;opacity:1}
.cms-source-banner span{display:inline;opacity:.7}
.cms-source-breadcrumb{min-width:0;overflow:hidden}
.cms-source-breadcrumb a,.cms-source-breadcrumb span{min-width:0}
.cms-source-breadcrumb a{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media(max-width:720px){
 .cms-source-head-inner{padding:12px 16px;grid-template-columns:38px minmax(0,1fr) 72px;min-height:77px}
 .cms-source-brand img{max-width:160px;max-height:44px}
 .cms-source-head-end{gap:11px}
 .cms-source-trends-inner{gap:25px;padding:9px 14px}
 .cms-source-breadcrumb{padding-top:22px;margin:0 0 30px;gap:8px}
 .cms-source-article{padding:0 17px 38px}
 .cms-source-article h1{font-size:clamp(28px,7.1vw,36px)}
 .cms-source-footer-top{padding:26px 25px}
 .cms-source-social{gap:13px}
 .cms-source-social a{width:35px;height:35px;min-width:35px}
 .cms-source-footer-legal{padding:22px 20px}
 .cms-source-footer-network{padding:50px 22px}
 .cms-source-footer-network img{max-width:170px}
 .cms-source-regional nav{grid-template-columns:1fr;gap:10px}
}
</style>

<?php if($css!==''):?><style id="source-design-style"><?=$css?></style><?php endif;?>
</head><body>
<div class="cms-source-banner" role="status" aria-label="Nem publikus előnézet">
 <strong>PISZKOZAT · NEM PUBLIKUS</strong>
 <span><?=sp_h($host)?></span>
</div>
<?php if($design||$isMagyarNemzet):?>
<header class="cms-source-header"><div class="cms-source-head-inner">
<div class="cms-source-head-start" aria-hidden="true"><svg class="cms-source-icon" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="7.5"/><path d="m16.5 16.5 5 5"/></svg></div>
<a class="cms-source-brand" href="<?=sp_h($rootUrl?:'#')?>" target="_blank" rel="noopener noreferrer">
<?php if($logoUrl!==''&&cms_rich_url($logoUrl)):?><img src="<?=sp_h(cms_rich_url($logoUrl))?>" alt="<?=sp_h($brandName)?>"><?php endif;?><span class="cms-source-brand-name"><?=sp_h($brandName)?></span>
</a>
<div class="cms-source-head-end">
<svg class="cms-source-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M4 21v-3a8 8 0 0 1 16 0v3"/></svg>
<details class="cms-source-menu-toggle"><summary aria-label="Menü megnyitása"><svg class="cms-source-icon" viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg></summary>
<nav aria-label="A forrásoldal főmenüje"><?php foreach($headerLinks as $item):if(!is_array($item))continue;?>
<a href="<?=sp_h((string)($item['url']??'#'))?>" target="_blank" rel="noopener noreferrer"><?=sp_h((string)($item['label']??''))?></a><?php endforeach;?></nav></details>
</div></div>
<?php if($topicLinks):?><nav class="cms-source-trends" aria-label="Kiemelt témák"><div class="cms-source-trends-inner">
<?php foreach($topicLinks as $item):if(!is_array($item))continue;?>
<a href="<?=sp_h((string)($item['url']??'#'))?>" target="_blank" rel="noopener noreferrer"><?=sp_h((string)($item['label']??''))?></a>
<?php endforeach;?></div></nav><?php endif;?>
</header><?php endif;?>
<article class="cms-source-article">
<?php if($source!==''):?><nav class="cms-source-breadcrumb" aria-label="Morzsanavigáció"><a href="<?=sp_h($rootUrl)?>" target="_blank" rel="noopener noreferrer"><?=sp_h($brandName)?></a><?php if($sectionName!==''):?><span class="cms-source-separator" aria-hidden="true">›</span><span><?=sp_h($sectionName)?></span><?php endif;?></nav><?php endif;?>
<h1><?=sp_h($title)?></h1>
<?php if($lead!==''):?><p class="cms-source-lead"><?=nl2br(sp_h($lead))?></p><?php endif;?>
<?php if($og&&cms_rich_url($og)):?><img class="cms-cover" src="<?=sp_h(cms_rich_url($og))?>" alt=""><?php endif;?>
<?=sp_content((string)$article['content_json'])?>
</article>
<?php if($design||$isMagyarNemzet):?>
<?php if($socialLinks||$footerActions):?>
<section class="cms-source-footer-top"><div class="cms-source-footer-top-inner">
<?php if($socialLinks):?><div class="cms-source-social-box">
<h2><?=sp_h($socialTitle)?></h2>
<nav class="cms-source-social" aria-label="A forrásoldal közösségi csatornái">
<?php foreach($socialLinks as $item):if(!is_array($item))continue;
 $label=(string)($item['label']??'');$href=cms_rich_url((string)($item['url']??''));if(!$href)continue;
 $symbol=['facebook'=>'f','instagram'=>'◎','x'=>'𝕏','twitter'=>'𝕏','videa'=>'V','youtube'=>'▶','rss'=>'◔','tiktok'=>'♪','linkedin'=>'in'][$label!==''?mb_strtolower($label):'']??'↗';?>
<a href="<?=sp_h($href)?>" title="<?=sp_h($label)?>" aria-label="<?=sp_h($label)?>" target="_blank" rel="noopener noreferrer"><span><?=sp_h($symbol)?></span></a>
<?php endforeach;?></nav></div><?php endif;?>
<?php if($footerActions):?><nav class="cms-source-footer-actions" aria-label="Hírlevél és előfizetés">
<?php foreach($footerActions as $item):if(!is_array($item))continue;$href=cms_rich_url((string)($item['url']??''));if(!$href)continue;?>
<a href="<?=sp_h($href)?>" target="_blank" rel="noopener noreferrer"><?=sp_h((string)($item['label']??''))?></a>
<?php endforeach;?></nav><?php endif;?></div></section><?php endif;?>
<footer class="cms-source-footer" data-variant="publisher">
<?php if($isMagyarNemzet):?><div class="cms-source-footer-legal">
<p><?=sp_h('A Magyar Nemzet kiadója a Mediaworks Hungary Zrt. © Minden jog fenntartva')?></p>
<p>© <?=date('Y')?> <img src="https://magyarnemzet.hu/assets/images/logo-mno-slightblue.svg" alt="Magyar Nemzet"></p>
</div>
<?php else:?><div class="cms-source-footer-legal">
<?php if(!empty($design['copyright'])):?><p><?=sp_h((string)$design['copyright'])?></p>
<?php elseif(!empty($design['footer_text'])):?><p><?=sp_h((string)$design['footer_text'])?></p><?php endif;?>
</div><?php endif;?>
<?php if($footerLinks&&!$isMagyarNemzet):?><div class="cms-source-footer-inner"><nav aria-label="A forrásoldal láblécmenüje">
<?php foreach($footerLinks as $item):if(!is_array($item))continue;$href=cms_rich_url((string)($item['url']??''));if(!$href)continue;?>
<a href="<?=sp_h($href)?>" target="_blank" rel="noopener noreferrer"><?=sp_h((string)($item['label']??''))?></a>
<?php endforeach;?></nav></div><?php endif;?>
</footer>
<?php if($publisherLogo||$publisherText||$regionals):?><section class="cms-source-footer-network" aria-label="Kiadói portfólió"><div class="cms-source-footer-network-inner">
<?php if($publisherLogo&&cms_rich_url($publisherLogo)):?><img src="<?=sp_h(cms_rich_url($publisherLogo))?>" alt="A kiadó logója"><?php endif;?>
<?php if($publisherText):?><p><?=sp_h($publisherText)?></p><?php endif;?>
<?php if($regionals):?><details class="cms-source-regional" open><summary>Regionális hírportálok</summary><nav>
<?php foreach($regionals as $item):if(!is_array($item))continue;$href=cms_rich_url((string)($item['url']??''));if(!$href)continue;?>
<a href="<?=sp_h($href)?>" target="_blank" rel="noopener noreferrer"><?=sp_h((string)($item['label']??''))?></a>
<?php endforeach;?></nav></details><?php endif;?>
</div></section><?php endif;?>
<?php endif;?>
</body></html>