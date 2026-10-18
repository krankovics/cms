/* Webhelyek admin: form state and accessible feedback for saved/invalid settings. */
(()=>{
 'use strict';
 const start=()=>{
  const form=document.querySelector('[data-site-edit-form]');
  const status=document.getElementById('site-save-feedback');
  const button=form?.querySelector('[data-site-save-button]');
  if(!form||!status||!button)return;
  const show=(message,tone)=>{
   status.hidden=false;
   status.textContent=message;
   status.classList.remove('success','error','ui-site-save-pending');
   if(tone)status.classList.add(tone);
   status.setAttribute('role',tone==='error'?'alert':'status');
  };
  let submitting=false;
  const changed=()=>{
   if(submitting)return;
   show('Nem mentett módosítások. A mentéshez kattints a Mentés gombra.','');
  };
  form.addEventListener('input',changed);
  form.addEventListener('change',changed);
  // Browser constraint validation prevents "submit"; keep the cause visible.
  form.addEventListener('invalid',()=>{
   if(submitting)return;
   show('A mentés nem indult el. Ellenőrizd a kötelező vagy hibás mezőket.','error');
  },true);
  form.addEventListener('submit',()=>{
   if(submitting)return;
   submitting=true;
   show('Mentés folyamatban…','ui-site-save-pending');
   button.textContent='Mentés…';
   button.disabled=true;
   button.setAttribute('aria-busy','true');
  });
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
 else start();
})();
