(()=>{
'use strict';
const dataNode=document.getElementById('cms-ad-placements');
if(!dataNode)return;
let config;try{config=JSON.parse(dataNode.textContent);}catch(_){return;}
const device=window.innerWidth<768?'mobile':window.innerWidth<1100?'tablet':'desktop';
const allowed=new Set(['header','footer','sidebar','paragraph','css','sticky']);
const header=document.querySelector('.pub-header');
const footer=document.querySelector('.pub-footer');
const main=document.querySelector('.pub-main');
if(!main||!Array.isArray(config.positions))return;
for(const zone of config.positions){
 if(!zone||!allowed.has(zone.position)||zone.device!=='all'&&zone.device!==device)continue;
 if(!/^[a-z0-9_-]{1,100}$/.test(zone.zone))continue;
 const wrapper=document.createElement('div');wrapper.className='pub-auto-ad pub-ad-'+zone.position;
 wrapper.dataset.adZone=zone.zone;
 const mark=document.createElement('small');mark.textContent='Hirdetés';mark.className='pub-ad-disclosure';
 const frame=document.createElement('iframe');frame.title='Hirdetés: '+zone.zone;
 frame.setAttribute('loading','lazy');frame.setAttribute('referrerpolicy','no-referrer');
 frame.style.width='100%';frame.style.height=Math.min(1080,Math.max(1,Number(zone.height)||250))+'px';
 const params=new URLSearchParams({site_id:String(config.site_id),zone:zone.zone});
 if(config.section)params.set('section',config.section);
 frame.src='/ad-slot.php?'+params.toString();
 wrapper.append(mark,frame);
 let target=null;
 switch(zone.position){
  case 'header':
   if(header)header.insertAdjacentElement('afterend',wrapper);
   break;
  case 'footer':
   if(footer)footer.insertAdjacentElement('beforebegin',wrapper);
   break;
  case 'sidebar':
   target=main.querySelector('.pub-sidebar,.cms-source-sidebar');
   if(!target){target=document.createElement('aside');target.className='pub-native-ads-sidebar';main.append(target);}
   target.append(wrapper);
   break;
  case 'paragraph':{
   const paragraphs=main.querySelectorAll('.pub-article p,.pub-rich-content p');
   const p=paragraphs[Math.max(0,Number(zone.value)-1)];
   if(p)p.insertAdjacentElement('afterend',wrapper);
   break;
  }
  case 'css':
   try{target=document.querySelector(String(zone.selector||''));if(target)target.insertAdjacentElement('afterend',wrapper);}catch(_){}
   break;
  case 'sticky':
   wrapper.classList.add('pub-sticky-ad');
   wrapper.insertAdjacentHTML('beforeend','<button type="button" class="pub-sticky-close" aria-label="Hirdetés bezárása">×</button>');
   wrapper.querySelector('button').addEventListener('click',()=>wrapper.remove());
   document.body.append(wrapper);
   break;
 }
}
})();