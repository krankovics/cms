/* Consent-gated AdOcean preview with an opaque-origin sandbox and typed status messages. */
(()=>{
 'use strict';
 const states={
  sdk_ready:'Az AdOcean JavaScript-könyvtár betöltődött.',
  master_ready:'Az AdOcean MASTER inicializálása sikerült.',
  request_started:'Az AdOcean hirdetéskérése elindult. Kreatívra várunk…',
  creative_visible:'Látható AdOcean-kreatív jelent meg a hirdetési pozícióban.',
  creative_container:'Hirdetési iframe érkezett. A kreatív megjelenése még nem igazolt.',
  creative_unverified:'Hirdetési iframe érkezett, de nem tudjuk igazolni, hogy látható kreatív van benne.',
  detector_error:'A kreatív láthatóságának ellenőrzése nem futott le.',
  no_creative:'A LEAF pozícióban nem jelent meg látható kreatív. Lehetséges ok: mobilos kampánycélzás, AdOcean-beállítás vagy böngészőblokkolás.',
  sdk_error:'Nem sikerült betölteni az AdOcean JavaScript-könyvtárát.',
  master_error:'Az AdOcean MASTER indítása sikertelen. Ellenőrizd a böngésző konzolját.',
  render_error:'A LEAF kreatív indítása hibára futott. Ellenőrizd a böngésző konzolját.'
 };
 function init(){
  document.querySelectorAll('[data-ao-preview-control]').forEach(control=>{
   const consent=control.querySelector('[data-ao-preview-consent]');
   const button=control.querySelector('[data-ao-preview-load]');
   const status=control.querySelector('[data-ao-preview-status]');
   const holder=control.querySelector('[data-ao-preview-frame]');
   if(!consent||!button||!status||!holder)return;
   let started=false;
   consent.addEventListener('change',()=>{if(!started)button.disabled=!consent.checked;});
   button.addEventListener('click',()=>{
    if(started||!consent.checked)return;
    const url=new URL(button.dataset.previewUrl||'',location.href);
    if(url.origin!==location.origin||url.pathname!=='/ad-external-preview.php'){
     status.textContent='Érvénytelen tesztelőnézeti URL.';return;
    }
    // Distinguish the isolated iframe from arbitrary third-party frame messages.
    const random=new Uint8Array(16);
    crypto.getRandomValues(random);
    const channel=Array.from(random,b=>b.toString(16).padStart(2,'0')).join('');
    url.searchParams.set('channel',channel);
    started=true;
    button.disabled=true;
    button.textContent='AdOcean teszt folyamatban';
    status.textContent='Védett AdOcean-tesztkeret megnyitása…';
    const iframe=document.createElement('iframe');
    iframe.title='AdOcean hirdetés tesztelőnézete';
    iframe.setAttribute('sandbox','allow-scripts allow-popups');
    iframe.referrerPolicy='no-referrer';
    iframe.loading='eager';
    iframe.className='pub-adocean-test-frame';
    // The adserver chooses campaigns from the iframe's actual viewport,
    // not the size of a banner scaled later with CSS. On a phone a desktop
    // MASTER must therefore run in a desktop-width browsing context.
    let outsideScale=1;
    const desktopMaster=control.dataset.aoPreviewDevice==='desktop';
    const availableWidth=Math.max(1,holder.getBoundingClientRect().width);
    if(desktopMaster&&availableWidth<970){
     outsideScale=availableWidth/970;
     iframe.style.width='970px';
     iframe.style.maxWidth='none';
     iframe.style.height='600px';
     iframe.style.transformOrigin='top left';
     iframe.style.transform='scale('+outsideScale+')';
     holder.style.position='relative';
     holder.style.overflow='hidden';
     holder.style.height=Math.ceil(600*outsideScale)+'px';
     control.dataset.previewDesktop='1';
     control.dataset.adScale=String(Math.round(outsideScale*100));
     status.textContent='Desktop AdOcean-zóna tesztelése 970 pixeles ablakban, mobilképernyőre kicsinyítve…';
    }
    let phase='';
    const handler=event=>{
     if(event.source!==iframe.contentWindow||event.origin!=='null')return;
     const message=event.data;
     if(!message||message.type!=='comesy-adocean-preview'||message.channel!==channel)return;
     if(message.phase!=='layout'&&!Object.prototype.hasOwnProperty.call(states,message.phase))return;
     if(message.phase==='layout'){
      const h=Number(message.details?.height);
      if(Number.isFinite(h)){
       const frameHeight=Math.max(180,Math.min(850,h));
       iframe.style.height=frameHeight+'px';
       if(outsideScale<1)holder.style.height=Math.max(80,Math.ceil(frameHeight*outsideScale))+'px';
      }
      const width=Number(message.details?.original_width),scale=Number(message.details?.scale);
      if(Number.isFinite(width)&&width>0&&Number.isFinite(scale)){
       const effective=Math.min(100,Math.round(scale*outsideScale));
       if(effective<100){
        control.dataset.adWidth=String(Math.round(width));
        control.dataset.adScale=String(effective);
       }
      }
      return;
     }
     if(phase==='creative_visible'&&message.phase!=='creative_visible')return;
     phase=message.phase;
     const isScaled=control.dataset.adScale&&Number(control.dataset.adScale)<100;
     status.textContent=states[phase]+(phase==='creative_visible'&&isScaled?' (Mobilnézethez '+control.dataset.adScale+'%-ra kicsinyítve.)':'');
     if(phase==='creative_visible')button.textContent='AdOcean kreatív látható';
     if(phase==='no_creative'&&desktopMaster)status.textContent+=' A desktop előnézeti méret használatával sem érkezett kampány.';
     if(phase.endsWith('_error')||phase==='no_creative'||phase==='creative_unverified')button.textContent='AdOcean teszt befejezve';
    };
    window.addEventListener('message',handler);
    iframe.addEventListener('load',()=>{
     if(!phase)status.textContent='A tesztkeret megnyílt; még nem érkezett visszajelzés az AdOcean könyvtárától.';
    },{once:true});
    iframe.addEventListener('error',()=>{
     status.textContent='Nem sikerült betölteni a tesztelőnézetet.';
    },{once:true});
    iframe.src=url.href;
    holder.replaceChildren(iframe);
    setTimeout(()=>{
     if(!phase){
      status.textContent='A tesztkeret nem jelzett vissza. Lehetséges, hogy a munkamenet lejárt, vagy a böngésző blokkolta a betöltést.';
      button.textContent='AdOcean teszt nem indult';
     }
     window.removeEventListener('message',handler);
    },25000);
   });
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
