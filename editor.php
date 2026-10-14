<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
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
  if(!is_array($b)||!in_array($b['type']??'', ['paragraph','heading','quote','list','image','embed'],true))throw new RuntimeException('Nem támogatott blokk.');
  $text=(string)($b['text']??'');
  if(mb_strlen($text)>20000)throw new RuntimeException('Túl hosszú blokk.');
  if(in_array($b['type'],['image','embed'],true) && (!filter_var($text,FILTER_VALIDATE_URL)||!in_array(parse_url($text,PHP_URL_SCHEME),['https','http'],true)))throw new RuntimeException('Érvénytelen URL.');
  $out[]=['type'=>$b['type'],'text'=>$text];
 }
 return $out;
}
$error='';$message='';$article=null;$id=max(0,(int)($_GET['id']??0));
try{
 $pdo=db();
 if($id){$q=$pdo->prepare('SELECT * FROM articles WHERE id=?');$q->execute([$id]);$article=$q->fetch();if(!$article)throw new RuntimeException('A cikk nem található.');}
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
  $siteId=(int)($_POST['site_id']??0);
  $q=$pdo->prepare('SELECT id FROM sites WHERE id=?');$q->execute([$siteId]);if(!$q->fetch())throw new RuntimeException('Válassz webhelyet.');
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
   $q=$pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM article_revisions WHERE article_id=?');$q->execute([$id]);$num=(int)$q->fetchColumn();
   $q=$pdo->prepare('INSERT INTO article_revisions(article_id,editor_id,revision_number,title,lead,content_json,status) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$id,$u['id'],$num,$title,$lead,$json,$status]);
   $q=$pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'article.save','article',?,?)");
   $q->execute([$u['id'],$id,json_encode(['revision'=>$num,'status'=>$status])]);
   $pdo->commit();header('Location: /editor.php?id='.$id.'&saved=1');exit;
  }catch(Throwable $e){$pdo->rollBack();throw $e;}
 }
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 if(!$sites&&$u['role']==='platform_admin'){
  $pdo->prepare('INSERT INTO sites(name,domain,slug) VALUES(?,?,?)')->execute(['comesy demo','demo.local','comesy-demo']);
  $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 }
 if($id){$q=$pdo->prepare('SELECT * FROM articles WHERE id=?');$q->execute([$id]);$article=$q->fetch();}
 $revisions=[];
 if($id){$q=$pdo->prepare('SELECT id,revision_number,created_at,status FROM article_revisions WHERE article_id=? ORDER BY revision_number DESC LIMIT 20');$q->execute([$id]);$revisions=$q->fetchAll();}
}catch(Throwable $e){error_log('comesy editor: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázishiba. Ellenőrizd a migrációt.':$e->getMessage();$sites=$sites??[];$revisions=$revisions??[];}
$initial=json_decode($article['content_json']??'[]',true);if(!is_array($initial))$initial=[];
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>comesy Editor</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{margin:0;font-family:system-ui}.editor-layout{max-width:1120px;margin:auto;padding:22px}.editor-top{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}.editor-form{display:grid;grid-template-columns:minmax(0,1fr) 250px;gap:20px}.panel{background:var(--surface);padding:22px;border:1px solid var(--line);border-radius:14px;margin-top:20px}.editor-form label{display:block;font-size:13px;font-weight:700;margin:14px 0 7px}.editor-form input,.editor-form select,.editor-form textarea{box-sizing:border-box;width:100%;padding:12px;font-size:16px}.editor-form textarea{min-height:95px;resize:vertical}.editor-form button,.editor-top a.btn{padding:11px 14px;border-radius:8px;background:#315fd6;color:#fff;border:0;font-weight:700}.block{border:1px solid var(--line);border-radius:9px;padding:12px;margin:12px 0}.block-header{display:flex;align-items:center;justify-content:space-between;gap:8px}.block-header button{background:transparent;border:1px solid var(--line);color:var(--ink);padding:6px}.block textarea{min-height:120px}.toolbar{display:flex;gap:8px;flex-wrap:wrap}.toolbar button{background:var(--bg);color:var(--ink);border:1px solid var(--line)}.error{padding:12px;background:#ffe5e5;color:#822}.hint{color:var(--muted);font-size:13px}.revision{display:flex;justify-content:space-between;gap:8px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}.revision button{background:var(--bg);color:var(--ink);border:1px solid var(--line);padding:7px}@media(max-width:800px){.editor-form{grid-template-columns:1fr}.editor-layout{padding:14px}}</style></head><body><main class="editor-layout"><div class="editor-top"><div><a href="/?view=articles">← comesy Editor</a><h1><?= $id?'Cikk szerkesztése':'Új cikk' ?></h1></div><button type="button" class="theme-toggle" data-theme-toggle>Sötét mód ◐</button></div><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><?php if(isset($_GET['saved'])):?><p class="success">A cikk és az új verzió mentve.</p><?php endif;?><form id="editor-form" class="editor-form" method="post"><div class="panel"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="expected_updated_at" value="<?=h((string)($article['updated_at']??''))?>"><input type="hidden" name="blocks" id="blocks-data"><label for="title">Cím</label><input id="title" name="title" maxlength="500" required value="<?=h((string)($article['title']??''))?>"><label for="lead">Lead</label><textarea id="lead" name="lead"><?=h((string)($article['lead']??''))?></textarea><h2>Tartalomblokkok</h2><p class="hint">A szövegek strukturált JSON-blokkokként kerülnek mentésre, HTML nélkül.</p><div id="blocks"></div><div class="toolbar"><button type="button" data-add="paragraph">+ Bekezdés</button><button type="button" data-add="heading">+ Címsor</button><button type="button" data-add="quote">+ Idézet</button><button type="button" data-add="list">+ Lista</button><button type="button" data-add="image">+ Kép URL</button><button type="button" data-add="embed">+ Hivatkozás</button></div></div><aside><div class="panel"><label for="site">Webhely</label><select id="site" name="site_id" required><?php foreach($sites as $s):?><option value="<?=(int)$s['id']?>" <?=((int)($article['site_id']??0)===(int)$s['id'])?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select><label for="status">Státusz</label><select id="status" name="status"><option value="draft" <?=($article['status']??'draft')==='draft'?'selected':''?>>Piszkozat</option><?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?><option value="published" <?=($article['status']??'')==='published'?'selected':''?>>Publikált</option><?php endif;?><option value="review" <?=($article['status']??'')==='review'?'selected':''?>>Ellenőrzésre vár</option></select><button type="button" id="preview-button" style="width:100%;margin-top:12px">Előnézet</button><p id="local-save" class="hint"></p><button type="submit" style="width:100%;margin-top:20px" <?=!$sites?'disabled':''?>>Cikk mentése</button></div><?php if($revisions):?><div class="panel"><h3>Korábbi verziók</h3><?php foreach($revisions as $r):?><div class="revision"><small>#<?=(int)$r['revision_number']?> · <?=h($r['created_at'])?></small><button type="submit" name="action" value="restore" formaction="/editor.php?id=<?=$id?>" onclick="document.getElementById('revision-id').value='<?=(int)$r['id']?>';return confirm('Visszaállítod ezt a verziót új piszkozatként?')">Visszaállítás</button></div><?php endforeach;?><input type="hidden" name="revision_id" id="revision-id"></div><?php endif;?></aside></form></main><script>const initial=<?=json_encode($initial,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)?>;const root=document.getElementById('blocks');function block(type='paragraph',text=''){const item=document.createElement('div');item.className='block';item.dataset.type=type;const bar=document.createElement('div');bar.className='block-header';const label=document.createElement('strong');label.textContent={paragraph:'Bekezdés',heading:'Címsor',quote:'Idézet',list:'Lista',image:'Kép URL',embed:'Hivatkozás'}[type]||type;const actions=document.createElement('div');for(const [name,handler] of [['↑',()=>item.previousElementSibling?.before(item)],['↓',()=>item.nextElementSibling?.after(item)],['×',()=>item.remove()]]){const b=document.createElement('button');b.type='button';b.textContent=name;b.addEventListener('click',handler);actions.append(b)}bar.append(label,actions);const input=document.createElement('textarea');input.value=text;input.placeholder='Írd ide a tartalmat…';item.append(bar,input);root.append(item)}for(const b of initial)if(['paragraph','heading','quote','list','image','embed'].includes(b.type))block(b.type,b.text||'');document.querySelectorAll('[data-add]').forEach(b=>b.addEventListener('click',()=>block(b.dataset.add)));document.getElementById('editor-form').addEventListener('submit',()=>{document.getElementById('blocks-data').value=JSON.stringify([...root.querySelectorAll('.block')].map(b=>({type:b.dataset.type,text:b.querySelector('textarea').value})))})const editorForm=document.getElementById('editor-form');const draftKey='comesy-editor-<?= (int)$id ?>';const serializeBlocks=()=>Array.from(root.querySelectorAll('.block'),el=>({type:el.dataset.type,text:el.querySelector('textarea').value}));const preview=document.getElementById('preview-button');preview.addEventListener('click',()=>{const win=window.open('','_blank');if(!win)return;const doc=win.document;doc.title='comesy előnézet';const heading=doc.createElement('h1');heading.textContent=document.getElementById('title').value;doc.body.append(heading);const lead=doc.createElement('p');lead.textContent=document.getElementById('lead').value;doc.body.append(lead);for(const b of serializeBlocks()){const tag=({heading:'h2',quote:'blockquote',image:'img',embed:'a'})[b.type]||'p';const el=doc.createElement(tag);if(b.type==='image'){el.src=b.text;el.alt='Cikk kép';el.style.maxWidth='100%'}else if(b.type==='embed'){el.href=b.text;el.textContent=b.text;el.rel='noopener noreferrer'}else el.textContent=b.text;doc.body.append(el)}});setInterval(()=>{try{localStorage.setItem(draftKey,JSON.stringify({title:document.getElementById('title').value,lead:document.getElementById('lead').value,blocks:serializeBlocks(),time:Date.now()}));document.getElementById('local-save').textContent='Helyi biztonsági másolat mentve'}catch(err){}},15000);</script></body></html>
