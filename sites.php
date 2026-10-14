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
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Webhelyek | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{margin:0;font-family:system-ui}.wrap{max-width:960px;margin:auto;padding:20px}.panel{padding:20px;border:1px solid var(--line);border-radius:12px;background:var(--surface);margin:16px 0}.site-form{display:grid;gap:12px;max-width:600px}.site-form label{display:grid;gap:6px}.site-form input{width:100%;box-sizing:border-box;border:1px solid var(--line);background:var(--field);color:var(--ink);padding:11px;border-radius:8px;font-size:16px}.site-form button{background:var(--blue);color:white;border:0;border-radius:8px;padding:12px;cursor:pointer}.site-list{display:grid;gap:12px}.site-item{padding:14px;border:1px solid var(--line);border-radius:10px}.site-item p{color:var(--muted);overflow-wrap:anywhere}</style></head><body><?php require __DIR__.'/app/header.php';?><main class="wrap"><h1>Webhelyek</h1><p>Az itt felvett webhelyekhez külön cikkek, kategóriák és médiaelemek rendelhetők.</p><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><?php if($saved):?><p role="status">A webhely mentése sikeres.</p><?php endif;?><section class="panel"><h2><?=$selected?'Webhely szerkesztése':'Új webhely'?></h2><form method="post" class="site-form"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="id" value="<?=(int)($selected['id']??0)?>"><label>Név<input name="name" required maxlength="190" value="<?=h($selected['name']??'')?>"></label><label>Domain<input name="domain" required maxlength="190" placeholder="pelda.hu" value="<?=h($selected['domain']??'')?>"></label><label>Azonosító (slug)<input name="slug" required maxlength="100" placeholder="pelda" value="<?=h($selected['slug']??'')?>"></label><button type="submit">Mentés</button><?php if($selected):?><a href="/sites.php">Mégse</a><?php endif;?></form></section><section class="panel"><h2>Meglévő webhelyek</h2><div class="site-list"><?php foreach($sites as $s):?><article class="site-item"><strong><?=h($s['name'])?></strong><p><?=h($s['domain'])?> · <?=h($s['slug'])?> · <?=(int)$s['article_count']?> cikk</p><a href="/sites.php?edit=<?=(int)$s['id']?>">Szerkesztés</a></article><?php endforeach;?></div><?php if(!$sites):?><p>Még nincs webhely.</p><?php endif;?></section></main></body></html>