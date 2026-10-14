<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/article-sites.php';
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
  if(!is_array($b)||!in_array($b['type']??'', ['paragraph','heading','quote','list','image','embed','richtext'],true))throw new RuntimeException('Nem támogatott blokk.');
  $text=(string)($b['text']??'');
  if(mb_strlen($text)>300000)throw new RuntimeException('Túl hosszú blokk.');
  if(in_array($b['type'],['image','embed'],true) && (!filter_var($text,FILTER_VALIDATE_URL)||!in_array(parse_url($text,PHP_URL_SCHEME),['https','http'],true)))throw new RuntimeException('Érvénytelen URL.');
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
try{
 $pdo=db();
 if($id){$q=$pdo->prepare('SELECT * FROM articles WHERE id=?');$q->execute([$id]);$article=$q->fetch();if(!$article)throw new RuntimeException('A cikk nem található.');if(!in_array((int)$article['site_id'],allowed_primary_sites($pdo,$u),true))throw new RuntimeException('Nincs jogosultság a cikk szerkesztéséhez.');if($u['role']==='author'&&(int)$article['author_id']!==(int)$u['id'])throw new RuntimeException('Csak a saját cikkeidet szerkesztheted.');}
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
  if(!in_array($status,in_array($u['role'],['platform_admin','site_admin','editor'],true)?['draft','review','published']:['draft','review'],true))throw new RuntimeException('Nem támogatott státusz.');
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
  $publishedAt=$status==='published'?date('Y-m-d H:i:s'):null;
  $pdo->beginTransaction();
  try{
   if($id){
    $q=$pdo->prepare('SELECT updated_at FROM articles WHERE id=? FOR UPDATE');$q->execute([$id]);$current=$q->fetchColumn();
    if(!$current||$current!==(string)($_POST['expected_updated_at']??''))throw new RuntimeException('A cikket időközben módosították. Frissítsd az oldalt.');
    $q=$pdo->prepare('UPDATE articles SET title=?,lead=?,content_json=?,status=?,site_id=?,published_at=CASE WHEN ?=\'published\' THEN COALESCE(published_at,?) ELSE NULL END,updated_at=NOW() WHERE id=?');
    $q->execute([$title,$lead,$json,$status,$siteId,$status,$publishedAt,$id]);
   }else{
    $slug=ed_slug($title).'-'.bin2hex(random_bytes(3));
    $q=$pdo->prepare('INSERT INTO articles(site_id,author_id,title,slug,lead,content_json,status,published_at) VALUES(?,?,?,?,?,?,?,?)');
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
   $pdo->prepare('INSERT INTO article_meta(article_id,seo_title,seo_description) VALUES(?,?,?) ON DUPLICATE KEY UPDATE seo_title=VALUES(seo_title),seo_description=VALUES(seo_description)')->execute([$id,$seoTitle,$seoDescription]);
   $q=$pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM article_revisions WHERE article_id=?');$q->execute([$id]);$num=(int)$q->fetchColumn();
   $q=$pdo->prepare('INSERT INTO article_revisions(article_id,editor_id,revision_number,title,lead,content_json,status) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$id,$u['id'],$num,$title,$lead,$json,$status]);
   $q=$pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'article.save','article',?,?)");
   $q->execute([$u['id'],$id,json_encode(['revision'=>$num,'status'=>$status])]);
   $pdo->commit();header('Location: /editor.php?id='.$id.'&saved=1');exit;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $primaryIds=allowed_primary_sites($pdo,$u);$sites=array_values(array_filter($sites,fn($s)=>in_array((int)$s['id'],$primaryIds,true)));
 $selectedSites=$article?article_site_ids($pdo,$article):[(int)($sites[0]['id']??0)];
 if($article&&array_diff($selectedSites,$primaryIds))throw new RuntimeException('A cikk egy vagy több olyan webhelyen is szerepel, amelyhez nincs szerkesztési jogod. Kérj jogosultságot a rendszergazdától.');
 if($id){$q=$pdo->prepare('SELECT * FROM articles WHERE id=?');$q->execute([$id]);$article=$q->fetch();}
 $visibleSites=allowed_resource_sites($pdo,$u,'tags');
 $terms=$pdo->query('SELECT id,site_id,type,name FROM taxonomy_terms WHERE site_id IN ('.implode(',',array_map('intval',$visibleSites?:[-1])).') ORDER BY type,(site_id=0) DESC,name')->fetchAll();
 $selectedTerms=[];if($id){$q=$pdo->prepare('SELECT term_id FROM article_terms WHERE article_id=?');$q->execute([$id]);$selectedTerms=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));}
 $meta=[];if($id){$q=$pdo->prepare('SELECT * FROM article_meta WHERE article_id=?');$q->execute([$id]);$meta=$q->fetch()?:[];}
 $revisions=[];
 if($id){$q=$pdo->prepare('SELECT id,revision_number,created_at,status FROM article_revisions WHERE article_id=? ORDER BY revision_number DESC LIMIT 20');$q->execute([$id]);$revisions=$q->fetchAll();}
}catch(Throwable $e){error_log('comesy editor: '.$e->getMessage());$error=$e instanceof PDOException?'A cikk mentése adatbázishiba miatt nem sikerült. Ellenőrizd a Beállítások / Adatbázis-migráció oldalt. Hibakód: '.h((string)$e->getCode()):$e->getMessage();$sites=$sites??[];$terms=$terms??[];$selectedTerms=$selectedTerms??[];$meta=$meta??[];$selectedSites=$selectedSites??[];$revisions=$revisions??[];}
$initial=json_decode($article['content_json']??'[]',true);if(!is_array($initial))$initial=[];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>comesy Editor</title><link rel="stylesheet" href="/assets/ui.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css"><script src="/assets/ui.js" defer></script><script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" defer></script><style>body{margin:0;font-family:system-ui}.editor-layout{max-width:1120px;margin:auto;padding:22px}.editor-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:0}.editor-top h1{font-size:clamp(22px,3vw,30px);line-height:1.2;margin:4px 0 0;letter-spacing:-.025em}.editor-layout{padding-top:12px!important}.editor-form>.panel{margin-top:14px!important}.editor-form{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:20px}.panel{background:var(--surface);padding:22px;border:1px solid var(--line);border-radius:14px;margin-top:20px}.editor-form label{display:block;font-size:13px;font-weight:700;margin:14px 0 7px}.editor-form input,.editor-form select,.editor-form textarea{box-sizing:border-box;width:100%;padding:12px;font-size:16px}.editor-form textarea{min-height:95px;resize:vertical}.editor-form button,.editor-top a.btn{padding:11px 14px;border-radius:8px;background:#315fd6;color:#fff;border:0;font-weight:700}.block{border:1px solid var(--line);border-radius:9px;padding:12px;margin:12px 0}.block-header{display:flex;align-items:center;justify-content:space-between;gap:8px}.block-header button{background:transparent;border:1px solid var(--line);color:var(--ink);padding:6px}.block textarea{min-height:120px}.toolbar{display:flex;gap:8px;flex-wrap:wrap}.toolbar button{background:var(--bg);color:var(--ink);border:1px solid var(--line)}.error{padding:12px;background:#ffe5e5;color:#822}.hint{color:var(--muted);font-size:13px}.revision{display:flex;justify-content:space-between;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}.revision button{background:var(--bg);color:var(--ink);border:1px solid var(--line);padding:7px}@media(max-width:800px){.editor-form{grid-template-columns:1fr}.editor-layout{padding:14px}} .ql-container{font-size:17px;background:var(--surface);color:var(--ink);min-height:320px}.ql-editor{min-height:320px;line-height:1.7}.ql-toolbar{background:var(--surface);border-color:var(--line)!important}.ql-container{border-color:var(--line)!important}.comesy-global-header{position:relative;z-index:5}.mobile-nav{display:none}@media(max-width:950px){.mobile-nav{display:flex}.editor-layout{padding:14px}.ql-toolbar{position:sticky;top:0;z-index:4}} </style></head><body><?php require __DIR__.'/app/header.php'; ?><main class="editor-layout"><div class="editor-top"><div><h1><?= $id?'Cikk szerkesztése':'Új cikk' ?></h1></div></div><?php if(!$sites):?><p class="error">Nincs elérhető webhelyed. Kérj jogosultságot a rendszergazdától, vagy szuperadminként <a href="/sites.php">hozz létre webhelyet</a>.</p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><?php if(isset($_GET['saved'])):?><p class="success">A cikk és az új verzió mentve.</p><?php endif;?><form id="editor-form" class="editor-form" method="post"><div class="panel"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="expected_updated_at" value="<?=h((string)($article['updated_at']??''))?>"><input type="hidden" name="blocks" id="blocks-data"><label for="title">Cím</label><input id="title" name="title" maxlength="500" required value="<?=h((string)($article['title']??''))?>"><label for="lead">Lead</label><textarea id="lead" name="lead"><?=h((string)($article['lead']??''))?></textarea><h2>Cikktörzs</h2><p class="hint">Egyetlen gazdag szövegszerkesztő, formázásokkal, képekkel és később egyedi kiadói elemekkel.</p><div id="editor-toolbar"><span class="ql-formats"><select class="ql-header"><option value="1"></option><option value="2"></option><option selected></option></select></span><span class="ql-formats"><button class="ql-bold" type="button"></button><button class="ql-italic" type="button"></button><button class="ql-underline" type="button"></button><button class="ql-blockquote" type="button"></button></span><span class="ql-formats"><button class="ql-list" value="ordered" type="button"></button><button class="ql-list" value="bullet" type="button"></button></span><span class="ql-formats"><button class="ql-link" type="button"></button><button class="ql-image" type="button"></button><button class="ql-clean" type="button"></button></span><span class="ql-formats"><button type="button" id="insert-recommendation" title="Cikk- vagy könyvajánló">+ Ajánló</button></span></div><div id="rich-editor" aria-label="Cikktörzs"></div></div><aside><div class="panel"><section class="ed-fieldset"><h3>Webhelyek</h3><p class="hint">Jelöld ki, mely webhelyekhez tartozzon a cikk. Az elsődleges webhely megmarad; a cikk tartalma és státusza közös.</p><?php if(!article_sites_ready($pdo)):?><p class="hint">Több webhely kiválasztásához előbb futtasd az <a href="/migrate.php">adatbázis-migrációt</a>.</p><?php endif;?><div class="ed-site-options"><?php foreach($sites as $s):?><label class="ed-check"><input type="checkbox" class="ed-site-check" name="site_ids[]" value="<?=(int)$s['id']?>" <?=in_array((int)$s['id'],$selectedSites??[],true)?'checked':''?> <?=((int)($article['site_id']??0)===(int)$s['id'])?'data-primary="1"':''?>> <span><?=h($s['name'])?><?=((int)($article['site_id']??0)===(int)$s['id'])?' (elsődleges)':''?></span></label><?php endforeach;?></div></section><section class="ed-fieldset"><h3>Kategóriák</h3><div class="ed-category-list"><?php foreach(($terms??[]) as $t):if($t['type']!=='category')continue;?><label class="ed-check ed-term" data-site="<?=(int)$t['site_id']?>"><input type="checkbox" name="terms[]" value="<?=(int)$t['id']?>" <?=in_array((int)$t['id'],$selectedTerms??[],true)?'checked':''?>> <span><?=h($t['name'])?><?php if((int)$t['site_id']===0):?> <small>(globális)</small><?php endif;?></span></label><?php endforeach;?></div><details class="ed-add"><summary>+ Új kategória hozzáadása</summary><label for="new-categories">Új kategória neve</label><input id="new-categories" name="new_categories" placeholder="Például: Hírek"><p class="hint">Az új kategória a cikk mentésekor jön létre.</p></details></section><section class="ed-fieldset"><h3>Címkék</h3><div id="ed-tag-chips" class="ed-tag-chips"></div><label for="ed-tag-search" class="hint">Keresés vagy új címke</label><input id="ed-tag-search" autocomplete="off" placeholder="Kezdj gépelni…" role="combobox" aria-expanded="false" aria-controls="ed-tag-suggestions"><div id="ed-tag-suggestions" class="ed-tag-suggestions" role="listbox" hidden></div><p class="hint">Válassz a javaslatokból, vagy Enterrel hozz létre új címkét.</p><input type="hidden" name="tag_names" id="ed-tag-names"><div id="ed-tag-existing"><?php foreach(($terms??[]) as $t):if($t['type']!=='tag')continue;?><label data-site="<?=(int)$t['site_id']?>" data-label="<?=h($t['name'])?>"><input type="checkbox" name="terms[]" value="<?=(int)$t['id']?>" <?=in_array((int)$t['id'],$selectedTerms??[],true)?'checked':''?>></label><?php endforeach;?></div></section><details class="ed-seo"><summary>SEO és keresőoptimalizálás</summary><div class="ed-seo-body"><p class="hint">A keresőtalálatokhoz megadható cím és leírás.</p><label>SEO cím</label><input name="seo_title" maxlength="255" value="<?=h($meta['seo_title']??'')?>"><label>Meta leírás</label><textarea name="seo_description" maxlength="500"><?=h($meta['seo_description']??'')?></textarea><p class="hint">Javasolt metaleírás: 140–160 karakter.</p></div></details><p><a href="/taxonomy.php">Kategóriák és címkék</a> · <a href="/media.php">Médiatár</a></p><label for="status">Státusz</label><select id="status" name="status"><option value="draft" <?=($article['status']??'draft')==='draft'?'selected':''?>>Piszkozat</option><?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?><option value="published" <?=($article['status']??'')==='published'?'selected':''?>>Publikált</option><?php endif;?><option value="review" <?=($article['status']??'')==='review'?'selected':''?>>Ellenőrzésre vár</option></select><button type="button" id="preview-button" style="width:100%;margin-top:12px">Előnézet</button><p id="local-save" class="hint"></p><button type="submit" class="editor-save-button" style="width:100%;margin-top:20px" <?=!$sites?'disabled':''?>>Cikk mentése</button></div><?php if($revisions):?><div class="panel"><h3>Korábbi verziók</h3><?php foreach($revisions as $r):?><div class="revision"><small>#<?=(int)$r['revision_number']?> · <?=h($r['created_at'])?></small><button type="submit" name="action" value="restore" formaction="/editor.php?id=<?=$id?>" onclick="document.getElementById('revision-id').value='<?=(int)$r['id']?>';true">Visszaállítás</button></div><?php endforeach;?><input type="hidden" name="revision_id" id="revision-id"></div><?php endif;?></aside></form></main><dialog id="ed-modal" class="ed-modal"><h2 id="ed-modal-title"></h2><div id="ed-modal-body"></div><div id="ed-modal-actions"></div></dialog><script src="/assets/editor.js" defer></script><script type="application/json" id="initial-content"><?=json_encode($initial,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?></script></body></html>
