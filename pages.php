<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require_once __DIR__.'/app/rich-content.php';
$u=require_user();
if(!in_array($u['role'],['platform_admin','site_admin','editor'],true)){http_response_code(403);exit('Nincs oldalkezelési jogosultság.');}
$pdo=db();$allowed=allowed_primary_sites($pdo,$u);
$site=max(0,(int)($_REQUEST['site_id']??$allowed[0]??0));
if(!in_array($site,$allowed,true)){http_response_code(403);exit('Nem szerkeszthető webhely.');}
$error='';$notice='';$pages=[];$current=null;
try{
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('A munkamenet lejárt.');
  $id=max(0,(int)($_POST['id']??0));$action=(string)($_POST['action']??'save');
  if($action==='delete'){
   $pdo->prepare('DELETE FROM site_pages WHERE id=? AND site_id=?')->execute([$id,$site]);
   $pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'page.delete','site_page',?,?)")->execute([$u['id'],$id,json_encode(['site_id'=>$site])]);
   header('Location: /pages.php?site_id='.$site.'&saved=1');exit;
  }
  $title=trim((string)($_POST['title']??''));
  $slug=mb_strtolower(trim((string)($_POST['slug']??'')));
  if(mb_strlen($title)<2||mb_strlen($title)>190||!preg_match('/^[a-z0-9][a-z0-9-]{0,188}$/D',$slug))throw new RuntimeException('Érvénytelen oldalcím vagy azonosító.');
  $html=(string)($_POST['body_html']??'');
  if(strlen($html)>180000)throw new RuntimeException('Az oldal tartalma túl hosszú.');
  $html=cms_rich_sanitize_html($html);
  $status=($_POST['status']??'draft')==='published'?'published':'draft';
  if($id){
   $q=$pdo->prepare('UPDATE site_pages SET title=?,slug=?,body_html=?,status=?,updated_by=? WHERE id=? AND site_id=?');
   $q->execute([$title,$slug,$html,$status,$u['id'],$id,$site]);
   $check=$pdo->prepare('SELECT id FROM site_pages WHERE id=? AND site_id=?');
   $check->execute([$id,$site]);
   if(!$check->fetchColumn())throw new RuntimeException('Nem található oldal.');
  }else{
   $q=$pdo->prepare('INSERT INTO site_pages(site_id,title,slug,body_html,status,updated_by) VALUES(?,?,?,?,?,?)');
   $q->execute([$site,$title,$slug,$html,$status,$u['id']]);$id=(int)$pdo->lastInsertId();
  }
  $pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'page.save','site_page',?,?)")->execute([$u['id'],$id,json_encode(['site_id'=>$site,'status'=>$status])]);
  header('Location: /pages.php?site_id='.$site.'&edit='.$id.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT id,title,slug,status,updated_at FROM site_pages WHERE site_id=? ORDER BY updated_at DESC,id DESC');
 $q->execute([$site]);$pages=$q->fetchAll();
 if(isset($_GET['edit'])){
  $q=$pdo->prepare('SELECT * FROM site_pages WHERE site_id=? AND id=?');
  $q->execute([$site,(int)$_GET['edit']]);$current=$q->fetch()?:null;
 }
}catch(Throwable $e){$error=$e->getMessage();error_log('comesy pages: '.$e->getMessage());}
$sites=$pdo->query('SELECT id,name,slug FROM sites ORDER BY name')->fetchAll();
$selectedSite=null;foreach($sites as $one)if((int)$one['id']===$site)$selectedSite=$one;
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statikus oldalak | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script>
<style>.cs-pages{display:grid;grid-template-columns:minmax(240px,340px) minmax(0,1fr);gap:18px}.cs-page-list a{display:block;padding:12px;border-bottom:1px solid #dbe3eb}.cs-page-editor{min-width:0}.cs-page-visual{min-height:250px;padding:20px;background:#fff;color:#151c2b;border:1px solid #b8c7d8;border-radius:8px;outline-offset:3px;overflow-wrap:anywhere}.cs-page-visual:focus{outline:2px solid #517ba5}.cs-page-editor label{display:grid;gap:5px;margin-bottom:15px}.cs-page-editor input,.cs-page-editor select{min-height:45px;padding:10px}.cs-page-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:17px}.cs-page-code{display:none;width:100%;min-height:240px;font:14px/1.5 monospace;padding:12px}.cs-page-editor[data-mode="html"] .cs-page-code{display:block}.cs-page-editor[data-mode="html"] .cs-page-visual{display:none}@media(max-width:800px){.cs-pages{grid-template-columns:1fr}}</style></head><body><?php require __DIR__.'/app/header.php';?>
<main id="main-content" class="ui-page"><header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalomkezelés</p><h1 class="ui-page-title">Statikus oldalak</h1><p class="ui-page-description">Impresszum, adatvédelem és más önálló oldalak létrehozása, szerkesztése és publikálása webhelyenként.</p></div><div class="ui-page-actions"><a class="ui-btn ui-btn-secondary" href="/site-manage.php?site_id=<?=$site?>&amp;tab=pages">← Webhelykezelő</a></div></header>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="success" role="status">Az oldal módosításai mentve.</p><?php endif;?>
<form method="get" style="margin-bottom:20px"><label>Webhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=(int)$s['id']===$site?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label></form>
<div class="cs-pages"><section class="ui-panel cs-page-list"><h2>Oldalak</h2><a class="ui-btn ui-btn-secondary" href="/pages.php?site_id=<?=$site?>">+ Új oldal</a>
<?php if(!$pages):?><p>Még nincs statikus oldal.</p><?php endif;?>
<?php foreach($pages as $p):?><a href="/pages.php?site_id=<?=$site?>&amp;edit=<?=(int)$p['id']?>"><strong><?=h($p['title'])?></strong> · <?=h($p['status'])?><small style="display:block"><?=h($p['slug'])?> · <?=h($p['updated_at'])?></small></a><?php endforeach;?></section>
<section class="ui-panel cs-page-editor" id="cs-page-editor" data-mode="visual"><h2><?=$current?'Oldal szerkesztése':'Új oldal'?></h2>
<form method="post" id="cs-page-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="id" value="<?=(int)($current['id']??0)?>">
<label>Cím<input name="title" maxlength="190" required value="<?=h((string)($current['title']??''))?>" placeholder="Például: Impresszum"></label>
<label>URL-azonosító<input name="slug" maxlength="190" pattern="[a-z0-9][a-z0-9-]*" required value="<?=h((string)($current['slug']??''))?>" placeholder="impresszum"></label>
<label>Státusz<select name="status"><option value="draft" <?=($current['status']??'draft')==='draft'?'selected':''?>>Piszkozat</option><option value="published" <?=($current['status']??'')==='published'?'selected':''?>>Publikált</option></select></label>
<p><strong>Oldal tartalma</strong> · <button type="button" class="ui-btn ui-btn-secondary" id="cs-page-toggle">HTML nézet</button></p>
<div class="cs-page-visual pub-rich-content" contenteditable="true" role="textbox" aria-label="Oldal tartalma" aria-multiline="true" id="cs-page-visual"><?=cms_rich_sanitize_html((string)($current['body_html']??'<p>Ide írhatod az oldal tartalmát.</p>'))?></div>
<textarea class="cs-page-code" id="cs-page-code" name="body_html" aria-label="Oldal HTML-kódja"><?=h((string)($current['body_html']??'<p>Ide írhatod az oldal tartalmát.</p>'))?></textarea>
<div class="cs-page-actions"><button type="submit" class="ui-btn ui-btn-primary">Oldal mentése</button>
<?php if($current):?><a class="ui-btn ui-btn-secondary" target="_blank" rel="noopener" href="/public.php?site=<?=rawurlencode((string)($selectedSite['slug']??''))?>&amp;page=<?=rawurlencode($current['slug'])?>&amp;preview=1">Előnézet ↗</a><?php endif;?></div></form>
<?php if($current):?><form method="post" style="margin-top:15px" onsubmit="return confirm('Biztosan törlöd ezt az oldalt?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="id" value="<?=(int)$current['id']?>"><button type="submit" name="action" value="delete" class="ui-btn ui-btn-danger">Oldal törlése</button></form><?php endif;?>
</section></div></main><script>
(()=>{const form=document.getElementById('cs-page-form'),wrap=document.getElementById('cs-page-editor'),visual=document.getElementById('cs-page-visual'),code=document.getElementById('cs-page-code'),toggle=document.getElementById('cs-page-toggle');
if(!form)return;
function sync(){if(wrap.dataset.mode==='html')visual.innerHTML=code.value;else code.value=visual.innerHTML;}
toggle.addEventListener('click',()=>{sync();wrap.dataset.mode=wrap.dataset.mode==='html'?'visual':'html';toggle.textContent=wrap.dataset.mode==='html'?'Vizuális nézet':'HTML nézet';});
form.addEventListener('submit',()=>{if(wrap.dataset.mode!=='html')code.value=visual.innerHTML;});
})();
</script></body></html>