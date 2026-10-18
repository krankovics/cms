/* Consent-gated AdOcean preview with an opaque-origin sandbox and typed status messages. */
(()=>{
 'use strict';
 const states={
  sdk_ready:'Az AdOcean JavaScript-könyvtár betöltődött.',
  master_ready:'Az AdOcean MASTER inicializálása sikerült.',
  request_started:'Az AdOcean hirdetéskérése elindult. Kreatívra várunk…',
  creative_detected:'Az AdOcean kreatívot helyezett el a tesztkeretben.',
  no_creative:'Az AdOcean kérés elindult, de nem jelent meg kreatív. Lehetséges ok: kampánycélzás, AdOcean-beállítás vagy böngészőblokkolás.',
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
    let phase='';
    const handler=event=>{
     if(event.source!==iframe.contentWindow||event.origin!=='null')return;
     const message=event.data;
     if(!message||message.type!=='comesy-adocean-preview'||message.channel!==channel)return;
     if(!Object.prototype.hasOwnProperty.call(states,message.phase))return;
     phase=message.phase;
     status.textContent=states[phase];
     if(phase==='creative_detected')button.textContent='AdOcean kreatív észlelve';
     if(phase.endsWith('_error')||phase==='no_creative')button.textContent='AdOcean teszt befejezve';
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
    },16000);
   });
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
