<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/taxonomy-actions.php';
$u=require_user();$error='';$message='';$terms=[];$sites=[];$allowed=[];$canManage=false;$selected=null;$site=0;$type='category';
try{
 $pdo=db();$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $allowed=allowed_resource_sites($pdo,$u,'tags');
 $defaultSite=(int)(array_values(array_filter($allowed,fn($v)=>$v>0))[0]??0);
 $site=(int)($_REQUEST['site_id']??$defaultSite);$type=($_REQUEST['type']??'category')==='tag'?'tag':'category';
 if(!in_array($site,$allowed,true))$site=$defaultSite;
 $canManage=in_array($u['role'],['platform_admin','site_admin','editor'],true)&&($u['role']==='platform_admin'||($site>0&&in_array($site,allowed_primary_sites($pdo,$u),true)));
 if($_SERVER['REQUEST_METHOD']==='POST'){
  tx_action($pdo,$u,$allowed,$site,$type);
  header('Location: /taxonomy.php?site_id='.$site.'&type='.$type.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT t.id,t.name,t.slug,t.site_id,m.description,m.parent_id,(SELECT COUNT(*) FROM article_terms a WHERE a.term_id=t.id) usage_count FROM taxonomy_terms t LEFT JOIN taxonomy_term_meta m ON m.term_id=t.id WHERE t.site_id=? AND t.type=? ORDER BY t.name');
 $q->execute([$site,$type]);$terms=$q->fetchAll();
 $legacyCount=(int)$pdo->query('SELECT COUNT(*) FROM taxonomy_terms WHERE site_id=0')->fetchColumn();
 $editId=max(0,(int)($_GET['edit']??0));$selected=null;
 foreach($terms as $term)if((int)$term['id']===$editId)$selected=$term;
}catch(Throwable $e){error_log('taxonomy: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázis-migráció szükséges vagy már létezik ez a név.':$e->getMessage();$sites=$sites??[];$terms=[];}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kategóriák és címkék | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head>
<body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalomkezelés</p><h1 class="ui-page-title">Kategóriák és címkék</h1><p class="ui-page-description">Besorolások, hierarchia és közös fogalmak kezelése.</p></div>
<?php if($canManage):?><div class="ui-page-actions"><button type="button" class="ui-btn ui-btn-primary ui-compact-action" data-panel-open="taxonomy-editor" aria-controls="taxonomy-editor" aria-expanded="false"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Új <?=$type==='tag'?'címke':'kategória'?></button></div><?php endif;?></header>
<nav class="ui-tabbar" aria-label="Fogalomtár típusa"><a <?=$type==='category'?'class="is-active" aria-current="page"':''?> href="?type=category&amp;site_id=<?=$site?>">Kategóriák</a><a <?=$type==='tag'?'class="is-active" aria-current="page"':''?> href="?type=tag&amp;site_id=<?=$site?>">Címkék</a></nav>
<?php if(isset($_GET['saved'])):?><p class="ui-notice success" role="status">A módosítások mentése sikeres.</p><?php endif;?>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if(!$canManage&&$allowed):?><p class="ui-notice">A kiválasztott fogalomtár csak olvasható. Másik szerkeszthető webhelyet a szűrőben választhatsz.</p><?php endif;?>
<form method="get" class="ui-filterbar" aria-label="Fogalomtár szűrése">
<input type="hidden" name="type" value="<?=h($type)?>">
<label class="ui-field ui-field-search">Keresés <input type="search" data-ui-search="taxonomy-list" placeholder="Kategória, címke vagy slug…"></label>
<label class="ui-field">Webhely <select name="site_id" onchange="this.form.submit()"><?php if($u['role']==='platform_admin'):?><option value="0" <?=$site===0?'selected':''?>>Globális fogalomtár</option><?php endif;?><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label>
</form>
<div class="ui-result-bar"><span><strong data-ui-visible-for="taxonomy-list"><?=count($terms)?></strong> elem</span><span><?=$type==='category'?'Kategóriák':'Címkék'?></span></div>
<div class="ui-workbench">
<section class="ui-panel ui-data-panel ui-workbench-list" aria-label="Meglévő elemek">
<?php if($canManage):?><form id="taxonomy-bulk" method="post" onsubmit="return confirm('Biztosan törlöd a kijelölt elemeket? A hozzájuk tartozó cikkbesorolások is megszűnnek.')">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="action" value="bulk_delete">
<?php if($terms):?><div class="ui-selection-controls"><label><input type="checkbox" id="taxonomy-select-all"> Látható elemek kijelölése</label><span id="taxonomy-selected-count">0 kijelölve</span></div>
<div class="ui-bulk-action" id="taxonomy-bulk-bar" hidden><strong id="taxonomy-bulk-count">0 kijelölve</strong><button class="ui-btn ui-btn-danger" type="submit" id="taxonomy-delete-selected" disabled>Kijelöltek törlése</button></div><?php endif;?>
<?php endif;?>
<div class="ui-taxonomy-list" id="taxonomy-list">
<?php foreach($terms as $term):?>
<div class="ui-taxonomy-row term" data-search="<?=h($term['name'].' '.$term['slug'])?>">
 <?php if($canManage):?><input type="checkbox" class="taxonomy-select" name="term_ids[]" value="<?=(int)$term['id']?>" aria-label="<?=h($term['name'])?> kijelölése"><?php endif;?>
 <details><summary><span class="ui-taxonomy-name"><?=h($term['name'])?></span><span class="ui-taxonomy-usage"><?=(int)$term['usage_count']?> cikk</span></summary>
 <div class="ui-taxonomy-meta"><p>Slug: <?=h($term['slug'])?></p><?php if($term['description']):?><p><?=h($term['description'])?></p><?php endif;?>
 <div class="ui-taxonomy-actions"><?php if($canManage):?><a href="?type=<?=h($type)?>&amp;site_id=<?=$site?>&amp;edit=<?=(int)$term['id']?>">Szerkesztés</a><?php endif;?><a href="/articles.php?term_id=<?=(int)$term['id']?>">Kapcsolódó cikkek</a></div></div></details>
</div>
<?php endforeach;?>
</div>
<?php if($canManage):?></form><?php endif;?>
<p class="ui-panel-state" data-ui-empty-for="taxonomy-list" <?=$terms?'hidden':''?>>Nincs a keresésnek megfelelő elem.</p>
</section>
<?php if($canManage):?>
<aside class="ui-panel ui-workbench-pane" data-ui-pane id="taxonomy-editor" data-panel-default-open="<?=$selected?'1':'0'?>" aria-label="<?=$selected?'Fogalom szerkesztése':'Új fogalom'?>">
<div class="ui-pane-header"><div><h2><?=$selected?'Fogalom szerkesztése':'Új '.($type==='tag'?'címke':'kategória')?></h2><p>Az adatok a kiválasztott webhelyhez tartoznak.</p></div><button type="button" class="ui-pane-close" data-panel-close aria-label="Szerkesztőpanel bezárása">✕</button></div>
<form id="taxonomy-edit-form" method="post">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="action" value="<?=$selected?'update':'create'?>"><input type="hidden" name="term_id" value="<?=(int)($selected['id']??0)?>">
<div class="ui-pane-body">
<label class="ui-field">Név <input name="name" data-pane-first required minlength="2" maxlength="190" value="<?=h($selected['name']??'')?>" placeholder="Fogalom neve"></label>
<label class="ui-field">Keresőbarát név <input name="slug" maxlength="190" value="<?=h($selected['slug']??'')?>" placeholder="Automatikus"><small>Ha üresen hagyod, a névből készül.</small></label>
<?php if($type==='category'):?><label class="ui-field">Szülőkategória <select name="parent_id"><option value="0">Nincs szülőkategória</option><?php foreach($terms as $term):if((int)$term['id']===(int)($selected['id']??0))continue;?><option value="<?=(int)$term['id']?>" <?=((int)($selected['parent_id']??0)===(int)$term['id'])?'selected':''?>><?=h($term['name'])?></option><?php endforeach;?></select></label><?php endif;?>
<label class="ui-field">Leírás <textarea name="description" rows="4" maxlength="5000" placeholder="Rövid leírás…"><?=h($selected['description']??'')?></textarea></label>
</div>
<div class="ui-pane-footer"><button type="submit" class="ui-btn ui-btn-primary"><?=$selected?'Módosítások mentése':'Hozzáadás'?></button><?php if($selected):?><a class="ui-btn ui-btn-secondary" href="?type=<?=h($type)?>&amp;site_id=<?=$site?>">Új fogalom</a><?php endif;?></div>
</form>
<?php if($selected):?><section class="ui-danger-zone"><details><summary>Összevonás vagy törlés</summary>
<form method="post" onsubmit="return confirm('Átkerülnek a cikkkapcsolatok a célfogalomhoz. Folytatod?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="term_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="action" value="merge">
<label class="ui-field">Összevonás ide <select name="target_id" required><option value="">Válassz célfogalmat…</option><?php foreach($terms as $term):if((int)$term['id']===(int)$selected['id'])continue;?><option value="<?=(int)$term['id']?>"><?=h($term['name'])?></option><?php endforeach;?></select></label>
<button class="ui-btn ui-btn-secondary" type="submit">Összevonás</button></form>
<form method="post" onsubmit="return confirm('Biztosan törlöd ezt az elemet? A besorolások is törlődnek.')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="term_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="action" value="delete"><button class="ui-btn ui-btn-danger" type="submit">Elem törlése</button></form></details></section><?php endif;?>
</aside><?php endif;?>
</div><button class="ui-pane-dim" data-ui-pane-dim type="button" aria-label="Panel bezárása"></button>
</main>
<script>
(()=>{const form=document.getElementById('taxonomy-bulk');if(!form)return;
 const items=[...form.querySelectorAll('.taxonomy-select')],all=document.getElementById('taxonomy-select-all');
 const bar=document.getElementById('taxonomy-bulk-bar'),count=document.getElementById('taxonomy-selected-count'),bulk=document.getElementById('taxonomy-bulk-count'),button=document.getElementById('taxonomy-delete-selected');
 const visible=()=>items.filter(el=>!el.closest('.ui-taxonomy-row').hidden);
 const update=()=>{const selected=items.filter(el=>el.checked).length;const shown=visible();
  if(count)count.textContent=selected+' kijelölve';if(bulk)bulk.textContent=selected+' kijelölve';if(bar)bar.hidden=selected===0;if(button)button.disabled=selected===0;
  if(all){all.checked=shown.length>0&&shown.every(el=>el.checked);all.indeterminate=shown.some(el=>el.checked)&&!all.checked;}
 };
 all?.addEventListener('change',()=>{visible().forEach(el=>el.checked=all.checked);update();});
 form.addEventListener('change',update);document.addEventListener('comesy:list-filtered',e=>{if(e.detail.id==='taxonomy-list')update();});update();
})();
</script>
</body></html>