<?php
require __DIR__.'/app/bootstrap.php';
$u=require_user();if($u['role']!=='platform_admin'){http_response_code(403);exit('Nincs jogosultság.');}
$message='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!valid_csrf())$error='Érvénytelen munkamenet.';
 else{
  try{
   $pdo=db();
   $sql=file_get_contents(__DIR__.'/database/editor.sql')."\n".file_get_contents(__DIR__.'/database/content.sql')."\n".file_get_contents(__DIR__.'/database/site-permissions.sql');
   foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)$pdo->exec($statement);
   // Keep existing shared taxonomy entries unchanged. Consolidation is a separate, reviewed operation.
   $message='A comesy Editor adatbázis-migrációja sikeresen lefutott.';
  }catch(Throwable $e){error_log('comesy migration: '.$e->getMessage());$error='A migráció sikertelen. Ellenőrizd az adatbázis jogosultságait.';}
 }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>comesy Editor telepítés</title><link rel="stylesheet" href="/assets/ui.css"><script src="/assets/ui.js" defer></script><style>body{font-family:system-ui}.box{max-width:620px;margin:10vh auto;padding:30px;background:var(--surface);border:1px solid var(--line);border-radius:14px}button{background:#315fd6;color:white;padding:13px 18px;border:0;border-radius:8px}</style></head><body><?php require __DIR__.'/app/header.php'; ?><main class="box"><a href="/">← comesy admin</a><h1>comesy Editor adatbázis</h1><p>A hiányzó adatbázistáblákat hozza létre, a meglévő cikkeket és címkéket nem módosítja. Kizárólag szuperadmin számára elérhető.</p><?php if($message):?><p class="success"><?=h($message)?></p><?php endif;?><?php if($error):?><p class="error"><?=h($error)?></p><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button>Adatbázis-migráció futtatása</button></form><p><a href="/articles.php">Tovább a cikkekhez →</a></p></main></body></html>