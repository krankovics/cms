<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/article-sites.php';
require_once __DIR__.'/app/rich-content.php';
require_once __DIR__.'/app/import-source-style.php';
$u=require_user();
header('Content-Type: text/html; charset=utf-8');
$canEdit=in_array($u['role'],['platform_admin','site_admin','editor','author'],true);
if(!$canEdit){http_response_code(403);exit('Nincs szerkesztési jogosultság.');}
function ed_slug(string $s):string{
 $s=mb_strtolower(trim($s));$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;
 $s=preg_replace('/[^a-z0-9]+/','-',$s);return trim(substr($s,0,220),'-')?:'cikk';
}
function ed_blocks($raw):array{
 $data=json_decode((string)$raw,true);
 if(!is_array($data)||count($data)>250)throw new RuntimeException('Érvénytelen blokkok vagy túl sok blokk.');
 $out=[];
 foreach($data as $b){
  if(!is_array($b)||!in_array($b['type']??'', ['paragraph','heading','quote','list','image','embed','richtext','richhtml'],true))throw new RuntimeException('Nem támogatott blokk.');
  $text=(string)($b['text']??'');
  if(mb_strlen($text)>300000)throw new RuntimeException('Túl hosszú blokk.');
  if(in_array($b['type'],['image','embed'],true) && (!filter_var($text,FILTER_VALIDATE_URL)||!in_array(parse_url($text,PHP_URL_SCHEME),['https','http'],true)))throw new RuntimeException('Érvénytelen URL.');
  if($b['type']==='richhtml'){
   $text=cms_rich_sanitize_html($text);
   if(cms_rich_content_chars($text)<1)throw new RuntimeException('A gazdag importtartalom üres vagy nem biztonságos.');
  }
  if($b['type']==='richtext'){
   $delta=json_decode($text,true);
   if(!is_array($delta)||!isset($delta['ops'])||!is_array($delta['ops'])||count($delta['ops'])>5000)throw new RuntimeException('Érvénytelen szerkesztői tartalom.');
   foreach($delta['ops'] as $op){
    if(!is_array($op)||!array_key_exists('insert',$op))throw new RuntimeException('Érvénytelen szerkesztői művelet.');
    $insert=$op['insert'];
    if(is_array($insert)){if(count($insert)!==1||!isset($insert['image'])||!is_string($insert['image'])||!filter_var($insert['image'],FILTER_VALIDATE_URL)||!in_array(parse_url($insert['image'],PHP_URL_SCHEME),['https','http'],true))throw new RuntimeException('Érvénytelen kép URL.');}
    elseif(!is_string($insert))throw new RuntimeException('Érvénytelen szöveg.');
   }
  }
  $out[]=['type'=>$b['type'],'text'=>$text];
 }
 return $out;
}
$error='';$message='';$article=null;$id=max(0,(int)($_GET['id']??0));
$sourceStyle=null;$sourceUrl='';
try{
 $pdo=db();
 if($id){$q=$pdo->prepare('SELECT * FROM articles WHERE id=?');$q->execute([$id]);$article=$q->fetch();if(!$article)throw new RuntimeException('A cikk nem található.');if(!in_array((int)$article['site_id'],allowed_primary_sites($pdo,$u),true)){http_response_code(403);exit('Nincs jogosultság a cikk szerkesztéséhez.');}if($u['role']==='author'&&(int)$article['author_id']!==(int)$u['id']){http_response_code(403);exit('Csak a saját cikkeidet szerkesztheted.');}}
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Lejárt munkamenet. Frissítsd az oldalt.');
  $action=(string)($_POST['action']??'save');
  if($action==='restore'){
   if(!$id)throw new RuntimeException('Hiányzó cikk.');
   $rid=(int)($_POST['revision_id']??0);
   $q=$pdo->prepare('SELECT * FROM article_revisions WHERE id=? AND article_id=?');$q->execute([$rid,$id]);$rev=$q->fetch();
   if(!$rev)throw new RuntimeException('Nem található a verzió.');
   $_POST['title']=$rev['title'];$_POST['lead']=$rev['lead'];$_POST['blocks']=$rev['content_json'];$_POST['status']='draft';
   $_POST['expected_updated_at']=$article['updated_at'];
  }
  $title=trim((string)($_POST['title']??''));$lead=trim((string)($_POST['lead']??''));
  if(mb_strlen($title)<3||mb_strlen($title)>500)throw new RuntimeException('A cím 3–500 karakter lehet.');
  if(mb_strlen($lead)>10000)throw new RuntimeException('A lead túl hosszú.');
  $blocks=ed_blocks($_POST['blocks']??'[]');
  $json=json_encode($blocks,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
  $status=(string)($_POST['status']??'draft');
  if(!in_array($status,in_array($u['role'],['platform_admin','site_admin','editor'],true)?['draft','review','scheduled','published']:['draft','review'],true))throw new RuntimeException('Nem támogatott státusz.');
  $siteChoices=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['site_ids']??[])))));
  if(!$siteChoices||count($siteChoices)>20)throw new RuntimeException('Válassz 1–20 webhelyet.');
  $editableSiteIds=allowed_primary_sites($pdo,$u);
  if(array_diff($siteChoices,$editableSiteIds))throw new RuntimeException('Nincs szerkesztési jog minden kijelölt webhelyhez.');
  $siteId=$article?(int)$article['site_id']:(int)$siteChoices[0];
  if(!in_array($siteId,$siteChoices,true))throw new RuntimeException('Az elsődleges webhelyet nem lehet eltávolítani.');
  if(count($siteChoices)>1&&!article_sites_ready($pdo))throw new RuntimeException('A többwebhelyes működéshez futtasd az adatbázis-migrációt.');

  $termIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['terms']??[])))));
  $newCategories=array_filter(array_map('trim',preg_split('/[,;]+/u',(string)($_POST['new_categories']??''))));
  $tagNames=array_filter(array_map('trim',preg_split('/[,;]+/u',(string)($_POST['tag_names']??''))));
  $seoTitle=mb_substr(trim((string)($_POST['seo_title']??'')),0,255);
  $seoDescription=mb_substr(trim((string)($_POST['seo_description']??'')),0,500);
  $ogImageUrl=trim((string)($_POST['og_image_url']??''));
  if(mb_strlen($ogImageUrl)>1000)throw new RuntimeException('A kiemelt kép URL-je túl hosszú.');
  if($ogImageUrl!==''&&(!filter_var($ogImageUrl,FILTER_VALIDATE_URL)||!in_array(parse_url($ogImageUrl,PHP_URL_SCHEME),['http','https'],true)))throw new RuntimeException('Érvénytelen kiemelt kép URL.');
  $publishedAt=$status==='published'?gmdate('Y-m-d H:i:s'):null;
  if($status==='scheduled'){
   $value=(string)($_POST['schedule_at']??'');
   $tz=new DateTimeZone('Europe/Budapest');
   $when=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value,$tz);
   if(!$when||$when->format('Y-m-d\\TH:i')!==$value)throw new RuntimeException('Érvénytelen időzített publikálási időpont.');
   $utc=$when->setTimezone(new DateTimeZone('UTC'));
   if($utc->getTimestamp()<time()+300||$utc->getTimestamp()>time()+730*86400)throw new RuntimeException('Az időzítés 5 perc és 2 év között állítható be.');
   $publishedAt=$utc->format('Y-m-d H:i:s');
  }
  $pdo->beginTransaction();
  try{
   if($id){
    $q=$pdo->prepare('SELECT updated_at FROM articles WHERE id=? FOR UPDATE');$q->execute([$id]);$current=$q->fetchColumn();
    if(!$current||$current!==(string)($_POST['expected_updated_at']??''))throw new RuntimeException('A cikket időközben módosították. Frissítsd az oldalt.');
    $q=$pdo->prepare('UPDATE articles SET title=?,`lead`=?,content_json=?,status=?,site_id=?,published_at=CASE WHEN ?=\'published\' THEN IF(status=\'published\',COALESCE(published_at,UTC_TIMESTAMP()),UTC_TIMESTAMP()) WHEN ?=\'scheduled\' THEN ? ELSE NULL END,updated_at=NOW() WHERE id=?');
    $q->execute([$title,$lead,$json,$status,$siteId,$status,$status,$publishedAt,$id]);
   }else{
    $slug=ed_slug($title).'-'.bin2hex(random_bytes(3));
    $q=$pdo->prepare('INSERT INTO articles(site_id,author_id,title,slug,`lead`,content_json,status,published_at) VALUES(?,?,?,?,?,?,?,?)');
    $q->execute([$siteId,$u['id'],$title,$slug,$lead,$json,$status,$publishedAt]);$id=(int)$pdo->lastInsertId();
   }
   article_site_save($pdo,$id,$siteId,$siteChoices);
   $createTerm=$pdo->prepare('INSERT INTO taxonomy_terms(site_id,type,name,slug) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
   $findShared=$pdo->prepare('SELECT id FROM taxonomy_terms WHERE site_id=0 AND type=? AND slug=? LIMIT 1');
   foreach(['category'=>$newCategories,'tag'=>$tagNames] as $termType=>$names){
    foreach($names as $termName){
     if(mb_strlen($termName)>190)throw new RuntimeException('Túl hosszú címke vagy kategória.');
     $slug=ed_slug($termName);
     $findShared->execute([$termType,$slug]);
     $sharedId=(int)$findShared->fetchColumn();
     if($sharedId){$termIds[]=$sharedId;continue;}
     $createTerm->execute([$siteId,$termType,$termName,$slug]);$termIds[]=(int)$pdo->lastInsertId();
    }
   }
   $termIds=array_values(array_unique($termIds));
   $pdo->prepare('DELETE FROM article_terms WHERE article_id=?')->execute([$id]);
   $check=$pdo->prepare('SELECT id,site_id FROM taxonomy_terms WHERE id=?');
   $link=$pdo->prepare('INSERT INTO article_terms(article_id,term_id) VALUES(?,?)');
   foreach($termIds as $tid){$check->execute([$tid]);$term=$check->fetch();if(!$term||!in_array((int)$term['site_id'],allowed_resource_sites($pdo,$u,'tags'),true))throw new RuntimeException('Érvénytelen besorolás.');$link->execute([$id,$tid]);}
   $pdo->prepare('INSERT INTO article_meta(article_id,seo_title,seo_description,og_image_url) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE seo_title=VALUES(seo_title),seo_description=VALUES(seo_description),og_image_url=VALUES(og_image_url)')->execute([$id,$seoTitle,$seoDescription,$ogImageUrl]);
   $q=$pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM article_revisions WHERE article_id=?');$q->execute([$id]);$num=(int)$q->fetchColumn();
   $q=$pdo->prepare('INSERT INTO article_revisions(article_id,editor_id,revision_number,title,`lead`,content_json,status) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$id,$u['id'],$num,$title,$lead,$json,$status]);
   $q=$pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'article.save','article',?,?)");
   $q->execute([$u['id'],$id,json_encode(['revision'=>$num,'status'=>$status,'site_id'=>$siteId,'site_ids'=>$siteChoices])]);
   $pdo->commit();header('Location: /editor.php?id='.$id.'&saved=1');exit;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $primaryIds=allowed_primary_sites($pdo,$u);$sites=array_values(array_filter($sites,fn($s)=>in_array((int)$s['id'],$primaryIds,true)));
 $selectedSites=$article?article_site_ids($pdo,$article):[(int)($sites[0]['id']??0)];
 if($article&&array_diff($selectedSites,$primaryIds)){http_response_code(403);exit('A cikk egy vagy több olyan webhelyhez kapcsolódik, amelyhez nincs szerkesztési jogosultságod.');}
 if($id){$q=$pdo->prepare('SELECT * FROM articles WHERE id=?');$q->execute([$id]);$article=$q->fetch();}
 $visibleSites=allowed_resource_sites($pdo,$u,'tags');
 $terms=$pdo->query('SELECT id,site_id,type,name FROM taxonomy_terms WHERE site_id IN ('.implode(',',array_map('intval',$visibleSites?:[-1])).') ORDER BY type,(site_id=0) DESC,name')->fetchAll();
 // Show only assets from media libraries the editor may access. The selected
 // featured image remains a normal article_meta URL until the article is saved.
 $mediaAllowed=allowed_resource_sites($pdo,$u,'media');
 $editorMedia=[];
 if($mediaAllowed){
  $mediaStmt=$pdo->query('SELECT m.id,m.site_id,m.original_name,m.alt_text,s.name AS site_name FROM media_assets m JOIN sites s ON s.id=m.site_id WHERE m.site_id IN ('.implode(',',array_map('intval',$mediaAllowed)).') ORDER BY m.id DESC LIMIT 120');
  $editorMedia=$mediaStmt->fetchAll();
 }
 $selectedTerms=[];if($id){$q=$pdo->prepare('SELECT term_id FROM article_terms WHERE article_id=?');$q->execute([$id]);$selectedTerms=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
 $meta=[];if($id){$q=$pdo->prepare('SELECT * FROM article_meta WHERE article_id=?');$q->execute([$id]);$meta=$q->fetch()?:[];}
 if($id&&$article){
  try{
   $sourceQuery=$pdo->prepare('SELECT source_url FROM import_article_map WHERE site_id=? AND article_id=? ORDER BY id DESC LIMIT 1');
   $sourceQuery->execute([(int)$article['site_id'],$id]);$sourceUrl=(string)($sourceQuery->fetchColumn()?:'');
   if($sourceUrl!=='')$sourceStyle=cms_source_style_read((int)$article['site_id'],$sourceUrl);
  }catch(PDOException $e){error_log('editor source design: '.$e->getMessage());}
 }

 $revisions=[];
 if($id){$q=$pdo->prepare('SELECT id,revision_number,created_at,status FROM article_revisions WHERE article_id=? ORDER BY revision_number DESC LIMIT 20');$q->execute([$id]);$revisions=$q->fetchAll();}
}catch(Throwable $e){error_log('comesy editor: '.$e->getMessage());$error=$e instanceof PDOException?'A cikk mentése adatbázishiba miatt nem sikerült. Ellenőrizd a Beállítások / Adatbázis-migráció oldalt. Hibakód: '.h((string)$e->getCode()):$e->getMessage();$sites=$sites??[];$terms=$terms??[];$selectedTerms=$selectedTerms??[];$meta=$meta??[];$selectedSites=$selectedSites??[];$revisions=$revisions??[];$editorMedia=$editorMedia??[];}
$scheduleValue=($article&&($article['status']??'')==='scheduled'&&!empty($article['published_at']))?(new DateTimeImmutable($article['published_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d\\TH:i'):'';
$initial=json_decode($article['content_json']??'[]',true);if(!is_array($initial))$initial=[];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>comesy Editor</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css"><script src="/assets/ui.js" defer></script><script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script></head><body><?php require __DIR__.'/app/header.php'; ?><main class="editor-layout ui-page" id="main-content"><header class="ui-page-header editor-top"><div class="ui-page-heading"><p class="ui-eyebrow">Szerkesztőség</p><h1 class="ui-page-title"><?= $id?'Cikk szerkesztése':'Új cikk' ?></h1><p class="ui-page-description">Tartalom, webhelyek, besorolások és publikálás.</p></div><div class="ui-page-actions"><a class="ui-btn ui-btn-secondary" href="/articles.php">Cikklista</a><span class="ui-editor-state"><?=h(['draft'=>'Piszkozat','review'=>'Ellenőrzésre vár','published'=>'Publikált'][$article['status']??'draft']??'Piszkozat')?></span><button form="editor-form" class="ui-btn ui-btn-primary ui-editor-header-save" type="submit" <?=!$sites?'disabled':''?>>Mentés</button></div></header><?php if(!$sites):?><p class="error">Nincs elérhető webhelyed. Kérj jogosultságot a rendszergazdától, vagy szuperadminként <a href="/sites.php">hozz létre webhelyet</a>.</p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><?php if(isset($_GET['saved'])):?><p class="success">A cikk és az új verzió mentve.</p><?php endif;?><form id="editor-form" class="editor-form" method="post"><div class="panel ui-editor-main"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="expected_updated_at" value="<?=h((string)($article['updated_at']??''))?>"><input type="hidden" name="blocks" id="blocks-data"><label for="title">Cím</label><input id="title" name="title" maxlength="500" required value="<?=h((string)($article['title']??''))?>"><label for="lead">Lead</label><textarea id="lead" name="lead"><?=h((string)($article['lead']??''))?></textarea><h2>Cikktörzs</h2><div id="editor-toolbar"><span class="ql-formats"><select class="ql-header"><option value="1"></option><option value="2"></option><option selected></option></select></span><span class="ql-formats"><button class="ql-bold" type="button"></button><button class="ql-italic" type="button"></button><button class="ql-underline" type="button"></button><button class="ql-blockquote" type="button"></button></span><span class="ql-formats"><button class="ql-list" value="ordered" type="button"></button><button class="ql-list" value="bullet" type="button"></button></span><span class="ql-formats"><button class="ql-link" type="button"></button><button class="ql-image" type="button"></button><button class="ql-clean" type="button"></button></span><span class="ql-formats"><button type="button" id="insert-recommendation" title="Cikk- vagy könyvajánló">+ Ajánló</button></span></div><div id="rich-editor" aria-label="Cikktörzs"></div><div class="ui-editor-seo-wrap"><details class="ed-seo"><summary>SEO és keresőoptimalizálás</summary><div class="ed-seo-body"><p class="hint">A keresőtalálatokhoz megadható cím és leírás.</p><label>SEO cím</label><input name="seo_title" maxlength="255" value="<?=h($meta['seo_title']??'')?>"><label>Meta leírás</label><textarea name="seo_description" maxlength="500"><?=h($meta['seo_description']??'')?></textarea><p class="hint">Javasolt metaleírás: 140–160 karakter.</p></div></details></div></div><aside class="ui-editor-aside"><div class="panel ui-editor-settings"><details class="ed-fieldset ui-editor-meta" data-meta-index="1" open><summary>Webhelyek</summary><p class="hint">Jelöld ki, mely webhelyekhez tartozzon a cikk. Az elsődleges webhely megmarad; a cikk tartalma és státusza közös.</p><?php if(!article_sites_ready($pdo)):?><p class="hint">Több webhely kiválasztásához előbb futtasd az <a href="/migrate.php">adatbázis-migrációt</a>.</p><?php endif;?><div class="ed-site-options"><?php foreach($sites as $s):?><label class="ed-check"><input type="checkbox" class="ed-site-check" name="site_ids[]" value="<?=(int)$s['id']?>" <?=in_array((int)$s['id'],$selectedSites??[],true)?'checked':''?> <?=((int)($article['site_id']??0)===(int)$s['id'])?'data-primary="1"':''?>> <span><?=h($s['name'])?><?=((int)($article['site_id']??0)===(int)$s['id'])?' (elsődleges)':''?></span></label><?php endforeach;?></div></details><details class="ed-fieldset ui-editor-meta" data-meta-index="2" open><summary>Kategóriák</summary><div class="ed-category-list"><?php foreach(($terms??[]) as $t):if($t['type']!=='category')continue;?><label class="ed-check ed-term" data-site="<?=(int)$t['site_id']?>"><input type="checkbox" name="terms[]" value="<?=(int)$t['id']?>" <?=in_array((int)$t['id'],$selectedTerms??[],true)?'checked':''?>> <span><?=h($t['name'])?><?php if((int)$t['site_id']===0):?> <small>(globális)</small><?php endif;?></span></label><?php endforeach;?></div><details class="ed-add"><summary>+ Új kategória hozzáadása</summary><label for="new-categories">Új kategória neve</label><input id="new-categories" name="new_categories" placeholder="Például: Hírek"><p class="hint">Az új kategória a cikk mentésekor jön létre.</p></details></details><details class="ed-fieldset ui-editor-meta" data-meta-index="3" open><summary>Címkék</summary><div id="ed-tag-chips" class="ed-tag-chips"></div><label for="ed-tag-search" class="hint">Keresés vagy új címke</label><input id="ed-tag-search" autocomplete="off" placeholder="Kezdj gépelni…" role="combobox" aria-expanded="false" aria-controls="ed-tag-suggestions"><div id="ed-tag-suggestions" class="ed-tag-suggestions" role="listbox" hidden></div><p class="hint">Válassz a javaslatokból, vagy Enterrel hozz létre új címkét.</p><input type="hidden" name="tag_names" id="ed-tag-names"><div id="ed-tag-existing"><?php foreach(($terms??[]) as $t):if($t['type']!=='tag')continue;?><label data-site="<?=(int)$t['site_id']?>" data-label="<?=h($t['name'])?>"><input type="checkbox" name="terms[]" value="<?=(int)$t['id']?>" <?=in_array((int)$t['id'],$selectedTerms??[],true)?'checked':''?>></label><?php endforeach;?></div></details><details class="ed-featured" open><summary>Kiemelt kép és megosztás</summary>
<div class="ui-editor-featured-body">
<label for="og-image-url">Kiemelt kép</label>
<div class="ui-featured-current" id="featured-current">
<img class="ui-featured-preview" id="featured-image-preview" src="<?=h($meta['og_image_url']??'')?>" alt="A cikk kiválasztott kiemelt képe" <?=empty($meta['og_image_url'])?'hidden':''?>>
<p id="featured-empty-message" <?=!empty($meta['og_image_url'])?'hidden':''?>>Még nincs kiemelt kép. Válassz a médiatárból vagy tölts fel újat.</p>
</div>
<div class="ui-featured-actions">
<button type="button" class="ui-btn ui-btn-primary" id="featured-choose">Kép választása a médiatárból</button>
<button type="button" class="ui-btn ui-btn-secondary" id="featured-clear" <?=empty($meta['og_image_url'])?'hidden':''?>>Eltávolítás</button>
</div>
<details class="ui-featured-url"><summary>Vagy külső kép URL megadása</summary><label for="og-image-url">Kép URL-je</label><input type="url" id="og-image-url" name="og_image_url" maxlength="1000" placeholder="https://..." value="<?=h($meta['og_image_url']??'')?>"></details>
<p class="hint">A kiválasztott képet a cikk mentésekor rögzítjük. A külső kép csak hivatkozásként kerül a cikkhez.</p>
</div></details><label for="status" id="publishing-status">Státusz</label><select id="status" name="status"><option value="draft" <?=($article['status']??'draft')==='draft'?'selected':''?>>Piszkozat</option><?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?><option value="published" <?=($article['status']??'')==='published'?'selected':''?>>Publikált</option><?php endif;?><option value="review" <?=($article['status']??'')==='review'?'selected':''?>>Ellenőrzésre vár</option><?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?><option value="scheduled" <?=($article['status']??'')==='scheduled'?'selected':''?>>Időzített</option><?php endif;?></select>
<div class="ui-editor-schedule" id="editor-schedule-fields" hidden><label for="schedule-at">Publikálás időpontja (Budapest)</label><input type="datetime-local" name="schedule_at" id="schedule-at" value="<?=h($scheduleValue)?>"><p class="hint">Az időpont a webhely időzónájában értendő, a feldolgozó UTC-ben tárolja. A közzétételhez futó ütemező szükséges.</p></div><button type="button" id="preview-button" class="ui-editor-preview">Előnézet új lapon ↗</button><?php if($sourceUrl!==''):?>
<div class="ui-source-style-status"><strong>Importált forrásarculat</strong>
<span id="source-style-label"><?=!empty($sourceStyle['css'])?'Mentett stílus: '.h($sourceStyle['host']):'A forrás CSS-e még nincs rögzítve'?></span><?php $siteDesign=is_array($sourceStyle['site_design']??null)?$sourceStyle['site_design']:[];?>
<?php if($siteDesign):?>
<div class="ui-source-design-summary">
<?php if(!empty($siteDesign['logo_url'])):?><img src="<?=h((string)$siteDesign['logo_url'])?>" alt="A forrásoldal logója" loading="lazy"><?php endif;?>
<strong><?=h((string)($siteDesign['name']??$sourceStyle['host']??''))?></strong>
<small>Főmenü: <?=count($siteDesign['header_links']??[])?> elem · Lábléc: <?=count($siteDesign['footer_links']??[])?> hivatkozás</small>
</div><?php endif;?>
<?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?>
<button type="button" id="source-style-refresh" data-id="<?=$id?>" data-csrf="<?=h(csrf())?>" class="ui-btn ui-btn-secondary">Forrásstílus letöltése / frissítése</button>
<?php endif;?>
<a class="ui-btn ui-btn-secondary" href="/source-preview.php?id=<?=$id?>" target="_blank" rel="noopener">Mentett piszkozat forrásnézete ↗</a><a class="ui-btn ui-btn-secondary" href="/source-site-design.php?id=<?=$id?>">Forráswebhely arculatának kezelése</a>
</div><?php endif;?><p id="local-save" class="hint"></p><button type="submit" class="editor-save-button ui-btn ui-btn-primary" <?=!$sites?'disabled':''?>>Cikk mentése</button></div><?php if($revisions):?><div class="panel"><h3>Korábbi verziók</h3><?php foreach($revisions as $r):?><div class="revision"><small>#<?=(int)$r['revision_number']?> · <?=h($r['created_at'])?></small><button type="submit" name="action" value="restore" formaction="/editor.php?id=<?=$id?>" onclick="document.getElementById('revision-id').value='<?=(int)$r['id']?>';true">Visszaállítás</button></div><?php endforeach;?><input type="hidden" name="revision_id" id="revision-id"></div><?php endif;?></aside></form><div class="ui-editor-mobile-action"><button form="editor-form" type="submit" class="ui-btn ui-btn-primary" <?=!$sites?'disabled':''?>>Cikk mentése</button></div></main><dialog id="featured-media-picker" class="ui-featured-picker" aria-labelledby="featured-picker-title">
<div class="ui-featured-picker-head"><div><h2 id="featured-picker-title">Kiemelt kép kiválasztása</h2><p>Válassz egy meglévő képet, vagy tölts fel újat.</p></div><button type="button" id="featured-picker-close" class="ui-picker-close" aria-label="Ablak bezárása">✕</button></div>
<div class="ui-featured-picker-tools">
<label for="featured-picker-search">Képek keresése</label>
<input id="featured-picker-search" type="search" autocomplete="off" placeholder="Fájlnév, alternatív szöveg vagy webhely…" >
<button type="button" id="featured-picker-upload" class="ui-btn ui-btn-secondary">Új kép feltöltése</button>
</div>
<div class="ui-featured-picker-grid" id="featured-picker-grid" role="group" aria-label="Médiatár képei">
<?php foreach($editorMedia as $asset):$mediaUrl='/media-file.php?id='.(int)$asset['id'];?>
<button type="button" class="ui-picker-asset" data-featured-url="<?=h($mediaUrl)?>" data-featured-site="<?=(int)$asset['site_id']?>" data-featured-filter="<?=h($asset['original_name'].' '.$asset['alt_text'].' '.$asset['site_name'])?>" aria-label="<?=h($asset['original_name'].' kiválasztása')?>">
<img src="<?=h($mediaUrl)?>" alt="<?=h($asset['alt_text'])?>" loading="lazy"><span><?=h($asset['original_name'])?></span><small><?=h($asset['site_name'])?></small>
</button><?php endforeach;?>
</div>
<p class="ui-picker-no-results" id="featured-picker-no-results" hidden>Nincs egyező kép. Módosítsd a keresést, vagy tölts fel újat.</p>
<p class="ui-picker-feedback" id="featured-picker-feedback" role="status" aria-live="polite"></p>
<input type="file" id="featured-picker-file" hidden accept="image/jpeg,image/png,image/webp,image/gif" aria-label="Képfájl kiválasztása">
<div class="ui-featured-picker-footer"><span id="featured-picker-count"><?=count($editorMedia)?> kép érhető el</span><div class="ui-featured-picker-buttons"><button type="button" class="ui-btn ui-btn-secondary" id="featured-picker-more" hidden>További képek</button><button type="button" class="ui-btn ui-btn-secondary" id="featured-picker-cancel">Mégse</button></div></div>
</dialog>
<dialog id="ed-modal" class="ed-modal"><h2 id="ed-modal-title"></h2><div id="ed-modal-body"></div><div id="ed-modal-actions"></div></dialog><script src="/assets/editor.js" defer></script><script type="application/json" id="source-style-data"><?=json_encode(['css'=>(string)($sourceStyle['css']??''),'source'=>$sourceUrl,'host'=>(string)($sourceStyle['host']??''),'site_design'=>(array)($sourceStyle['site_design']??[])],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script><script type="application/json" id="initial-content"><?=json_encode($initial,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script></body></html>
