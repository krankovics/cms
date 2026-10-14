<?php
if(!isset($u))$u=require_user();
?>
<header class="app-header comesy-global-header">
 <button type="button" class="menu-toggle" data-menu-toggle aria-expanded="false" aria-label="Menü megnyitása">☰</button>
 <a href="/" class="brand">comesy</a>
 <div class="app-header-right">
  <button type="button" class="theme-toggle" data-theme-toggle aria-label="Sötét mód váltása">◐</button>
  <details class="profile-menu"><summary class="profile-trigger" style="list-style:none;cursor:pointer"><img class="avatar" src="/avatar.php" alt=""><span class="profile-name"><?=h($u['name'])?></span> ▾</summary>
   <div class="profile-dropdown"><a href="/profile.php">Profilbeállítások</a><form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button type="submit">Kijelentkezés</button></form></div>
  </details>
 </div>
</header>
<div class="nav-overlay" data-nav-overlay></div>
<aside class="mobile-nav sidebar">
 <div class="mobile-nav-head"><strong>comesy</strong><button type="button" class="mobile-nav-close" data-menu-close aria-label="Menü bezárása">×</button></div><p class="mobile-nav-sub">Content. Media. System.</p>
 <nav class="nav" aria-label="Mobil navigáció">
  <details class="nav-group" <?=in_array(basename((string)($_SERVER['SCRIPT_NAME']??'')),['articles.php','editor.php','taxonomy.php','media.php'],true)?'open':''?>>
   <summary class="nav-group-toggle"><span>Editor</span><span class="nav-group-chevron" aria-hidden="true">⌄</span></summary>
   <div class="nav-submenu">
    <a href="/articles.php">Cikkek</a>
    <a href="/editor.php">Új cikk</a>
    <a href="/taxonomy.php">Kategóriák és címkék</a>
    <a href="/media.php">Médiatár</a>
   </div>
  </details>
  <?php if($u['role']==='platform_admin'):?><a href="/sites.php">Webhelyek</a><?php endif;?>
  <a href="/settings.php">Beállítások</a>
 </nav>
</aside>
