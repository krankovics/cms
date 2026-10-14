<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/site-access.php';
require __DIR__.'/app/taxonomy-actions.php';
$u=require_user();$error='';$message='';
try{
 $pdo=db();$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $allowed=allowed_resource_sites($pdo,$u,'tags');
 $site=(int)($_REQUEST['site_id']??($allowed[0]??0));$type=($_REQUEST['type']??'category')==='tag'?'tag':'category';
 if(!in_array($site,$allowed,true))$site=$allowed[0]??0;
 if($_SERVER['REQUEST_METHOD']==='POST'){
  tx_action($pdo,$u,$allowed,$site,$type);
  header('Location: /taxonomy.php?site_id='.$site.'&type='.$type.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT t.id,t.name,t.slug,t.site_id,m.description,m.parent_id,(SELECT COUNT(*) FROM article_terms a WHERE a.term_id=t.id) usage_count FROM taxonomy_terms t LEFT JOIN taxonomy_term_meta m ON m.term_id=t.id WHERE t.site_id IN ('.implode(',',array_map('intval',$allowed?:[-1])).') AND t.type=? ORDER BY t.name');
 $q->execute([$type]);$terms=$q->fetchAll();
 $editId=(int)($_GET['edit']??0);$selected=null;
 foreach($terms as $term)if((int)$term['id']===$editId)$selected=$term;
}catch(Throwable $e){error_log('taxonomy: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázis-migráció szükséges vagy már létezik ez a név.':$e->getMessage();$sites=$sites??[];$terms=[];}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kategóriák és címkék | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{margin:0;font-family:system-ui}.wrap{max-width:860px;margin:auto;padding:20px}.panel{padding:20px;margin:16px 0;background:var(--surface);border:1px solid var(--line);border-radius:12px}input,select,button{font-size:16px;padding:10px;max-width:100%}.terms{display:grid;gap:8px}.term{padding:10px;border-bottom:1px solid var(--line)}.tabs{display:flex;gap:18px}</style></head><body><?php require __DIR__.'/app/header.php';?><main class="wrap"><h1>Kategóriák és címkék</h1><nav class="tabs comesy-tabs"><a class="<?=$type==='category'?'is-active':''?>" href="?type=category&site_id=<?=$site?>">Kategóriák</a><a class="<?=$type==='tag'?'is-active':''?>" href="?type=tag&site_id=<?=$site?>">Címkék</a></nav><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?><form method="get" class="panel"><input type="hidden" name="type" value="<?=h($type)?>"><label>Webhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):if(!in_array((int)$s['id'],$allowed,true))continue;?><option value="<?=(int)$s['id']?>" <?=((int)$s['id']===$site)?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label></form><form method="post" class="panel taxonomy-edit" id="term-editor"><h2><?=$selected?'Fogalom szerkesztése':'Új '.($type==='tag'?'címke':'kategória')?></h2>
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="action" value="<?=$selected?'update':'create'?>"><input type="hidden" name="term_id" value="<?=(int)($selected['id']??0)?>">
<label>Név <input name="name" required minlength="2" maxlength="190" value="<?=h($selected['name']??'')?>"></label>
<label>Keresőbarát név <input name="slug" maxlength="190" value="<?=h($selected['slug']??'')?>" placeholder="Automatikus"></label>
<?php if($type==='category'):?><label>Szülőkategória <select name="parent_id"><option value="0">Nincs szülő</option><?php foreach($terms as $t):if((int)$t['id']===(int)($selected['id']??0))continue;?><option value="<?=(int)$t['id']?>" <?=((int)($selected['parent_id']??0)===(int)$t['id'])?'selected':''?>><?=h($t['name'])?></option><?php endforeach;?></select></label><?php endif;?>
<label>Leírás <textarea name="description" rows="3" maxlength="5000"><?=h($selected['description']??'')?></textarea></label>
<button type="submit"><?=$selected?'Módosítások mentése':'Hozzáadás'?></button><?php if($selected):?><a href="?type=<?=h($type)?>&amp;site_id=<?=$site?>">Mégse</a><?php endif;?></form>
<?php if($selected):?><div class="panel taxonomy-advanced"><h2>Összevonás és törlés</h2>
<form method="post" onsubmit="return confirm('A cikkek besorolása átkerül a célfogalomhoz. Folytatod?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="term_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="action" value="merge"><label>Célfogalom <select name="target_id" required><option value="">Válassz…</option><?php foreach($terms as $t):if((int)$t['id']===(int)$selected['id'])continue;?><option value="<?=(int)$t['id']?>"><?=h($t['name'])?></option><?php endforeach;?></select></label><button type="submit">Összevonás</button></form>
<form method="post" onsubmit="return confirm('Biztosan törlöd a fogalmat?')"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="term_id" value="<?=(int)$selected['id']?>"><input type="hidden" name="action" value="delete"><button type="submit">Törlés</button></form></div><?php endif;?><?php endif;?><section class="panel"><div class="comesy-section-head"><h2>Meglévő elemek</h2><label class="comesy-search"><span>Keresés</span><input type="search" data-list-search="taxonomy-list" placeholder="Név vagy slug keresése…"></label></div><form method="post" id="taxonomy-bulk" onsubmit="return confirm('Biztosan törlöd a kijelölt kategóriákat/címkéket? A cikkekről is lekerülnek.')">
<input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><input type="hidden" name="action" value="bulk_delete">
<div class="taxonomy-bulk-toolbar"><label><input type="checkbox" id="taxonomy-select-all"> Összes látható kijelölése</label><span id="taxonomy-selected-count">0 kijelölve</span><button type="submit" id="taxonomy-delete-selected" disabled>Kijelöltek törlése</button></div>
<div class="terms" id="taxonomy-list"><?php foreach($terms as $t):?><details class="term taxonomy-row" data-search="<?=h($t['name'].' '.$t['slug'])?>"><summary><input type="checkbox" class="taxonomy-select" name="term_ids[]" value="<?=(int)$t['id']?>" aria-label="<?=h($t['name'])?> kijelölése" onclick="event.stopPropagation()"><strong><?=h($t['name'])?></strong><span class="taxonomy-count"><?=(int)$t['usage_count']?> cikk</span></summary><p class="taxonomy-slug"><?=h($t['slug'])?></p><?php if($t['description']):?><p><?=h($t['description'])?></p><?php endif;?><a href="?type=<?=h($type)?>&amp;site_id=<?=$site?>&amp;edit=<?=(int)$t['id']?>#term-editor">Szerkesztés</a> · <a href="/articles.php?term_id=<?=(int)$t['id']?>">Kapcsolódó cikkek</a></details><?php endforeach;?></div></form><p class="comesy-empty-filter" data-empty-for="taxonomy-list" hidden>Nincs a keresésnek megfelelő elem.</p><?php if(!$terms):?><p>Még nincs elem.</p><?php endif;?></section></main><script>
(function(){
 const root=document.getElementById('taxonomy-bulk'),all=document.getElementById('taxonomy-select-all'),count=document.getElementById('taxonomy-selected-count'),button=document.getElementById('taxonomy-delete-selected');
 if(!root||!all)return;
 const items=()=>Array.from(root.querySelectorAll('.taxonomy-select'));
 const visible=()=>items().filter(x=>x.closest('.taxonomy-row').getClientRects().length>0);
 function update(){const selected=items().filter(x=>x.checked).length;count.textContent=selected+' kijelölve';button.disabled=!selected;const shown=visible();all.checked=shown.length>0&&shown.every(x=>x.checked);all.indeterminate=shown.some(x=>x.checked)&&!all.checked;}
 all.addEventListener('change',()=>{visible().forEach(x=>x.checked=all.checked);update();});
 root.addEventListener('change',e=>{if(e.target.matches('.taxonomy-select'))update();});
 document.querySelectorAll('[data-list-search="taxonomy-list"]').forEach(x=>x.addEventListener('input',()=>requestAnimationFrame(update)));
 update();
})();
</script></body></html>