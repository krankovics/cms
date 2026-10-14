<?php
if(!isset($u))$u=require_user();
$currentPage=basename((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??''));
if($currentPage==='')$currentPage='index.php';
$isPlatformAdmin=$u['role']==='platform_admin';
$canWrite=in_array($u['role'],['platform_admin','site_admin','editor','author'],true);
$navGroups=[
 ['title'=>'Munkaterület','links'=>[
  ['href'=>'/','page'=>'index.php','text'=>'Áttekintés','icon'=>'home'],
  ...($canWrite?[['href'=>'/articles.php','page'=>'articles.php','text'=>'Cikkek','icon'=>'file'],['href'=>'/editor.php','page'=>'editor.php','text'=>'Új cikk','icon'=>'plus'],['href'=>'/taxonomy.php','page'=>'taxonomy.php','text'=>'Kategóriák és címkék','icon'=>'tag'],['href'=>'/media.php','page'=>'media.php','text'=>'Médiatár','icon'=>'image']]:[])
 ]],
 ...($isPlatformAdmin?[['title'=>'Adminisztráció','links'=>[
  ['href'=>'/sites.php','page'=>'sites.php','text'=>'Webhelyek','icon'=>'globe'],
  ['href'=>'/users.php','page'=>'users.php','text'=>'Felhasználók','icon'=>'users'],
  ['href'=>'/site-permissions.php','page'=>'site-permissions.php','text'=>'Hozzáférések','icon'=>'shield']
 ]]]:[]),
 ['title'=>'Fiók','links'=>[
  ['href'=>'/settings.php','page'=>'settings.php','text'=>'Beállítások','icon'=>'settings'],
  ['href'=>'/profile.php','page'=>'profile.php','text'=>'Saját profil','icon'=>'user']
 ]]
];
$currentTitle='Áttekintés';
foreach($navGroups as $group)foreach($group['links'] as $item)if($currentPage===$item['page'])$currentTitle=$item['text'];
if($currentPage==='migrate.php')$currentTitle='Adatbázis-migráció';
?>
<script>document.body.classList.add('comesy-admin');</script>
<link rel="stylesheet" href="/assets/admin-v2.css">
<?php if(in_array($currentPage,['articles.php','taxonomy.php','media.php','users.php','sites.php'],true)):?><link rel="stylesheet" href="/assets/admin-pages-v2.css"><?php endif;?>
<?php if(in_array($currentPage,['editor.php','sites.php','site-permissions.php','profile.php','settings.php','migrate.php'],true)):?><link rel="stylesheet" href="/assets/admin-secondary-v2.css"><?php endif;?>
<svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="ui-icon-sprite" width="0" height="0" style="position:absolute;overflow:hidden">
 <symbol id="cs-home" viewBox="0 0 24 24"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></symbol>
 <symbol id="cs-file" viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H5V3zM14 3v5h5M8 13h8M8 17h6"/></symbol>
 <symbol id="cs-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
 <symbol id="cs-tag" viewBox="0 0 24 24"><path d="M12 3H4a1 1 0 0 0-1 1v8l9 9 9-9z"/><circle cx="7.5" cy="7.5" r="1.2"/></symbol>
 <symbol id="cs-image" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9" r="1.5"/><path d="m4 18 5-5 4 4 3-3 4 4"/></symbol>
 <symbol id="cs-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c-5 5-5 13 0 18M12 3c5 5 5 13 0 18"/></symbol>
 <symbol id="cs-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2zM17 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 5v1"/></symbol>
 <symbol id="cs-shield" viewBox="0 0 24 24"><path d="m12 2 9 4v6c0 5-4 8-9 10-5-2-9-5-9-10V6zM9 12l2 2 4-4"/></symbol>
 <symbol id="cs-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M10 2h4l.6 3 2 1 2.7-1.5 2 3.5-2.3 2v4l2.3 2-2 3.5-2.7-1.5-2 1L14 22h-4l-.6-3-2-1-2.7 1.5-2-3.5L5 14v-4L2.7 8l2-3.5L7.4 6l2-1z"/></symbol>
 <symbol id="cs-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2z"/></symbol>
 <symbol id="cs-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
 <symbol id="cs-close" viewBox="0 0 24 24"><path d="M5 5l14 14M19 5 5 19"/></symbol>
 <symbol id="cs-moon" viewBox="0 0 24 24"><path d="M20 15A9 9 0 0 1 9 4a9 9 0 1 0 11 11z"/></symbol>
 <symbol id="cs-chevron" viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></symbol>
 <symbol id="cs-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
