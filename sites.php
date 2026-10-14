<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$u=require_user();
if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$error='';$saved=isset($_GET['saved']);$sites=[];
try{
 $pdo=db();
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  $id=max(0,(int)($_POST['id']??0));
  $name=trim((string)($_POST['name']??''));
  $domain=mb_strtolower(trim((string)($_POST['domain']??'')));
  $slug=mb_strtolower(trim((string)($_POST['slug']??'')));
  if(mb_strlen($name)<2||mb_strlen($name)>190||!preg_match('/^(?=.{3,190}$)[a-z0-9-]+(?:\\.[a-z0-9-]+)+$/D',$domain)||!preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/D',$slug))throw new RuntimeException('Érvénytelen név, domain vagy azonosító.');
  if($id){
   $q=$pdo->prepare('UPDATE sites SET name=?,domain=?,slug=? WHERE id=?');
   $q->execute([$name,$domain,$slug,$id]);
   if(!$q->rowCount()){ $check=$pdo->prepare('SELECT id FROM sites WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('A webhely nem található.');}
  }else{
   $q=$pdo->prepare('INSERT INTO sites(name,domain,slug) VALUES(?,?,?)');$q->execute([$name,$domain,$slug]);
  }
  header('Location: /sites.php?saved=1');exit;
 }
 $sites=$pdo->query('SELECT s.id,s.name,s.domain,s.slug,(SELECT COUNT(*) FROM articles a WHERE a.site_id=s.id) article_count FROM sites s ORDER BY s.name')->fetchAll();
}catch(Throwable $e){error_log('sites: '.$e->getMessage());$error=$e instanceof PDOException?'Az adatbázis-művelet sikertelen. A domain és az azonosító egyedi kell legyen.':$e->getMessage();}
$edit=max(0,(int)($_GET['edit']??0));$selected=null;foreach($sites as $s)if((int)$s['id']===$edit)$selected=$s;
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhelyek | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><script src="/assets/admin-pages-v2.js" defer></script></head><body><?php require __DIR__.'/app/header.php';?>
<main class="ui-admin-section" id="main-content">
<header class="ui-page-header"><div class="ui-page-heading"><p class="ui-eyebrow">Adminisztráció</p><h1 class="ui-page-title">Webhelyek</h1><p class="ui-page-description">A comesy felületen kezelt kiadói webhelyek és tartalmaik.</p></div>
<div class="ui-page-actions"><?php if($selected):?><a class="ui-btn ui-btn-primary" href="/sites.php?new=1">Új webhely</a><?php else:?><button type="button" class="ui-btn ui-btn-primary ui-compact-action" data-panel-open="site-editor" aria-controls="site-editor" aria-expanded="false">+ Új webhely</button><?php endif;?></div></header>
<?php if($error):?><p class="error" role="alert"><?=h($error)?></p><?php endif;?>
<?php if($saved):?><p class="success" role="status">A webhely adatait mentettük.</p><?php endif;?>
<div class="ui-workbench">
<section class="ui-admin-panel ui-workbench-list"><div class="ui-panel-header"><h2>Meglévő webhelyek</h2><span class="ui-text-muted"><?=count($sites)?> webhely</span></div>
<?php if($sites):?><div class="ui-admin-list"><?php foreach($sites as $s):?><article class="ui-admin-row"><div class="ui-admin-row-main"><strong><?=h($s['name'])?></strong><small><?=h($s['domain'])?> · <?=h($s['slug'])?> · <?=(int)$s['article_count']?> cikk</small></div><a href="/sites.php?edit=<?=(int)$s['id']?>">Szerkesztés</a></article><?php endforeach;?></div>
<?php else:?><div class="ui-empty-state"><strong>Még nincs webhely.</strong><p>Az új webhelyet a jobb oldali űrlapon veheted fel.</p></div><?php endif;?></section>
<aside class="ui-admin-panel ui-workbench-pane" id="site-editor" data-ui-pane data-panel-default-open="<?=$selected||isset($_GET['new'])?'1':'0'?>" aria-labelledby="site-form-title"><button type="button" class="ui-pane-close" data-panel-close aria-label="Panel bezárása">✕</button><h2 id="site-form-title"><?=$selected?'Webhely szerkesztése':'Új webhely'?></h2><form method="post" class="ui-admin-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=(int)($selected['id']??0)?>">
<label class="ui-field">Név <input name="name" data-pane-first required minlength="2" maxlength="190" value="<?=h($selected['name']??'')?>" placeholder="Kiadói webhely"></label>
<label class="ui-field">Domain <input name="domain" required maxlength="190" placeholder="pelda.hu" value="<?=h($selected['domain']??'')?>"></label>
<label class="ui-field">Azonosító (slug) <input name="slug" required maxlength="100" placeholder="pelda" value="<?=h($selected['slug']??'')?>"></label>
<div class="ui-admin-actions"><button class="ui-btn ui-btn-primary" type="submit">Mentés</button><?php if($selected):?><a class="ui-btn ui-btn-secondary" href="/sites.php">Mégse</a><?php endif;?></div></form>
<p>Az egyes webhelyek szerkesztési jogosultságait a <a href="/site-permissions.php">Hozzáféréseknél</a> tudod beállítani.</p>
</aside></div><button class="ui-pane-dim" data-ui-pane-dim type="button" aria-label="Panel bezárása"></button></main></body></html>