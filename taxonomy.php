<?php
require __DIR__.'/app/bootstrap.php';
$u=require_user();$error='';$message='';
try{
 $pdo=db();$sites=$pdo->query('SELECT id,name FROM sites ORDER BY name')->fetchAll();
 $site=(int)($_REQUEST['site_id']??($sites[0]['id']??0));$type=($_REQUEST['type']??'category')==='tag'?'tag':'category';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
  if(!in_array($u['role'],['platform_admin','site_admin','editor'],true))throw new RuntimeException('Nincs jogosultság.');
  $name=trim((string)($_POST['name']??''));
  if(mb_strlen($name)<2||mb_strlen($name)>190)throw new RuntimeException('A név 2–190 karakter lehet.');
  $slug=mb_strtolower($name);$slug=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$slug)?:$slug;$slug=trim(preg_replace('/[^a-z0-9]+/','-',$slug),'-');
  if(!$slug)throw new RuntimeException('Érvénytelen név.');
  $q=$pdo->prepare('INSERT INTO taxonomy_terms(site_id,type,name,slug) VALUES(0,?,?,?)');$q->execute([$type,$name,$slug]);
  header('Location: /taxonomy.php?site_id='.$site.'&type='.$type.'&saved=1');exit;
 }
 $q=$pdo->prepare('SELECT id,name,slug FROM taxonomy_terms WHERE site_id=0 AND type=? ORDER BY name');$q->execute([$type]);$terms=$q->fetchAll();
}catch(Throwable $e){error_log('taxonomy: '.$e->getMessage());$error=$e instanceof PDOException?'Adatbázis-migráció szükséges vagy már létezik ez a név.':$e->getMessage();$sites=$sites??[];$terms=[];}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kategóriák és címkék | comesy</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{margin:0;font-family:system-ui}.wrap{max-width:860px;margin:auto;padding:20px}.panel{padding:20px;margin:16px 0;background:var(--surface);border:1px solid var(--line);border-radius:12px}input,select,button{font-size:16px;padding:10px;max-width:100%}.terms{display:grid;gap:8px}.term{padding:10px;border-bottom:1px solid var(--line)}.tabs{display:flex;gap:18px}</style></head><body><?php require __DIR__.'/app/header.php';?><main class="wrap"><h1>Kategóriák és címkék</h1><nav class="tabs"><a href="?type=category&site_id=<?=$site?>">Kategóriák</a><a href="?type=tag&site_id=<?=$site?>">Címkék</a></nav><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif;?><form method="get" class="panel"><input type="hidden" name="type" value="<?=h($type)?>"><label>Webhely <select name="site_id" onchange="this.form.submit()"><?php foreach($sites as $s):?><option value="<?=(int)$s['id']?>" <?=$site===(int)$s['id']?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></label></form><?php if(in_array($u['role'],['platform_admin','site_admin','editor'],true)):?><form method="post" class="panel"><h2>Új <?=$type==='tag'?'címke':'kategória'?></h2><input type="hidden" name="csrf" value="<?=h(csrf())?>"><input type="hidden" name="site_id" value="<?=$site?>"><input type="hidden" name="type" value="<?=h($type)?>"><label>Név <input name="name" required maxlength="190"></label><button type="submit">Hozzáadás</button></form><?php endif;?><section class="panel"><h2>Meglévő elemek</h2><div class="terms"><?php foreach($terms as $t):?><div class="term"><?=h($t['name'])?> <small>(<?=h($t['slug'])?>)</small></div><?php endforeach;?></div><?php if(!$terms):?><p>Még nincs elem.</p><?php endif;?></section></main></body></html>