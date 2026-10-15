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
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f3f5f9;color:#202938;font-family:system-ui,sans-serif}
.cms-source-banner{padding:11px 17px;background:#172440;color:#e8eefb;font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}
.cms-source-banner a{color:#c0d2ff}
.cms-source-article{background:#fff;color:#202938;max-width:920px;padding:clamp(18px,5vw,50px);margin:23px auto 45px;box-shadow:0 7px 35px #0d1b3010;font-family:Georgia,serif;font-size:18px;line-height:1.75;min-width:0;overflow-wrap:anywhere}
.cms-source-article h1{font:750 clamp(28px,5vw,44px)/1.2 system-ui;margin:0 0 18px}
.cms-source-article .cms-source-lead{font-size:1.15em;color:#4b5c72;margin-bottom:25px}
.cms-source-article img,.cms-source-article video{display:block;max-width:100%;height:auto}
.cms-source-article iframe{max-width:100%;width:100%;height:430px;border:0}
.cms-source-article figcaption{font:13px/1.5 system-ui;color:#66768c;margin-top:8px}
.cms-source-article table{display:block;max-width:100%;overflow:auto;border-collapse:collapse}
.cms-source-article td,.cms-source-article th{border:1px solid #d8dee8;padding:9px}
.cms-source-article blockquote{border-left:4px solid #314fd2;padding:12px 20px;background:#f6f7fa}
.cms-source-article .cms-cover{width:100%;max-height:540px;object-fit:cover;margin-bottom:25px}
@media(max-width:720px){.cms-source-article{margin:0;padding:20px 16px;box-shadow:none;font-size:16px}.cms-source-banner{font-size:11px}}
</style>
<?php if($css!==''):?><style id="source-design-style"><?=$css?></style><?php endif;?>
</head><body>
<header class="cms-source-banner">
 <strong>PISZKOZAT ELŐNÉZET · NEM PUBLIKUS</strong>
 <span><?=($profile&&$css!=='')?'A forrás tipográfiájával és színeivel: '.sp_h($profile['host']):'Alap megjelenés'.($source!==''?' · forrásstílus még nincs rögzítve':'')?></span>
</header>
<article class="cms-source-article">
<?php if($og&&cms_rich_url($og)):?><img class="cms-cover" src="<?=sp_h(cms_rich_url($og))?>" alt=""><?php endif;?>
<h1><?=sp_h($title)?></h1>
<?php if($lead!==''):?><p class="cms-source-lead"><?=nl2br(sp_h($lead))?></p><?php endif;?>
<?=sp_content((string)$article['content_json'])?>
</article></body></html>