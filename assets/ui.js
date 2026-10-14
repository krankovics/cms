(()=>{const root=document.documentElement;const get=()=>{try{return localStorage.getItem('comesy-theme')||'light'}catch(e){return 'light'}};const set=t=>{root.dataset.theme=t;try{localStorage.setItem('comesy-theme',t)}catch(e){}};set(get());document.addEventListener('click',e=>{const theme=e.target.closest('[data-theme-toggle]');if(theme){set(root.dataset.theme==='dark'?'light':'dark')}const menu=e.target.closest('[data-menu-toggle]');if(menu){const open=document.body.classList.toggle('nav-open');menu.setAttribute('aria-expanded',String(open))}if(e.target.closest('[data-nav-overlay],[data-menu-close]'))document.body.classList.remove('nav-open')});document.addEventListener('keydown',e=>{if(e.key==='Escape')document.body.classList.remove('nav-open')})})();

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
