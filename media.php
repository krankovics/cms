<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
$u=require_user();$error='';$items=[];$sites=[];$allowed=[];$primary=[];$total=0;$page=max(1,min(10000,(int)($_GET['page']??1)));$perPage=48;$search=trim((string)($_GET['q']??''));$mediaQueueSummary=[];$hasMediaImport=false;
try{
 $pdo=db();$hasMediaImport=(bool)$pdo->query("SHOW TABLES LIKE 'media_import_sources'")->fetchColumn()&&(bool)$pdo->query("SHOW TABLES LIKE 'article_media_uses'")->fetchColumn()&&(bool)$pdo->query("SHOW TABLES LIKE 'media_import_queue'")->fetchColumn();$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();$allowed=allowed_resource_sites($pdo,$u,'media');$primary=allowed_primary_sites($pdo,$u);
 $site=(int)($_POST['site_id']??($primary[0]??0));
 $viewSite=(int)($_GET['view_site']??0);
 if($viewSite&&!in_array($viewSite,$allowed,true))throw new RuntimeException('Nincs jogosultság a kiválasztott médiatárhoz.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  if(!in_array($u['role'],['platform_admin','site_admin','editor','author'],true))throw new RuntimeException('Nincs jogosultság.');
  if(($_POST['action']??'')==='queue_imported'){
   if(!in_array($site,$primary,true))throw new RuntimeException('Csak a saját webhely korábbi importjai indíthatók.');
   // Queue only records not yet processed, 500 at a time, safely repeatable.
   $q=$pdo->prepare("INSERT IGNORE INTO media_import_queue(site_id,article_id,status) SELECT m.site_id,m.article_id,'pending' FROM import_article_map m LEFT JOIN media_import_queue q ON q.site_id=m.site_id AND q.article_id=m.article_id WHERE m.site_id=? AND m.article_id IS NOT NULL AND q.article_id IS NULL ORDER BY m.id LIMIT 500");
   $q->execute([$site]);$queued=$q->rowCount();
   header('Location: /media.php?'.http_build_query(['view_site'=>$site,'saved'=>'queued','count'=>$queued]));exit;
  }
  if(($_POST['action']??'')==='retry_failed'){
   if(!in_array($site,$primary,true))throw new RuntimeException('Nincs jogosultság a webhelyhez.');
   $pdo->prepare("UPDATE media_import_queue SET status='pending',attempts=0,next_offset=0,found_count=0,imported_count=0,failed_count=0,last_error='',available_at=NOW() WHERE site_id=? AND (status='failed' OR (status='done' AND failed_count>0))")->execute([$site]);
   header('Location: /media.php?view_site='.$site.'&saved=retried');exit;
  }
  if(($_POST['action']??'')==='update_meta'){
   $assetId=max(0,(int)($_POST['asset_id']??0));
   $q=$pdo->prepare('SELECT site_id FROM media_assets WHERE id=?');$q->execute([$assetId]);$assetSite=(int)$q->fetchColumn();
   if(!$assetSite||!in_array($assetSite,$primary,true))throw new RuntimeException('A kép szerkesztéséhez nincs jogosultság.');
   $alt=mb_substr(trim((string)($_POST['alt']??'')),0,500);
   $credit=mb_substr(trim((string)($_POST['credit']??'')),0,255);
   $holder=mb_substr(trim((string)($_POST['copyright_holder']??'')),0,255);
   $rights=(string)($_POST['rights_status']??'unknown');
   if(!in_array($rights,['unknown','cleared','restricted'],true))throw new RuntimeException('Érvénytelen jogállapot.');
   $pdo->prepare('UPDATE media_assets SET alt_text=? WHERE id=? AND site_id=?')->execute([$alt,$assetId,$assetSite]);
   $pdo->prepare('UPDATE media_import_sources SET photographer=?,copyright_holder=?,rights_status=? WHERE media_id=? AND site_id=?')->execute([$credit,$holder,$rights,$assetId,$assetSite]);
   // Keep per-article caption history. Only update a selected use, not every caption.
   $useId=max(0,(int)($_POST['use_id']??0));
   if($useId){
    $caption=mb_substr(trim((string)($_POST['caption']??'')),0,1800);
    $pdo->prepare('UPDATE article_media_uses SET caption=? WHERE id=? AND media_id=? AND site_id=?')->execute([$caption,$useId,$assetId,$assetSite]);
   }
   header('Location: /media.php?'.http_build_query(['view_site'=>$viewSite,'q'=>$search,'page'=>$page,'saved'=>'meta']));exit;
  }
  if(($_POST['action']??'')==='update_alt'){
   $assetId=max(0,(int)($_POST['asset_id']??0));
   $q=$pdo->prepare('SELECT site_id FROM media_assets WHERE id=?');$q->execute([$assetId]);$assetSite=(int)$q->fetchColumn();
   if(!$assetSite||!in_array($assetSite,$primary,true))throw new RuntimeException('A kép módosításához nincs szerkesztési jogod.');
   $alt=mb_substr(trim((string)($_POST['alt']??'')),0,500);
   $pdo->prepare('UPDATE media_assets SET alt_text=? WHERE id=? AND site_id=?')->execute([$alt,$assetId,$assetSite]);
   header('Location: /media.php?'.http_build_query(['view_site'=>$viewSite,'q'=>$search,'page'=>$page,'saved'=>'alt']));exit;
  }
  if(!in_array($site,$primary,true))throw new RuntimeException('Csak a saját webhely médiatárába tölthetsz fel.');
  $f=$_FILES['image']??null;
  if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>10485760||$f['size']<1)throw new RuntimeException('Maximum 10 MB méretű kép tölthető fel.');
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
  if(!isset($extensions[$mime])||!getimagesize($f['tmp_name']))throw new RuntimeException('Nem támogatott képformátum.');
  $check=$pdo->prepare('SELECT id FROM sites WHERE id=?');$check->execute([$site]);if(!$check->fetch())throw new RuntimeException('Érvénytelen webhely.');
  $dir=__DIR__.'/storage/media';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('A tárhely nem írható.');
  $name=bin2hex(random_bytes(20)).'.'.$extensions[$mime];
  if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Feltöltési hiba.');
  chmod($dir.'/'.$name,0640);
  try{$q=$pdo->prepare('INSERT INTO media_assets(site_id,uploader_id,original_name,storage_path,mime_type,size_bytes,alt_text) VALUES(?,?,?,?,?,?,?)');$q->execute([$site,$u['id'],mb_substr(basename($f['name']),0,255),$name,$mime,$f['size'],mb_substr(trim((string)($_POST['alt']??'')),0,500)]);}catch(Throwable $e){unlink($dir.'/'.$name);throw $e;}
  header('Location: /media.php?view_site='.$site.'&saved=1');exit;
 }
 $where=' WHERE m.site_id IN ('.implode(',',array_map('intval',$allowed?:[-1])).')';$params=[];
 if($viewSite){$where.=' AND m.site_id=?';$params[]=$viewSite;}
 if($search!==''){
  $like='%'.addcslashes(mb_substr($search,0,100),'\\%_').'%';
  if($hasMediaImport){$where.=" AND (m.original_name LIKE ? OR m.alt_text LIKE ? OR s.name LIKE ? OR EXISTS(SELECT 1 FROM media_import_sources im WHERE im.media_id=m.id AND (im.source_host LIKE ? OR im.source_url LIKE ? OR im.photographer LIKE ? OR im.copyright_holder LIKE ?)) OR EXISTS(SELECT 1 FROM article_media_uses am JOIN articles a ON a.id=am.article_id WHERE am.media_id=m.id AND (am.caption LIKE ? OR am.alt_text LIKE ? OR am.photo_credit LIKE ? OR a.title LIKE ?)))";$params=array_merge($params,array_fill(0,11,$like));}
  else{$where.=' AND (m.original_name LIKE ? OR m.alt_text LIKE ? OR s.name LIKE ?)';$params=array_merge($params,[$like,$like,$like]);}
}
 $count=$pdo->prepare('SELECT COUNT(*) FROM media_assets m JOIN sites s ON s.id=m.site_id'.$where);$count->execute($params);$total=(int)$count->fetchColumn();
 $lastPage=max(1,(int)ceil($total/$perPage));$page=min($page,$lastPage);$offset=($page-1)*$perPage;
 if($hasMediaImport){$q=$pdo->prepare("SELECT m.id,m.site_id,m.original_name,m.alt_text,m.mime_type,m.size_bytes,m.created_at,s.name site_name,im.source_url,im.source_host,im.source_article_url,im.photographer,im.copyright_holder,im.rights_status,im.width_px,im.height_px,(SELECT am.caption FROM article_media_uses am WHERE am.media_id=m.id AND am.caption<>'' ORDER BY am.id LIMIT 1) caption,(SELECT am.id FROM article_media_uses am WHERE am.media_id=m.id ORDER BY am.id LIMIT 1) use_id,(SELECT COUNT(*) FROM article_media_uses am WHERE am.media_id=m.id) article_uses FROM media_assets m JOIN sites s ON s.id=m.site_id LEFT JOIN media_import_sources im ON im.media_id=m.id".$where.' ORDER BY m.id DESC LIMIT '.$perPage.' OFFSET '.$offset);}
 else{$q=$pdo->prepare('SELECT m.id,m.site_id,m.original_name,m.alt_text,m.mime_type,m.size_bytes,m.created_at,s.name site_name FROM media_assets m JOIN sites s ON s.id=m.site_id'.$where.' ORDER BY m.id DESC LIMIT '.$perPage.' OFFSET '.$offset);}
 $q->execute($params);$items=$q->fetchAll();
 if($viewSite&&$hasMediaImport){
  $q=$pdo->prepare('SELECT status,COUNT(*) cnt,SUM(imported_count) imported,SUM(failed_count) failures FROM media_import_queue WHERE site_id=? GROUP BY status');
  $q->execute([$viewSite]);foreach($q->fetchAll() as $v)$mediaQueueSummary[$v['status']]=$v;
 }
}catch(Throwable $e){error_log('media: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázis-migráció szükséges.':$e->getMessage();$sites=$sites??[];$items=[];}
$canUpload=in_array($u['role'],['platform_admin','site_admin','editor','author'],true)&&!empty($primary);
$pagerParams=['view_site'=>$viewSite??0,'q'=>$search];
function media_page_link(array $p,int $page):string{return '/media.php?'.http_build_query(array_merge($p,['page'=>$page]));}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Médiatár | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page ui-media-page" id="main-content"><header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalomkezelés</p><h1 class="ui-page-title">Médiatár</h1><p class="ui-page-description">Képek kezelése, keresése és webhelyenkénti böngészése.</p></div>
<?php if($canUpload):?><div class="ui-page-actions"><button type="button" class="ui-btn ui-btn-primary" data-ui-dialog-open="media-upload-dialog"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Kép feltöltése</button></div><?php endif;?></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status"><?=match($_GET['saved']){'alt'=>'Az alternatív szöveget mentettük.','meta'=>'A kép metaadatait mentettük.','queued'=>(int)($_GET['count']??0).' cikk képfeldolgozását sorba állítottuk.','retried'=>'A sikertelen feldolgozások újraindulnak.',default=>'A képet sikeresen feltöltöttük.'}?></p><?php endif;?>
<form method="get" class="ui-filterbar" aria-label="Médiatár szűrése">
<label class="ui-field ui-field-search">Keresés <input type="search" name="q" value="<?=h($search)?>" placeholder="Fájlnév, alt, képaláírás, fotós, cikk, forrás…"></label>
<label class="ui-field">Webhely <select name="view_site"><option value="0">Összes elérhető</option><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=($viewSite??0)===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label>
<button type="submit" class="ui-btn ui-btn-primary">Szűrés</button><?php if($search!==''||($viewSite??0)):?><a href="/media.php" class="ui-btn ui-btn-ghost">Alaphelyzet</a><?php endif;?>
</form>
<style>.ui-media-page .ui-panel button{max-width:100%;white-space:normal;overflow-wrap:anywhere}.ui-media-page .ui-inspector-detail dd{overflow-wrap:anywhere}.ui-media-page .ui-media-alt-form textarea{max-width:100%;min-width:0;width:100%}</style>
<?php if($canUpload&&!$hasMediaImport):?><p class="ui-notice">Az új képimport az <a href="/migrate.php">adatbázis-migráció</a> után aktiválódik. A korábbi médiatár továbbra is használható.</p><?php endif;?>
<?php if($canUpload&&$hasMediaImport):?><section class="ui-panel" style="margin:12px 0;padding:14px"><strong>Korábbi importok képeinek feldolgozása</strong>
<p class="ui-page-description">A képek a meglévő import workerrel, a háttérben töltődnek le. A forrás és a képaláírás megmarad, az ismétlődő képek deduplikálódnak. A képek felhasználási joga alapból ellenőrizetlen.</p>
<?php if($viewSite&&in_array($viewSite,$primary,true)):?>
<form method="post" style="display:flex;gap:9px;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$viewSite?>">
<button class="ui-btn ui-btn-secondary" type="submit" name="action" value="queue_imported">Korábban importált cikkek képeinek feldolgozása (max. 500)</button>
<button class="ui-btn ui-btn-secondary" type="submit" name="action" value="retry_failed">Sikertelen képfeldolgozások újraindítása</button></form>
<?php foreach($mediaQueueSummary as $state=>$v):?><span style="display:inline-block;margin:8px 12px 0 0"><?=h($state)?>: <?=(int)$v['cnt']?> cikk</span><?php endforeach;?>
<?php else:?><p class="ui-page-description">Válassz egy olyan webhelyet a szűrőben, amelyhez szerkesztési hozzáférésed van.</p><?php endif;?></section><?php endif;?>
<div class="ui-result-bar"><span><strong><?=(int)$total?></strong> kép</span><span><?=$total?($offset+1).'–'.min($total,$offset+$perPage).' / '.$total:'Nincs találat'?></span></div>
<div class="ui-workbench ui-media-workbench">
<section class="ui-workbench-list" aria-label="Képek">
<?php if($items):?><div class="ui-gallery" id="media-list">
<?php foreach($items as $asset):$url='/media-file.php?id='.(int)$asset['id'];?>
<article class="ui-asset">
<button type="button" class="ui-asset-preview" aria-label="<?=h($asset['original_name'])?> részletei" data-panel-open="media-inspector" data-asset-id="<?=(int)$asset['id']?>" aria-expanded="false" aria-controls="media-inspector" data-asset-editable="<?=in_array((int)$asset['site_id'],$primary,true)?'1':'0'?>" data-asset-name="<?=h($asset['original_name'])?>" data-asset-alt="<?=h($asset['alt_text'])?>" data-asset-size="<?=round(((int)$asset['size_bytes'])/1024)?> KB" data-asset-type="<?=h($asset['mime_type'])?>" data-asset-site="<?=h($asset['site_name'])?>" data-asset-date="<?=h($asset['created_at'])?>" data-asset-url="<?=h($url)?>" data-asset-source="<?=h((string)($asset['source_url']??''))?>" data-asset-host="<?=h((string)($asset['source_host']??''))?>" data-asset-source-article="<?=h((string)($asset['source_article_url']??''))?>" data-asset-credit="<?=h((string)($asset['photographer']??''))?>" data-asset-copyright="<?=h((string)($asset['copyright_holder']??''))?>" data-asset-rights="<?=h((string)($asset['rights_status']??'unknown'))?>" data-asset-caption="<?=h((string)($asset['caption']??''))?>" data-asset-use-id="<?=(int)($asset['use_id']??0)?>" data-asset-uses="<?=(int)($asset['article_uses']??0)?>" data-asset-resolution="<?=((int)($asset['width_px']??0)).' × '.((int)($asset['height_px']??0))?>">
<img src="<?=h($url)?>" loading="lazy" alt="<?=h($asset['alt_text'])?>"></button>
<div class="ui-asset-info"><strong title="<?=h($asset['original_name'])?>"><?=h($asset['original_name'])?></strong><small><?=h($asset['site_name'])?></small><button type="button" class="ui-asset-copy" data-copy-url="<?=h($url)?>">Kép URL másolása</button></div>
</article>
<?php endforeach;?></div>
<?php else:?><div class="ui-panel ui-empty-state"><strong>Nincs megjeleníthető kép.</strong><p>A szűrők módosításával vagy új kép feltöltésével folytathatod.</p><?php if($canUpload):?><button type="button" class="ui-btn ui-btn-secondary" data-ui-dialog-open="media-upload-dialog">Kép feltöltése</button><?php endif;?></div><?php endif;?>
<?php if($total>$perPage):?><nav class="ui-pagination" aria-label="Médiatár lapozása"><span><?=$page?> / <?=$lastPage?>. oldal</span><div class="ui-pagination-links"><?php if($page>1):?><a href="<?=h(media_page_link($pagerParams,$page-1))?>" rel="prev">← Előző</a><?php endif;?><?php if($page<$lastPage):?><a href="<?=h(media_page_link($pagerParams,$page+1))?>" rel="next">Következő →</a><?php endif;?></div></nav><?php endif;?>
</section>
<aside class="ui-panel ui-workbench-pane ui-media-drawer" id="media-inspector" data-ui-pane aria-label="Kép részletei">
<div class="ui-pane-header"><div><h2>Részletek</h2><p>A kiválasztott médiaelem adatai</p></div><button type="button" class="ui-pane-close" data-panel-close aria-label="Panel bezárása">✕</button></div>
<div class="ui-pane-body">
<div class="ui-inspector-empty ui-text-muted">Válassz ki egy képet a galériából az előnézethez.</div>
<div class="ui-inspector-detail" hidden><img class="ui-detail-image" src="" alt="" data-asset-detail-image>
<dl class="ui-detail-data"><dt>Fájlnév</dt><dd data-asset-detail-name></dd><dt>Webhely</dt><dd data-asset-detail-site></dd><dt>Méret</dt><dd data-asset-detail-size></dd><dt>Formátum</dt><dd data-asset-detail-type></dd><dt>Feltöltés</dt><dd data-asset-detail-date></dd><dt>Alternatív szöveg</dt><dd data-asset-detail-alt></dd><dt>Forráswebhely</dt><dd data-asset-detail-host></dd><dt>Eredeti kép URL</dt><dd data-asset-detail-source style="overflow-wrap:anywhere"></dd><dt>Forráscikk</dt><dd data-asset-detail-source-article style="overflow-wrap:anywhere"></dd><dt>Képaláírás</dt><dd data-asset-detail-caption></dd><dt>Fotós / kredit</dt><dd data-asset-detail-credit></dd><dt>Jogtulajdonos</dt><dd data-asset-detail-copyright></dd><dt>Felhasználási jog</dt><dd data-asset-detail-rights></dd><dt>Felbontás</dt><dd data-asset-detail-resolution></dd><dt>Cikkbeli felhasználások</dt><dd data-asset-detail-uses></dd></dl>
<?php if($canUpload):?><form method="post" class="ui-media-alt-form" data-asset-meta-form hidden><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="update_meta"><input type="hidden" name="asset_id" value=""><input type="hidden" name="use_id" value="">
<label class="ui-field">Alternatív szöveg <input name="alt" maxlength="500"></label>
<label class="ui-field">Képaláírás a kapcsolódó cikkben <textarea name="caption" rows="3" maxlength="1800"></textarea></label>
<label class="ui-field">Fotós / kredit <input name="credit" maxlength="255"></label>
<label class="ui-field">Jogtulajdonos <input name="copyright_holder" maxlength="255"></label>
<label class="ui-field">Felhasználási jog <select name="rights_status"><option value="unknown">Ellenőrizetlen</option><option value="cleared">Engedélyezett</option><option value="restricted">Korlátozott</option></select></label>
<button class="ui-btn ui-btn-primary" type="submit">Metaadatok mentése</button></form>
<form method="post" class="ui-media-alt-form" data-asset-alt-form hidden><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="update_alt"><input type="hidden" name="asset_id" value=""><label class="ui-field">Alternatív szöveg módosítása <input type="text" name="alt" maxlength="500" placeholder="Mi látható a képen?"></label><button type="submit" class="ui-btn ui-btn-primary">Alt szöveg mentése</button></form><?php endif;?>
<button type="button" class="ui-btn ui-btn-secondary" data-asset-detail-copy>URL másolása</button></div></div>
</aside>
</div><button type="button" class="ui-pane-dim" data-ui-pane-dim aria-label="Panel bezárása"></button>
<?php if($canUpload):?><dialog class="ui-upload-dialog" id="media-upload-dialog" aria-labelledby="media-upload-title">
<div class="ui-pane-header"><div><h2 id="media-upload-title">Kép feltöltése</h2><p>JPEG, PNG, WebP vagy GIF · maximum 10 MB</p></div><button class="ui-pane-close ui-dialog-close" type="button" data-ui-dialog-close aria-label="Bezárás">✕</button></div>
<form method="post" enctype="multipart/form-data" id="media-upload-form">
<input type="hidden" name="csrf" value="<?=h(csrf())?>">
<div class="ui-pane-body"><label class="ui-field">Célwebhely <select name="site_id" required><?php foreach($sites as $s):if(!in_array((int)$s['id'],$primary,true))continue;?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label>
<label class="ui-field">Képfájl <input type="file" id="media-upload-file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required data-ui-file-preview><small data-ui-file-name-for="media-upload-file">Nincs kiválasztott fájl</small></label>
<label class="ui-field">Alternatív szöveg <input name="alt" maxlength="500" placeholder="Mi látható a képen?"></label></div>
<div class="ui-upload-progress" id="media-upload-progress" aria-live="polite" hidden><progress max="100" value="0"></progress><span>Feltöltés indítása…</span></div>
<div class="ui-pane-footer"><button class="ui-btn ui-btn-primary" type="submit">Feltöltés</button><button class="ui-btn ui-btn-secondary" type="button" data-ui-dialog-close>Mégse</button></div></form></dialog><?php endif;?>
</main><script>
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('[data-asset-name]').forEach(button=>button.addEventListener('click',()=>{
  const panel=document.getElementById('media-inspector');
  document.querySelectorAll('.ui-asset').forEach(card=>card.classList.remove('is-selected'));
  button.closest('.ui-asset')?.classList.add('is-selected');
  panel.querySelector('.ui-inspector-empty').hidden=true;panel.querySelector('.ui-inspector-detail').hidden=false;
  const val=(name)=>button.dataset[name]||'';
  const img=panel.querySelector('[data-asset-detail-image]');img.src=val('assetUrl');img.alt=val('assetAlt');
  for(const key of ['name','site','size','type','date','alt','host','source','sourceArticle','caption','credit','copyright','rights','resolution','uses']){
   const attr=key.replace(/[A-Z]/g,c=>'-'+c.toLowerCase());
   const field=panel.querySelector('[data-asset-detail-'+attr+']');
   if(field)field.textContent=val('asset'+key.charAt(0).toUpperCase()+key.slice(1))||'Nincs megadva';
  }
  panel.querySelector('[data-asset-detail-copy]').dataset.copyUrl=val('assetUrl');
  const metaForm=panel.querySelector('[data-asset-meta-form]');
  if(metaForm){
   metaForm.hidden=val('assetEditable')!=='1'||!val('assetSource');
   metaForm.querySelector('[name=asset_id]').value=val('assetId');
   metaForm.querySelector('[name=use_id]').value=val('assetUseId');
   for(const key of ['alt','caption','credit','copyright'])metaForm.querySelector('[name='+key+']').value=val('asset'+key.charAt(0).toUpperCase()+key.slice(1));
   metaForm.querySelector('[name=rights_status]').value=['unknown','cleared','restricted'].includes(val('assetRights'))?val('assetRights'):'unknown';
  }
  const altForm=panel.querySelector('[data-asset-alt-form]');
  if(altForm){
   altForm.hidden=val('assetEditable')!=='1'||!!val('assetSource');
   altForm.querySelector('[name=asset_id]').value=val('assetId');
   altForm.querySelector('[name=alt]').value=val('assetAlt');
  }
 }));
 document.querySelector('[data-asset-detail-copy]')?.addEventListener('click',async e=>{
  const url=new URL(e.currentTarget.dataset.copyUrl,location.origin).href;
  try{await navigator.clipboard.writeText(url);e.currentTarget.textContent='Másolva';}
  catch{window.prompt('Kép URL-je:',url);}
 });
 const form=document.getElementById('media-upload-form');
 form?.addEventListener('submit',e=>{
  const file=form.querySelector('input[type=file]')?.files?.[0];
  if(!file||file.size>10485760){e.preventDefault();alert('Legfeljebb 10 MB méretű kép tölthető fel.');return;}
  if(typeof XMLHttpRequest==='undefined')return;
  e.preventDefault();
  const progress=document.getElementById('media-upload-progress');
  const bar=progress.querySelector('progress'),info=progress.querySelector('span'),submit=form.querySelector('button[type=submit]');
  const xhr=new XMLHttpRequest();
  xhr.open('POST',form.action||location.pathname,true);
  submit.disabled=true;progress.hidden=false;bar.value=0;info.textContent='Feltöltés…';
  xhr.upload.addEventListener('progress',ev=>{if(ev.lengthComputable){const value=Math.min(100,Math.round(ev.loaded/ev.total*100));bar.value=value;info.textContent='Feltöltés: '+value+'%';}});
  const fail=()=>{submit.disabled=false;info.textContent='A feltöltés sikertelen. Ellenőrizd a fájlt, vagy próbáld újra.';};
  xhr.addEventListener('load',()=>{
   const url=new URL(xhr.responseURL||location.href,location.href);
   if(xhr.status>=200&&xhr.status<300&&url.searchParams.has('saved'))location.assign(url.href);
   else fail();
  });
  xhr.addEventListener('error',fail);
  xhr.addEventListener('abort',fail);
  xhr.send(new FormData(form));
 });
});
</script></body></html>