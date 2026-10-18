<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/ad-external-import.php';
$u=require_user();
if($u['role']!=='platform_admin'){http_response_code(403);exit('Csak platformadmin importálhat és kapcsolhat össze külső hirdetési zónákat.');}
$error='';$message='';$imports=[];$groups=[];$sites=[];$masters=[];$leaves=[];$leafTotal=0;
$page=max(1,(int)($_GET['page']??1));
$importId=max(0,(int)($_GET['import_id']??0));$folderId=max(0,(int)($_GET['folder']??0));
try{
 $pdo=db();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $action=(string)($_POST['action']??'');
  if($action==='upload'){
   if(!isset($_FILES['xlsx'])||!is_array($_FILES['xlsx'])||($_FILES['xlsx']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)
    throw new RuntimeException('Az XLSX feltöltés sikertelen. Ellenőrizd a PHP upload_max_filesize korlátját.');
   $file=$_FILES['xlsx'];$filename=(string)($file['name']??'');
   if(!preg_match('/\.xlsx$/i',$filename)||($file['size']??0)>15*1024*1024||!is_uploaded_file($file['tmp_name']))
    throw new RuntimeException('Csak legfeljebb 15 MB-os XLSX tölthető fel.');
   set_time_limit(120);
   $validated=external_validate_nodes(external_xlsx_rows($file['tmp_name']));
   $importId=external_import($pdo,$validated,$filename,hash_file('sha256',$file['tmp_name']),(int)$u['id']);
   header('Location: /ad-external.php?import_id='.$importId.'&imported=1');exit;
  }
  if($action==='map'){
   $mapId=max(0,(int)($_POST['map_id']??0));
   $siteId=max(0,(int)($_POST['site_id']??0));
   $enable=isset($_POST['enabled'])?1:0;
   if(!$mapId||($enable&&!$siteId))throw new RuntimeException('Aktiváláshoz válassz célwebhelyet.');
   $pdo->beginTransaction();
   try {
    $q=$pdo->prepare('SELECT id,import_id,site_id,folder_name FROM ad_external_sites WHERE id=? FOR UPDATE');
    $q->execute([$mapId]);$current=$q->fetch();if(!$current)throw new RuntimeException('Nem található a forráswebhely.');
    if($siteId){$q=$pdo->prepare('SELECT id FROM sites WHERE id=?');$q->execute([$siteId]);if(!$q->fetchColumn())throw new RuntimeException('Ismeretlen célwebhely.');}
    // New snapshots may replace older active versions but not unrelated folders in the same snapshot.
    if($enable)$pdo->prepare('UPDATE ad_external_sites SET enabled=0 WHERE site_id=? AND import_id<>?')
      ->execute([$siteId,(int)$current['import_id']]);
    $pdo->prepare('UPDATE ad_external_sites SET site_id=?,enabled=? WHERE id=?')
      ->execute([$siteId?:null,$enable,$mapId]);
    $pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)')
      ->execute([(int)$u['id'],'ad.external_map','ad_external_sites',$mapId,
       json_encode(['old_site_id'=>$current['site_id'],'new_site_id'=>$siteId?:null,'enabled'=>$enable],JSON_THROW_ON_ERROR)]);
    $pdo->commit();
   }catch(Throwable $e){$pdo->rollBack();throw $e;}
   header('Location: /ad-external.php?import_id='.(int)$current['import_id'].'&mapped=1');exit;
  }
  throw new RuntimeException('Ismeretlen művelet.');
 }
 $imports=$pdo->query('SELECT * FROM ad_external_imports ORDER BY id DESC LIMIT 30')->fetchAll();
 if(!$importId&&$imports)$importId=(int)$imports[0]['id'];
 $sites=$pdo->query('SELECT id,name,domain FROM sites ORDER BY name')->fetchAll();
 if($importId){
  $q=$pdo->prepare('SELECT s.*,COUNT(n.id) node_count,SUM(n.node_type="MASTER") master_count,SUM(n.node_type="LEAF") leaf_count
     FROM ad_external_sites s LEFT JOIN ad_external_nodes n ON n.import_id=s.import_id AND n.folder_source_id=s.folder_source_id
     WHERE s.import_id=? GROUP BY s.id ORDER BY s.folder_name');
  $q->execute([$importId]);$groups=$q->fetchAll();
  if($folderId){
   $q=$pdo->prepare('SELECT source_id,name,master_code_id,(SELECT COUNT(*) FROM ad_external_nodes c WHERE c.import_id=n.import_id AND c.folder_source_id=n.folder_source_id AND c.node_type="LEAF" AND c.is_slave=1 AND c.master_code_id=n.master_code_id) slaves
       FROM ad_external_nodes n WHERE n.import_id=? AND n.folder_source_id=? AND n.node_type="MASTER" ORDER BY n.name LIMIT 200');
   $q->execute([$importId,$folderId]);$masters=$q->fetchAll();
   $q=$pdo->prepare('SELECT COUNT(*) FROM ad_external_nodes WHERE import_id=? AND folder_source_id=? AND node_type="LEAF"');
   $q->execute([$importId,$folderId]);$leafTotal=(int)$q->fetchColumn();
   $page=min($page,max(1,(int)ceil($leafTotal/100)));
   $q=$pdo->prepare('SELECT source_id,name,leaf_code_id,is_slave,master_code_id FROM ad_external_nodes WHERE import_id=? AND folder_source_id=? AND node_type="LEAF" ORDER BY source_id LIMIT 100 OFFSET '.(($page-1)*100));
   $q->execute([$importId,$folderId]);$leaves=$q->fetchAll();
  }
 }
}catch(Throwable $e){error_log('ad-external: '.$e->getMessage());$error=$e instanceof PDOException?'A külső hirdetésmodul táblái még hiányoznak. Futtasd a migrációt.':$e->getMessage();}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AdOcean zónaimport | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script>
<style>
.external-page{max-width:1420px;margin:auto;padding:24px}.external-panel{background:var(--surface,#fff);border:1px solid var(--border,#dce0e7);border-radius:12px;padding:18px;margin:18px 0}.external-table-wrap{overflow:auto;max-height:620px}.external-table{width:100%;border-collapse:collapse;font-size:13px}.external-table th,.external-table td{padding:10px;border-bottom:1px solid #ddd;text-align:left;vertical-align:middle}.external-table tr:hover{background:rgba(120,120,120,.06)}.external-table select{min-width:220px;max-width:280px}.external-muted{font-size:13px;color:#67717e}.external-form{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.external-status{font-weight:bold}.external-tag{display:inline-block;border-radius:20px;padding:3px 8px;background:#e8edf4;color:#243247}.external-tag.active{background:#e2f5e8;color:#146531}.external-code{font-size:12px;overflow-wrap:anywhere}body.dark .external-table td{border-color:#384354}body.dark .external-tag{background:#303947;color:#e6eaf0}
</style></head><body><?php require __DIR__.'/app/header.php';?>
<main class="external-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Monetizáció / Külső adserver</p><h1 class="ui-page-title">AdOcean zónaimport</h1>
<p class="ui-page-description">MASTER/SLAVE hierarchia, webhely-hozzárendelés és ellenőrzött aktiválás. Az import alapból semmit nem jelenít meg.</p></div></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(isset($_GET['imported'])):?><p class="ui-notice success">Az XLSX feldolgozva. A webhelyek még nincsenek aktiválva.</p><?php endif;?>
<?php if(isset($_GET['mapped'])):?><p class="ui-notice success">A webhely-hozzárendelés mentve.</p><?php endif;?>
<section class="external-panel"><h2>1. AdOcean nodes.xlsx beolvasása</h2><p class="external-muted">Maximum 15 MB, 30 000 sor. Azonos tartalmú fájl újraimportálása nem hoz létre duplikátumokat. A forráskódot kizárólag adatként tároljuk.</p>
<form class="external-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="upload">
<input aria-label="AdOcean XLSX" type="file" name="xlsx" accept=".xlsx" required><button type="submit" class="ui-btn ui-btn-primary">Importálás (inaktív)</button></form></section>
<?php if($imports):?>
<section class="external-panel"><h2>2. Importált állományok</h2><div class="external-form">
<?php foreach($imports as $item):?><a class="ui-btn <?=$importId===(int)$item['id']?'ui-btn-primary':'ui-btn-secondary'?>" href="/ad-external.php?import_id=<?=(int)$item['id']?>">#<?=(int)$item['id']?> · <?=h($item['filename'])?> · <?=(int)$item['row_count']?> rekord</a><?php endforeach;?></div></section>
<?php endif;?>
<?php if($groups):?>
<section class="external-panel"><h2>3. Forráswebhelyek és aktiválás</h2>
<p class="external-muted">Az aktiválás csak a kiszolgálási adatokat teszi elérhetővé a jóváhagyott célwebhelyen. A frontend nem tölt be AdOcean-szkriptet automatikusan, és a hozzájárulás ellenőrzése továbbra is kötelező.</p>
<div class="external-table-wrap"><table class="external-table"><thead><tr><th>Forráswebhely</th><th>Pozíciók</th><th>comesy webhely</th><th>Állapot</th><th>Beállítás</th></tr></thead><tbody>
<?php foreach($groups as $g):?>
<tr><td><a href="/ad-external.php?import_id=<?=$importId?>&amp;folder=<?=(int)$g['folder_source_id']?>"><?=h($g['folder_name'])?></a><div class="external-muted">Forrás ID: <?=(int)$g['folder_source_id']?></div></td>
<td><?=(int)$g['master_count']?> MASTER<br><?=(int)$g['leaf_count']?> LEAF</td>
<td colspan="3"><form method="post" class="external-form">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="action" value="map"><input type="hidden" name="map_id" value="<?=(int)$g['id']?>">
<select name="site_id" aria-label="Célwebhely: <?=h($g['folder_name'])?>"><option value="0">Nincs hozzárendelve</option>
<?php foreach($sites as $s):?><option value="<?=(int)$s['id']?>" <?=(int)$g['site_id']===(int)$s['id']?'selected':''?>><?=h($s['name'])?> (<?=h($s['domain'])?>)</option><?php endforeach;?></select>
<label><input type="checkbox" name="enabled" value="1" <?=!empty($g['enabled'])?'checked':''?>> Engedélyezve</label>
<span class="external-tag <?=!empty($g['enabled'])?'active':''?>"><?=!empty($g['enabled'])?'Aktív':'Inaktív'?></span>
<button class="ui-btn ui-btn-secondary" type="submit">Mentés</button></form></td></tr>
<?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php if($folderId&&$masters!==[]):?>
<section class="external-panel"><h2>4. MASTER/SLAVE részletek</h2>
<p class="external-muted">Azonos forrásazonosítókra lehet hivatkozni az adapterben. A példák éles hirdetést nem indítanak.</p>
<div class="external-table-wrap"><table class="external-table"><thead><tr><th>MASTER node</th><th>MASTER-kód</th><th>SLAVE pozíciók</th></tr></thead><tbody>
<?php foreach($masters as $m):?><tr><td><?=h($m['name'])?><small class="external-muted"> #<?=(int)$m['source_id']?></small></td><td class="external-code"><?=h($m['master_code_id'])?></td><td><?=(int)$m['slaves']?></td></tr><?php endforeach;?>
</tbody></table></div>
<h3>LEAF pozíciók (<?=$leafTotal?>), oldal <?=$page?> / <?=max(1,(int)ceil($leafTotal/100))?></h3><div class="external-table-wrap"><table class="external-table"><thead><tr><th>Node ID</th><th>Név</th><th>Szerep</th></tr></thead><tbody>
<?php foreach($leaves as $l):?><tr><td><?=(int)$l['source_id']?></td><td><?=h($l['name'])?></td><td><?=!empty($l['is_slave'])?'SLAVE':'Önálló'?></td></tr><?php endforeach;?>
</tbody></table></div>
<div class="external-form" style="margin-top:12px">
<?php if($page>1):?><a class="ui-btn ui-btn-secondary" href="/ad-external.php?import_id=<?=$importId?>&amp;folder=<?=$folderId?>&amp;page=<?=$page-1?>">← Előző 100</a><?php endif;?>
<?php if($page*100<$leafTotal):?><a class="ui-btn ui-btn-secondary" href="/ad-external.php?import_id=<?=$importId?>&amp;folder=<?=$folderId?>&amp;page=<?=$page+1?>">Következő 100 →</a><?php endif;?>
</div></section>
<?php endif;?>
<section class="external-panel"><h2>Integráció</h2>
<p>A külső webhely az <code>/assets/ad-external.js</code> adaptert használhatja. Csak az adott oldalra kirakott LEAF-eket kérje le; egy MASTER összes SLAVE-jét egyszerre nem szabad automatikusan betölteni. A <code>consentGranted</code> jelzőt kizárólag a CMP megfelelő engedélye után szabad igazra állítani.</p>
<p><a href="https://github.com/krankovics/cms/blob/main/docs/adocean-import.md">Integrációs dokumentáció</a></p></section>
</main></body></html>