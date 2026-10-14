/* Shared list-page UI: accessible editing drawers, filtering and copy actions. */
(()=>{
 const ready=()=>{
  const narrow=window.matchMedia('(max-width:1199px)');
  let active=null,returnFocus=null;
  const panels=[...document.querySelectorAll('[data-ui-pane]')];
  const paneById=id=>panels.find(p=>p.id===id);
  const backdrop=document.querySelector('[data-ui-pane-dim]');
  function focusables(panel){return [...panel.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])')].filter(el=>el.getClientRects().length&&el.getAttribute('type')!=='hidden');}
  function setPane(panel,open){
   if(!panel)return;
   const isMobile=narrow.matches;
   if(open&&!isMobile){panel.scrollIntoView({block:'nearest'});const target=panel.querySelector('[data-pane-first]');target?.focus();return;}
   if(open&&active&&active!==panel)setPane(active,false);
   if(open){returnFocus=document.activeElement;active=panel;}
   panel.classList.toggle('is-open',open);
   panel.setAttribute('aria-hidden',String(!open&&isMobile));
   if(backdrop)backdrop.classList.toggle('is-open',open);
   document.querySelectorAll('[data-panel-open="'+panel.id+'"]').forEach(el=>el.setAttribute('aria-expanded',String(open)));
   if(open){document.body.classList.add('ui-pane-open');focusables(panel)[0]?.focus();}
   else{document.body.classList.remove('ui-pane-open');active=null;if(returnFocus?.isConnected)returnFocus.focus();returnFocus=null;}
  }
  panels.forEach(panel=>{
   panel.setAttribute('aria-hidden',String(narrow.matches));
   if(narrow.matches)panel.inert=true;
  });
  function toggleInert(){panels.forEach(panel=>{panel.inert=narrow.matches&&!panel.classList.contains('is-open');panel.setAttribute('aria-hidden',String(narrow.matches&&!panel.classList.contains('is-open')));});}
  // Inert is removed before focus moves into the active panel.
  document.querySelectorAll('[data-panel-open]').forEach(button=>button.addEventListener('click',e=>{
   const panel=paneById(button.dataset.panelOpen);if(!panel)return;
   e.preventDefault();panel.inert=false;setPane(panel,true);
  }));
  document.querySelectorAll('[data-panel-close]').forEach(button=>button.addEventListener('click',()=>{
   const pane=button.closest('[data-ui-pane]');setPane(pane,false);toggleInert();
  }));
  backdrop?.addEventListener('click',()=>{if(active){setPane(active,false);toggleInert();}});
  document.addEventListener('keydown',e=>{
   if(!active)return;
   if(e.key==='Escape'){e.preventDefault();setPane(active,false);toggleInert();return;}
   if(e.key!=='Tab')return;
   const nodes=focusables(active);if(!nodes.length)return;
   if(e.shiftKey&&document.activeElement===nodes[0]){e.preventDefault();nodes[nodes.length-1].focus();}
   else if(!e.shiftKey&&document.activeElement===nodes[nodes.length-1]){e.preventDefault();nodes[0].focus();}
  });
  narrow.addEventListener('change',()=>{
   if(active)setPane(active,false);
   toggleInert();
  });
  toggleInert();
  panels.forEach(panel=>{if(panel.dataset.panelDefaultOpen==='1'&&narrow.matches){panel.inert=false;setPane(panel,true);}});

  const normalize=s=>(s||'').toLocaleLowerCase('hu').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const accordions=[...document.querySelectorAll('[data-responsive-accordion]')];
  if(accordions.length){
   const small=window.matchMedia('(max-width:767px)');
   const sync=()=>accordions.forEach((panel,i)=>{panel.open=!small.matches||i===0;});
   sync();small.addEventListener('change',sync);
  }
  document.querySelectorAll('[data-filter-toggle]').forEach(button=>{
   const panel=document.getElementById(button.dataset.filterToggle);if(!panel)return;
   const set=open=>{panel.classList.toggle('is-open',open);button.setAttribute('aria-expanded',String(open));};
   set(!!panel.querySelector('select option:checked:not([value=""]):not([value="0"])'));
   button.addEventListener('click',()=>set(!panel.classList.contains('is-open')));
  });
  document.querySelectorAll('[data-ui-search]').forEach(input=>{
   const list=document.getElementById(input.dataset.uiSearch);
   if(!list)return;
   const rows=()=>[...list.querySelectorAll('[data-search]')].filter(el=>el.closest('#'+list.id)===list);
   const count=document.querySelector('[data-ui-visible-for="'+list.id+'"]');
   const empty=document.querySelector('[data-ui-empty-for="'+list.id+'"]');
   const update=()=>{
    const query=normalize(input.value.trim());
    let shown=0;
    rows().forEach(row=>{const match=!query||normalize(row.dataset.search||row.textContent).includes(query);row.hidden=!match;if(match)shown++;});
    if(count)count.textContent=shown.toLocaleString('hu-HU');
    if(empty)empty.hidden=shown>0;
    document.dispatchEvent(new CustomEvent('comesy:list-filtered',{detail:{id:list.id}}));
   };
   input.addEventListener('input',update);update();
  });
  document.querySelectorAll('[data-copy-url]').forEach(button=>button.addEventListener('click',async()=>{
   const text=new URL(button.dataset.copyUrl,location.origin).href;
   try{await navigator.clipboard.writeText(text);button.textContent='Másolva';}
   catch{window.prompt('Kép URL-je:',text);}
  }));
  document.querySelectorAll('[data-ui-dialog-open]').forEach(button=>button.addEventListener('click',()=>{
   const dialog=document.getElementById(button.dataset.uiDialogOpen);
   if(dialog&&typeof dialog.showModal==='function')dialog.showModal();
  }));
  document.querySelectorAll('[data-ui-dialog-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
  document.querySelectorAll('dialog.ui-upload-dialog').forEach(dialog=>dialog.addEventListener('click',e=>{
   if(e.target===dialog)dialog.close();
  }));
  document.querySelectorAll('[data-ui-file-preview]').forEach(input=>input.addEventListener('change',()=>{
   const label=document.querySelector('[data-ui-file-name-for="'+input.id+'"]');
   if(label)label.textContent=input.files?.[0]?.name||'Nincs kiválasztott fájl';
  }));
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
})();
