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
 <div class="logo">comesy</div><div class="sub">Content. Media. System.</div>
 <nav class="nav" aria-label="Mobil navigáció">
  <a href="/">Áttekintés</a><a href="/articles.php">comesy Editor</a><a href="/editor.php">Új cikk</a><a href="/?view=sites">comesy Sites</a><a href="/?view=media">comesy Media</a><a href="/?view=ads">comesy Ads</a><a href="/?view=analytics">comesy Analytics</a><a href="/profile.php">Profilbeállítások</a>
 </nav>
</aside>
