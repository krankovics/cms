/* comesy theme, navigation drawer and account menu */
(()=>{
 const root=document.documentElement;
 let theme='light';
 try{theme=localStorage.getItem('comesy-theme')||'light';}catch(e){}
 root.dataset.theme=theme==='dark'?'dark':'light';
 const ready=()=>{
  const body=document.body,drawer=document.getElementById('comesy-sidebar');
  const toggle=document.querySelector('[data-menu-toggle]'),close=document.querySelector('[data-menu-close]');
  const themeButtons=[...document.querySelectorAll('[data-theme-toggle]')];
  const mobile=window.matchMedia('(max-width:1023px)');
  let lastFocus=null;
  const isOpen=()=>body.classList.contains('nav-open');
  const focusable=()=>[...drawer.querySelectorAll('a[href],button:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>el.getClientRects().length);
  function setDrawer(open,restore=true){
   if(!drawer||!toggle)return;
   const shouldOpen=!!(open&&mobile.matches);
   if(shouldOpen&&!isOpen())lastFocus=document.activeElement;
   body.classList.toggle('nav-open',shouldOpen);
   toggle.setAttribute('aria-expanded',String(shouldOpen));
   toggle.setAttribute('aria-label',shouldOpen?'Menü bezárása':'Menü megnyitása');
   if(mobile.matches)drawer.inert=!shouldOpen;else drawer.inert=false;
   if(shouldOpen)(close||focusable()[0])?.focus();
   else if(restore&&lastFocus&&mobile.matches&&lastFocus.isConnected){lastFocus.focus();lastFocus=null;}
  }
  function onBreakpoint(){setDrawer(false,false);}
  if(drawer&&toggle){
   mobile.addEventListener('change',onBreakpoint);
   onBreakpoint();
   toggle.addEventListener('click',()=>setDrawer(!isOpen()));
   document.querySelectorAll('[data-menu-close],[data-nav-overlay]').forEach(el=>el.addEventListener('click',()=>setDrawer(false)));
   drawer.querySelectorAll('a[href]').forEach(link=>link.addEventListener('click',()=>setDrawer(false,false)));
  }
  document.addEventListener('keydown',e=>{
   if(e.key==='Escape'){
    if(isOpen()){e.preventDefault();setDrawer(false);return;}
    const profile=document.querySelector('.profile-menu[open]');if(profile){profile.open=false;profile.querySelector('summary')?.focus();}
   }
   if(e.key!=='Tab'||!isOpen()||!drawer)return;
   const items=focusable();if(!items.length)return;
   const first=items[0],last=items[items.length-1];
   if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
   else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
  });
  themeButtons.forEach(button=>button.addEventListener('click',()=>{
   const next=root.dataset.theme==='dark'?'light':'dark';
   root.dataset.theme=next;
   try{localStorage.setItem('comesy-theme',next);}catch(e){}
  }));
  document.addEventListener('click',e=>{
   const profile=document.querySelector('.profile-menu[open]');
   if(profile&&!profile.contains(e.target))profile.open=false;
  });
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
})();

/* Scroll newly displayed server and client notices into view. */
(()=>{
 const selector='.success,.error,[role="alert"],[data-notice]';
 const visible=el=>!!(el.offsetWidth||el.offsetHeight||el.getClientRects().length);
 let last=null;
 const show=el=>{if(!el||!visible(el)||el===last)return;last=el;requestAnimationFrame(()=>el.scrollIntoView({behavior:'smooth',block:'start'}));};
 const check=()=>{const messages=[...document.querySelectorAll(selector)].filter(el=>visible(el)&&el.textContent.trim());if(messages.length)show(messages[0]);};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',check,{once:true});else check();
 const observer=new MutationObserver(records=>{for(const r of records){if(r.type==='attributes'&&r.target.matches?.(selector)){show(r.target);return}if(r.type==='characterData'||r.type==='childList'){const target=r.target.nodeType===1?r.target:r.target.parentElement;const notice=target?.closest?.(selector)||target?.querySelector?.(selector);if(notice&&notice.textContent.trim()){show(notice);return}}}});
 const start=()=>observer.observe(document.body,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['hidden','class','style']});
 if(document.body)start();else document.addEventListener('DOMContentLoaded',start,{once:true});
})();

/* Shared list search and filter for article, media and taxonomy pages. */
(()=>{
 const norm=s=>(s||'').toLocaleLowerCase('hu').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
 function apply(id){
  const list=document.getElementById(id);if(!list)return;
  const search=document.querySelector('[data-list-search="'+id+'"]');
  const filter=document.querySelector('[data-list-filter="'+id+'"]');
  const query=norm(search?.value.trim());
  const field=filter?.dataset.filterField||'status';
  const selected=filter?.value||'';
  const rows=[...list.children].filter(el=>el.matches('tr,.asset,.term'));
  let visible=0;
  for(const row of rows){
   const matches=(!query||norm(row.dataset.search||row.textContent).includes(query))&&(!selected||row.dataset[field]===selected);
   row.hidden=!matches;if(matches)visible++;
  }
  const empty=document.querySelector('[data-empty-for="'+id+'"]');if(empty)empty.hidden=visible!==0||rows.length===0;
 }
 const init=()=>document.querySelectorAll('[data-list-search],[data-list-filter]').forEach(el=>{
  const id=el.dataset.listSearch||el.dataset.listFilter;
  el.addEventListener('input',()=>apply(id));el.addEventListener('change',()=>apply(id));apply(id);
 });
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
