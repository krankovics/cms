<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require_once __DIR__.'/app/import-engine.php';
require_once __DIR__.'/app/import-source-style.php';
require_once __DIR__.'/app/import-site-design.php';
$u=require_user();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex,nofollow');
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
if(!$id){http_response_code(404);exit('Hiányzó cikk.');}
$pdo=db();
$q=$pdo->prepare('SELECT id,site_id,author_id,title FROM articles WHERE id=? LIMIT 1');$q->execute([$id]);$article=$q->fetch();
if(!$article){http_response_code(404);exit('A cikk nem található.');}
$site=(int)$article['site_id'];
$allowed=in_array($u['role'],['platform_admin','site_admin','editor','author'],true)
 && in_array($site,allowed_primary_sites($pdo,$u),true)
 && ($u['role']!=='author'||(int)$article['author_id']===(int)$u['id']);
if(!$allowed){http_response_code(403);exit('Nincs hozzáférés.');}
$canEdit=in_array($u['role'],['platform_admin','site_admin'],true);
$q=$pdo->prepare('SELECT source_url FROM import_article_map WHERE site_id=? AND article_id=? ORDER BY id DESC LIMIT 1');
$q->execute([$site,$id]);$url=(string)($q->fetchColumn()?:'');
if(!$url){http_response_code(404);exit('Nem található importált forráswebhely.');}
$error='';$profile=cms_source_style_read($site,$url);
if(!$profile){http_response_code(404);exit('Még nincs mentett forrásarculat. Frissítsd az importált cikkben.');}
function sd_h(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function sd_lines($items):string{
 if(!is_array($items))return '';
 $out=[];
 foreach($items as $item)if(is_array($item))$out[]=($item['label']??'').' | '.($item['url']??'');
 return implode("\n",$out);
}
function sd_parse_links(string $raw,string $source,int $max):array{
 if(mb_strlen($raw)>6500)throw new RuntimeException('A menü túl hosszú.');
 $host=strtolower((string)parse_url($source,PHP_URL_HOST));
 $out=[];$seen=[];
 foreach(preg_split('/\R/u',trim($raw))?:[] as $line){
  $line=trim($line);if($line==='')continue;
  if(count($out)>=$max)throw new RuntimeException('Túl sok menüpont.');
  if(!str_contains($line,'|'))throw new RuntimeException('A menüelemek formátuma: Név | https://...');
  [$label,$href]=explode('|',$line,2);
  $label=cms_source_design_text($label,60);
  $href=cms_source_design_url(trim($href),$source);
  if(mb_strlen($label)<2||!$href||strtolower((string)parse_url($href,PHP_URL_HOST))!==$host)
   throw new RuntimeException('Csak a forráswebhelyre mutató, nyilvános menühivatkozás adható meg.');
  if(isset($seen[$href]))continue;
  $out[]=['label'=>$label,'url'=>$href];$seen[$href]=true;
 }
 return $out;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!$canEdit){http_response_code(403);throw new RuntimeException('Csak webhelyadminisztrátor módosíthatja a közös arculatot.');}
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $name=cms_source_design_text((string)($_POST['name']??''),100);
  if(mb_strlen($name)<2)throw new RuntimeException('Add meg a kiadó nevét.');
  $logoRaw=trim((string)($_POST['logo_url']??''));
  $logo=$logoRaw===''?'':cms_source_design_url($logoRaw,$url);
  if($logoRaw!==''&&$logo==='')throw new RuntimeException('Érvénytelen logó URL.');
  $design=[
   'name'=>$name,'logo_url'=>$logo,
   'header_links'=>sd_parse_links((string)($_POST['header_links']??''),$url,14),
   'footer_links'=>sd_parse_links((string)($_POST['footer_links']??''),$url,10),
   'footer_text'=>cms_source_design_text((string)($_POST['footer_text']??''),420),
   'copyright'=>cms_source_design_text((string)($_POST['copyright']??''),240)
  ];
  $current=cms_source_style_read($site,$url);
  if(!$current)throw new RuntimeException('A forrásarculat időközben nem található.');
  $current['site_design']=$design;
  $current['site_design_reviewed']=true;
  $current['site_design_reviewed_at']=gmdate('c');
  cms_source_style_write($site,$url,$current);
  $audit=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
  $audit->execute([$u['id'],'source_design.review','site',$site,json_encode(['host'=>$current['host'],'article_id'=>$id],JSON_UNESCAPED_UNICODE)]);
  header('Location: /source-site-design.php?id='.$id.'&saved=1');exit;
 }catch(Throwable $e){$error=$e->getMessage();}
}
$design=is_array($profile['site_design']??null)?$profile['site_design']:[];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forráswebhely arculata | comesy</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="/assets/admin-secondary-v2.css">
<style>
.source-design-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(240px,.8fr);gap:14px;margin-top:14px}
.source-design-card{padding:20px;border:1px solid var(--ui-border,#dde4ed);border-radius:12px;background:var(--ui-surface,#fff);min-width:0}
.source-design-card label{display:grid;gap:5px;margin:0 0 13px;font-size:12px;font-weight:650}
.source-design-card input,.source-design-card textarea{width:100%;min-height:41px;min-width:0;padding:10px 12px;border:1px solid var(--ui-border,#dde4ed);border-radius:8px;background:var(--ui-surface,#fff);color:var(--ui-text,#1e2a39);font:14px/1.5 system-ui}
.source-design-card textarea{min-height:92px}
.source-design-card p,.source-design-card small{font-size:12px;color:var(--ui-text-secondary,#5b6978);line-height:1.55;overflow-wrap:anywhere}
.source-design-card img{display:block;max-width:220px;max-height:95px;object-fit:contain;margin-bottom:12px}
.source-design-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.source-design-card button{cursor:pointer}
@media(max-width:790px){.source-design-grid{grid-template-columns:1fr}.source-design-card{padding:14px}}
</style></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Importált webhely</p><h1 class="ui-page-title">Forráswebhely arculata</h1>
<p class="ui-page-description"><?=sd_h((string)($profile['host']??''))?> · <?=sd_h((string)$article['title'])?></p></div>
<div class="ui-page-actions"><a class="ui-btn ui-btn-secondary" href="/editor.php?id=<?=$id?>">Vissza a cikkhez</a>
<a class="ui-btn ui-btn-primary" href="/source-preview.php?id=<?=$id?>" target="_blank" rel="noopener">Oldalelőnézet ↗</a></div></header>
<?php if($error):?><p class="error" role="alert"><?=sd_h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="success" role="status">A közös forráswebhely-arculat mentve. A következő import nem írja felül a kézi módosításokat.</p><?php endif;?>
<div class="source-design-grid">
<section class="source-design-card"><h2>Webhely megjelenése</h2><p>A mezők az ugyanerről a forráswebhelyről importált cikkek előnézetében közösen érvényesülnek. Az éles comesy webhelyre nem publikálódnak automatikusan.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=sd_h(csrf())?>"><input type="hidden" name="id" value="<?=$id?>">
<label>Kiadó neve<input name="name" required maxlength="100" value="<?=sd_h((string)($design['name']??$profile['host']??''))?>" <?=$canEdit?'':'disabled'?>></label>
<label>Logó URL-je<input name="logo_url" type="url" maxlength="1000" value="<?=sd_h((string)($design['logo_url']??''))?>" placeholder="https://…" <?=$canEdit?'':'disabled'?>></label>
<label>Főmenü (soronként: Név | URL)<textarea name="header_links" rows="6" <?=$canEdit?'':'disabled'?>><?=sd_h(sd_lines($design['header_links']??[]))?></textarea></label>
<label>Láblécmenü (soronként: Név | URL)<textarea name="footer_links" rows="4" <?=$canEdit?'':'disabled'?>><?=sd_h(sd_lines($design['footer_links']??[]))?></textarea></label>
<label>Copyright<input name="copyright" maxlength="240" value="<?=sd_h((string)($design['copyright']??''))?>" <?=$canEdit?'':'disabled'?>></label>
<label>Lábléc rövid szövege<textarea name="footer_text" rows="3" <?=$canEdit?'':'disabled'?>><?=sd_h((string)($design['footer_text']??''))?></textarea></label>
<?php if($canEdit):?><div class="source-design-actions"><button type="submit" class="ui-btn ui-btn-primary">Arculat jóváhagyása és mentése</button></div><?php endif;?>
</form></section>
<aside class="source-design-card"><h2>Felismerés és állapot</h2>
<?php if(!empty($design['logo_url'])):?><img src="<?=sd_h((string)$design['logo_url'])?>" alt="Forráswebhely logója" loading="lazy"><?php endif;?>
<p><strong><?=sd_h((string)($design['name']??$profile['host']??''))?></strong></p>
<p>Főmenü: <?=count($design['header_links']??[])?> · Láblécmenü: <?=count($design['footer_links']??[])?></p>
<p>CSS: <?=!empty($profile['css'])?'rögzítve':'nem sikerült felismerni'?> · Külső CSS-fájlok: <?=(int)($profile['css_files']??0)?></p>
<p>Utolsó automatikus gyűjtés: <?=!empty($profile['saved_at'])?sd_h(date('Y-m-d H:i',(int)$profile['saved_at'])):'nincs adat'?></p>
<p><?=!empty($profile['site_design_reviewed'])?'Kézzel ellenőrzött és jóváhagyott.':'Automatikusan felismert, még nem jóváhagyott.'?></p>
<p><strong>Fontos:</strong> a forráslogó és a menü használatához megfelelő felhasználási jogosultság szükséges. A forrásoldalból származó JavaScriptet és aktív widgeteket nem másoljuk át.</p>
</aside></div></main></body></html>