<?php
require __DIR__.'/app/bootstrap.php';require __DIR__.'/app/site-access.php';require __DIR__.'/app/article-sites.php';$u=require_user();header('Content-Type: text/html; charset=utf-8');
$error='';$articles=[];$siteFilter=max(0,(int)($_GET['site_id']??0));$statusFilter=(string)($_GET['status']??'');$search=trim((string)($_GET['q']??''));$termId=max(0,(int)($_GET['term_id']??0));$sites=[];$total=0;$page=max(1,min(10000,(int)($_GET['page']??1)));$perPage=40;
try{
 $pdo=db();
 $allowed=allowed_primary_sites($pdo,$u);
 $sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $sites=array_values(array_filter($sites,fn($s)=>in_array((int)$s['id'],$allowed,true)));
 if($siteFilter&&!in_array($siteFilter,$allowed,true))throw new RuntimeException('Nincs jogosultság a webhelyhez.');
 if(!in_array($statusFilter,['','draft','review','scheduled','published','archived'],true))$statusFilter='';
 $allowedSql=implode(',',array_map('intval',$allowed?:[-1]));
 $mapped=article_sites_ready($pdo);
 $siteVisibility=$mapped?"(a.site_id IN ($allowedSql) OR EXISTS(SELECT 1 FROM article_sites asite WHERE asite.article_id=a.id AND asite.site_id IN ($allowedSql)))":"a.site_id IN ($allowedSql)";
 $where=[$siteVisibility];$params=[];
 if($u['role']==='author'){$where[]='a.author_id=?';$params[]=(int)$u['id'];}
 if($siteFilter){if($mapped){$where[]='(a.site_id=? OR EXISTS(SELECT 1 FROM article_sites asite WHERE asite.article_id=a.id AND asite.site_id=?))';$params[]=$siteFilter;$params[]=$siteFilter;}else{$where[]='a.site_id=?';$params[]=$siteFilter;}}
 if($statusFilter!==''){$where[]='a.status=?';$params[]=$statusFilter;}
 if($search!==''){$where[]='a.title LIKE ?';$params[]='%'.mb_substr($search,0,100).'%';}
 if($termId){$where[]='EXISTS (SELECT 1 FROM article_terms at WHERE at.article_id=a.id AND at.term_id=?)';$params[]=$termId;}
 $sql=' FROM articles a JOIN sites s ON s.id=a.site_id WHERE '.implode(' AND ',$where);
 $q=$pdo->prepare('SELECT COUNT(*)'.$sql);$q->execute($params);$total=(int)$q->fetchColumn();
 $lastPage=max(1,(int)ceil($total/$perPage));$page=min($page,$lastPage);$offset=($page-1)*$perPage;
 $q=$pdo->prepare('SELECT a.id,a.title,a.site_id,a.status,a.updated_at,s.name site,'.($mapped?'(SELECT COUNT(*) FROM article_sites ai WHERE ai.article_id=a.id) site_count':'1 site_count').$sql.' ORDER BY a.updated_at DESC,a.id DESC LIMIT '.$perPage.' OFFSET '.$offset);$q->execute($params);$articles=$q->fetchAll();
 $editableIds=array_map('intval',$allowed);
 foreach($articles as &$entry){
  $entry['can_edit']=in_array((int)$entry['site_id'],$editableIds,true);
  if($entry['can_edit']&&$mapped){
   $siteQuery=$pdo->prepare('SELECT site_id FROM article_sites WHERE article_id=?');$siteQuery->execute([(int)$entry['id']]);
   foreach($siteQuery->fetchAll(PDO::FETCH_COLUMN) as $sid){if(!in_array((int)$sid,$editableIds,true)){$entry['can_edit']=false;break;}}
  }
 }
 unset($entry);
}catch(Throwable $e){$error=$e instanceof PDOException?'Az adatbázis nem érhető el.':$e->getMessage();error_log('articles: '.$e->getMessage());}
$canWrite=in_array($u['role'],['platform_admin','site_admin','editor','author'],true);
$statusLabels=['draft'=>'Piszkozat','review'=>'Ellenőrzés','scheduled'=>'Időzített','published'=>'Publikált','archived'=>'Archivált'];
$pagerParams=['q'=>$search,'site_id'=>$siteFilter,'status'=>$statusFilter,'term_id'=>$termId];
function article_page_link(array $p,int $page):string{return '/articles.php?'.http_build_query(array_merge($p,['page'=>$page]));}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cikkek | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-page" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Tartalomkezelés</p><h1 class="ui-page-title">Cikkek</h1><p class="ui-page-description">Keresés, státuszok és többwebhelyes publikációk egy helyen.</p></div>
<?php if($canWrite):?><div class="ui-page-actions"><a class="ui-btn ui-btn-primary" href="/editor.php"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg>Új cikk</a></div><?php endif;?></header>
<?php if($error):?><p class="ui-notice error" role="alert"><?=h($error)?></p><?php endif;?>
<form method="get" class="ui-filterbar" aria-label="Cikkek szűrése">
<?php if($termId):?><input type="hidden" name="term_id" value="<?=(int)$termId?>"><?php endif;?>
<label class="ui-field ui-field-search">Keresés <input type="search" name="q" value="<?=h($search)?>" placeholder="Cikk címe…" autocomplete="off"></label>
<button class="ui-btn ui-btn-secondary ui-filter-toggle" type="button" aria-controls="article-filter-extras" aria-expanded="false" data-filter-toggle="article-filter-extras">Szűrők ⌄</button><div id="article-filter-extras" class="ui-filter-advanced"><label class="ui-field">Webhely <select name="site_id"><option value="0">Összes webhely</option><?php foreach($sites as $siteItem):?><option value="<?=(int)$siteItem['id']?>" <?=$siteFilter===(int)$siteItem['id']?'selected':''?>><?=h($siteItem['name'])?></option><?php endforeach;?></select></label>
<label class="ui-field">Státusz <select name="status"><option value="">Összes státusz</option><?php foreach($statusLabels as $key=>$label):?><option value="<?=h($key)?>" <?=$statusFilter===$key?'selected':''?>><?=h($label)?></option><?php endforeach;?></select></label>
<button class="ui-btn ui-btn-primary" type="submit">Szűrés</button>
<?php if($search!==''||$statusFilter!==''||$siteFilter||$termId):?><a class="ui-btn ui-btn-ghost" href="/articles.php">Alaphelyzet</a><?php endif;?>
</div></form>
<div class="ui-result-bar"><span><strong><?=$total?></strong> cikk<?= $termId?' · Címke/kategória #'.(int)$termId:''?></span><span><?=$total?($offset+1).'–'.min($total,$offset+$perPage).' / '.$total:'Nincs találat'?></span></div>
<section class="ui-panel ui-data-panel" aria-label="Cikklista">
<?php if($articles):?>
<div class="ui-table-wrap"><table class="ui-data-table"><thead><tr><th>Cikk címe</th><th>Webhely</th><th>Státusz</th><th>Utolsó módosítás</th><th>Műveletek</th></tr></thead><tbody id="article-list">
<?php foreach($articles as $a):?>
<tr><td class="ui-cell-title">
<?php if($canWrite&&$a['can_edit']):?><a class="ui-table-title" href="/editor.php?id=<?=(int)$a['id']?>"><?=h($a['title'])?></a><?php else:?><span class="ui-table-title"><?=h($a['title'])?></span><?php endif;?>
<?php if(!$a['can_edit']):?><p class="ui-table-meta">Csak olvasás</p><?php endif;?></td>
<td class="ui-cell-site"><span class="ui-cell-secondary"><?=h($a['site'])?><?php if((int)$a['site_count']>1):?> · +<?=((int)$a['site_count']-1)?> webhely<?php endif;?></span></td>
<td class="ui-cell-status"><span class="ui-status ui-status-<?=h($a['status'])?>"><?=h($statusLabels[$a['status']]??$a['status'])?></span></td>
<td class="ui-cell-date"><span class="ui-cell-secondary"><?=h($a['updated_at'])?></span></td><td class="ui-cell-actions"><?php if($canWrite&&$a['can_edit']):?><details class="ui-row-menu"><summary aria-label="Cikk műveletei" title="Műveletek">•••</summary><div class="ui-row-menu-items"><a href="/editor.php?id=<?=(int)$a['id']?>">Szerkesztés</a><a href="/editor.php?id=<?=(int)$a['id']?>#status">Státusz és publikálás</a></div></details><?php endif;?></td></tr>
<?php endforeach;?></tbody></table></div>
<?php else:?><div class="ui-empty-state"><strong>Nincs a feltételeknek megfelelő cikk.</strong><p>Próbálj más keresési kifejezést vagy szűrőt.</p><a class="ui-btn ui-btn-secondary" href="/articles.php">Szűrés törlése</a></div><?php endif;?>
<?php if($total>$perPage):?><nav class="ui-pagination" aria-label="Cikklista lapozása"><span><?=$page?> / <?=$lastPage?>. oldal</span><div class="ui-pagination-links"><?php if($page>1):?><a href="<?=h(article_page_link($pagerParams,$page-1))?>" rel="prev">← Előző</a><?php endif;?><?php if($page<$lastPage):?><a href="<?=h(article_page_link($pagerParams,$page+1))?>" rel="next">Következő →</a><?php endif;?></div></nav><?php endif;?>
</section></main></body></html>