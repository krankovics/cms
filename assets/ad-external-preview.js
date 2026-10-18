/* Explicitly authorized real AdOcean preview inside an opaque-origin sandbox. */
(()=>{
 'use strict';
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
    started=true;
    button.disabled=true;
    button.textContent='AdOcean betöltve';
    status.textContent='Valódi AdOcean-kérés indítása…';
    const iframe=document.createElement('iframe');
    iframe.title='AdOcean hirdetés tesztelőnézete';
    iframe.setAttribute('sandbox','allow-scripts allow-popups');
    iframe.referrerPolicy='no-referrer';
    iframe.loading='eager';
    iframe.className='pub-adocean-test-frame';
    iframe.addEventListener('load',()=>{
     status.textContent='Az AdOcean tesztbetöltés megtörtént. Ha a keret üres, lehet, hogy nincs aktív kreatív ehhez a pozícióhoz, vagy a böngésző blokkolja.';
    },{once:true});
    iframe.addEventListener('error',()=>{
     status.textContent='A tesztelőnézet nem tölthető be. Ellenőrizd a böngésző konzolját és az AdOcean beállításait.';
    },{once:true});
    iframe.src=url.href;
    holder.replaceChildren(iframe);
   });
  });
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
 else init();
})();