</svg>
<aside class="mobile-nav sidebar comesy-sidebar" id="comesy-sidebar" aria-label="Fő navigáció">
 <div class="ui-sidebar-heading">
  <a class="ui-sidebar-brand" href="/" aria-label="comesy kezdőlap"><span class="ui-brand-mark">c<span>•</span></span><span>comesy<span class="ui-brand-dot">.</span></span></a>
  <button type="button" class="ui-sidebar-collapse" data-sidebar-collapse aria-label="Oldalsáv összecsukása" aria-pressed="false" title="Oldalsáv összecsukása"><svg class="ui-icon" aria-hidden="true"><use href="#cs-chevron"></use></svg></button>
  <button type="button" class="mobile-nav-close ui-sidebar-close" data-menu-close aria-label="Menü bezárása"><svg class="ui-icon"><use href="#cs-close"></use></svg></button>
 </div>
 <p class="ui-sidebar-tagline">Content. Media. System.</p>
 <nav class="nav ui-sidebar-nav" aria-label="Adminisztrációs menü">
  <?php foreach($navGroups as $group):?>
  <div class="ui-nav-section">
   <p class="ui-nav-section-title"><?=h($group['title'])?></p>
   <?php foreach($group['links'] as $item): $active=$currentPage===$item['page'];?>
   <a class="ui-nav-link <?=$active?'is-active':''?>" href="<?=h($item['href'])?>" title="<?=h($item['text'])?>" <?=$active?'aria-current="page"':''?>>
    <svg class="ui-icon" aria-hidden="true"><use href="#cs-<?=h($item['icon'])?>"></use></svg>
    <span class="ui-nav-label"><?=h($item['text'])?></span>
   </a>
   <?php endforeach;?>
  </div>
  <?php endforeach;?>
 </nav>
 <div class="ui-sidebar-footer"><span class="ui-sidebar-status"></span><span>comesy workspace</span><small>v2</small></div>
</aside>
<button type="button" class="nav-overlay comesy-nav-overlay" data-nav-overlay aria-label="Menü bezárása"></button>
<header class="app-header comesy-global-header">
 <div class="ui-header-start">
  <button type="button" class="menu-toggle ui-icon-button" data-menu-toggle aria-controls="comesy-sidebar" aria-expanded="false" aria-label="Menü megnyitása"><svg class="ui-icon"><use href="#cs-menu"></use></svg></button>
  <a href="/" class="brand ui-mobile-brand">comesy<span class="ui-brand-dot">.</span></a>
  <div class="ui-header-location"><span class="ui-header-parent">Munkaterület</span><span class="ui-header-separator">/</span><strong><?=h($currentTitle)?></strong></div>
 </div>
 <div class="app-header-right ui-header-actions">
  <?php if($canWrite):?><a class="ui-header-create" href="/editor.php"><svg class="ui-icon" aria-hidden="true"><use href="#cs-plus"></use></svg><span>Új cikk</span></a><?php endif;?>
  <button type="button" class="theme-toggle ui-icon-button" data-theme-toggle aria-label="Világos vagy sötét mód váltása" title="Téma váltása"><svg class="ui-icon" aria-hidden="true"><use href="#cs-moon"></use></svg></button>
  <details class="profile-menu"><summary class="profile-trigger ui-profile-trigger"><img class="avatar" src="/avatar.php" alt=""><span class="profile-name"><?=h($u['name'])?></span><svg class="ui-icon ui-profile-chevron" aria-hidden="true"><use href="#cs-chevron"></use></svg></summary>
   <div class="profile-dropdown"><a href="/profile.php">Profilbeállítások</a><form method="post" action="/logout.php"><input type="hidden" name="csrf" value="<?=h(csrf())?>"><button type="submit">Kijelentkezés</button></form></div>
  </details>
 </div>
</header>
