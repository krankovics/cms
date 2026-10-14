<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
$u=require_user();$error='';$items=[];$sites=[];$allowed=[];$primary=[];$total=0;$page=max(1,min(10000,(int)($_GET['page']??1)));$perPage=48;$search=trim((string)($_GET['q']??''));
try{
 $pdo=db();$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();$allowed=allowed_resource_sites($pdo,$u,'media');$primary=allowed_primary_sites($pdo,$u);
 $site=(int)($_POST['site_id']??($primary[0]??0));
 $viewSite=(int)($_GET['view_site']??0);
 if($viewSite&&!in_array($viewSite,$allowed,true))throw new RuntimeException('Nincs jogosultság a kiválasztott médiatárhoz.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  if(!in_array($u['role'],['platform_admin','site_admin','editor','author'],true))throw new RuntimeException('Nincs jogosultság.');
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
 if($search!==''){$where.=' AND (m.original_name LIKE ? OR m.alt_text LIKE ?)';$like='%'.mb_substr($search,0,100).'%';$params[]=$like;$params[]=$like;}
 $count=$pdo->prepare('SELECT COUNT(*) FROM media_assets m'.$where);$count->execute($params);$total=(int)$count->fetchColumn();
 $lastPage=max(1,(int)ceil($total/$perPage));$page=min($page,$lastPage);$offset=($page-1)*$perPage;
 $q=$pdo->prepare('SELECT m.id,m.site_id,m.original_name,m.alt_text,m.mime_type,m.size_bytes,m.created_at,s.name site_name FROM media_assets m JOIN sites s ON s.id=m.site_id'.$where.' ORDER BY m.id DESC LIMIT '.$perPage.' OFFSET '.$offset);
 $q->execute($params);$items=$q->fetchAll();
}catch(Throwable $e){error_log('media: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázis-migráció szükséges.':$e->getMessage();$sites=$sites??[];$items=[];}
$canUpload=in_array($u['role'],['platform_admin','site_admin','editor','author'],true)&&!empty($primary);
$pagerParams=['view_site'=>$viewSite??0,'q'=>$search];
function media_page_link(array $p,int $page):string{return '/media.php?'.http_build_query(array_merge($p,['page'=>$page]));}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Médiatár | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page" id="main-content"><header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalomkezelés</p><h1 class="ui-page-title">Médiatár</h1><p class="ui-page-description">Képek kezelése, keresése és webhelyenkénti böngészése.</p></div>
<?php if($canUpload):?><div class="ui-page-actions"><button type="button" class="ui-btn ui-btn-primary" data-ui-dialog-open="media-upload-dialog"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Kép feltöltése</button></div><?php endif;?></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status"><?=$_GET['saved']==='alt'?'Az alternatív szöveget mentettük.':'A képet sikeresen feltöltöttük.'?></p><?php endif;?>
<form method="get" class="ui-filterbar" aria-label="Médiatár szűrése">
<label class="ui-field ui-field-search">Keresés <input type="search" name="q" value="<?=h($search)?>" placeholder="Fájlnév vagy alternatív szöveg…"></label>
<label class="ui-field">Webhely <select name="view_site"><option value="0">Összes elérhető</option><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=($viewSite??0)===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label>
<button type="submit" class="ui-btn ui-btn-primary">Szűrés</button><?php if($search!==''||($viewSite??0)):?><a href="/media.php" class="ui-btn ui-btn-ghost">Alaphelyzet</a><?php endif;?>
</form>
<div class="ui-result-bar"><span><strong><?=(int)$total?></strong> kép</span><span><?=$total?($offset+1).'–'.min($total,$offset+$perPage).' / '.$total:'Nincs találat'?></span></div>
<div class="ui-workbench">
<section class="ui-workbench-list" aria-label="Képek">
<?php if($items):?><div class="ui-gallery" id="media-list">
<?php foreach($items as $asset):$url='/media-file.php?id='.(int)$asset['id'];?>
<article class="ui-asset">
<button type="button" class="ui-asset-preview" aria-label="<?=h($asset['original_name'])?> részletei" data-panel-open="media-inspector" data-asset-name="<?=h($asset['original_name'])?>" data-asset-alt="<?=h($asset['alt_text'])?>" data-asset-size="<?=round(((int)$asset['size_bytes'])/1024)?> KB" data-asset-type="<?=h($asset['mime_type'])?>" data-asset-site="<?=h($asset['site_name'])?>" data-asset-date="<?=h($asset['created_at'])?>" data-asset-url="<?=h($url)?>">
<img src="<?=h($url)?>" loading="lazy" alt="<?=h($asset['alt_text'])?>"></button>
<div class="ui-asset-info"><strong title="<?=h($asset['original_name'])?>"><?=h($asset['original_name'])?></strong><small><?=h($asset['site_name'])?></small><button type="button" class="ui-asset-copy" data-copy-url="<?=h($url)?>">URL másolása</button></div>
</article>
<?php endforeach;?></div>
<?php else:?><div class="ui-panel ui-empty-state"><strong>Nincs megjeleníthető kép.</strong><p>A szűrők módosításával vagy új kép feltöltésével folytathatod.</p><?php if($canUpload):?><button type="button" class="ui-btn ui-btn-secondary" data-ui-dialog-open="media-upload-dialog">Kép feltöltése</button><?php endif;?></div><?php endif;?>
<?php if($total>$perPage):?><nav class="ui-pagination" aria-label="Médiatár lapozása"><span><?=$page?> / <?=$lastPage?>. oldal</span><div class="ui-pagination-links"><?php if($page>1):?><a href="<?=h(media_page_link($pagerParams,$page-1))?>" rel="prev">← Előző</a><?php endif;?><?php if($page<$lastPage):?><a href="<?=h(media_page_link($pagerParams,$page+1))?>" rel="next">Következő →</a><?php endif;?></div></nav><?php endif;?>
</section>
<aside class="ui-panel ui-workbench-pane" id="media-inspector" data-ui-pane aria-label="Kép részletei">
<div class="ui-pane-header"><div><h2>Részletek</h2><p>A kiválasztott médiaelem adatai</p></div><button type="button" class="ui-pane-close" data-panel-close aria-label="Panel bezárása">✕</button></div>
<div class="ui-pane-body">
<div class="ui-inspector-empty ui-text-muted">Válassz ki egy képet a galériából az előnézethez.</div>
<div class="ui-inspector-detail" hidden><img class="ui-detail-image" src="" alt="" data-asset-detail-image>
<dl class="ui-detail-data"><dt>Fájlnév</dt><dd data-asset-detail-name></dd><dt>Webhely</dt><dd data-asset-detail-site></dd><dt>Méret</dt><dd data-asset-detail-size></dd><dt>Formátum</dt><dd data-asset-detail-type></dd><dt>Feltöltés</dt><dd data-asset-detail-date></dd><dt>Alternatív szöveg</dt><dd data-asset-detail-alt></dd></dl>
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
<div class="ui-pane-footer"><button class="ui-btn ui-btn-primary" type="submit">Feltöltés</button><button class="ui-btn ui-btn-secondary" type="button" data-ui-dialog-close>Mégse</button></div></form></dialog><?php endif;?>
</main><script>
document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('[data-asset-name]').forEach(button=>button.addEventListener('click',()=>{
  const panel=document.getElementById('media-inspector');
  panel.querySelector('.ui-inspector-empty').hidden=true;panel.querySelector('.ui-inspector-detail').hidden=false;
  const val=(name)=>button.dataset[name]||'';
  const img=panel.querySelector('[data-asset-detail-image]');img.src=val('assetUrl');img.alt=val('assetAlt');
  for(const key of ['name','site','size','type','date','alt'])panel.querySelector('[data-asset-detail-'+key+']').textContent=val('asset'+key.charAt(0).toUpperCase()+key.slice(1))||'Nincs megadva';
  panel.querySelector('[data-asset-detail-copy]').dataset.copyUrl=val('assetUrl');
 }));
 document.querySelector('[data-asset-detail-copy]')?.addEventListener('click',async e=>{
  const url=new URL(e.currentTarget.dataset.copyUrl,location.origin).href;
  try{await navigator.clipboard.writeText(url);e.currentTarget.textContent='Másolva';}
  catch{window.prompt('Kép URL-je:',url);}
 });
 const form=document.getElementById('media-upload-form');
 form?.addEventListener('submit',e=>{const file=form.querySelector('input[type=file]')?.files?.[0];if(file&&file.size>10485760){e.preventDefault();alert('Legfeljebb 10 MB méretű kép tölthető fel.');}});
});
</script></body></html>