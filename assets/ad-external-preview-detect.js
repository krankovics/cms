/* AdOcean editor preview diagnostics.
 * The SDK rewrites LEAF #id as #id1 and often nests #ao-id. Look only inside
 * that selected placement, never count unrelated tracking pixels.
 */
(()=>{
 'use strict';
 const owned=(slotId)=>{
  const original=document.getElementById(slotId);
  const renamed=document.getElementById(slotId+'1');
  const emitted=document.getElementById('ao-'+slotId);
  return renamed||original||emitted||null;
 };
 function painted(el){
  if(!el?.isConnected)return false;
  for(let p=el;p&&p.nodeType===1;p=p.parentElement){
   const css=getComputedStyle(p);
   if(css.display==='none'||css.visibility==='hidden'||css.visibility==='collapse'||Number(css.opacity)===0)return false;
  }
  return true;
 }
 function findCreative(slot){
  if(!slot)return null;
  let unverified=null;
  for(const el of slot.querySelectorAll('img,video,iframe,object,embed,canvas,svg')){
   if(!painted(el))continue;
   const box=el.getBoundingClientRect();
   if(box.width<100||box.height<45)continue;
   const tag=el.tagName;
   if(tag==='IMG'){
    if(el.complete&&el.naturalWidth>=100&&el.naturalHeight>=45)
     return {type:'image',element:el,confirmed:true};
    continue;
   }
   if(tag==='VIDEO'){
    if(el.readyState>=2&&el.videoWidth>=100&&el.videoHeight>=45)
     return {type:'video',element:el,confirmed:true};
    continue;
   }
   if(tag==='CANVAS'&&(el.width<100||el.height<45))continue;
   if(tag==='IFRAME'||tag==='OBJECT'||tag==='EMBED'){
    unverified=unverified||{type:'frame',element:el,confirmed:false};
    continue;
   }
   return {type:tag.toLowerCase(),element:el,confirmed:true};
  }
  for(const el of slot.querySelectorAll('a,article,div')){
   if(!painted(el))continue;
   const box=el.getBoundingClientRect();
   if(box.width<120||box.height<45)continue;
   const css=getComputedStyle(el);
   if(css.backgroundImage!=='none'&&css.backgroundImage.includes('url('))
    return {type:'background',element:el,confirmed:true};
   if(el.children.length===0&&el.textContent.trim().length>=20)
    return {type:'text',element:el,confirmed:true};
  }
  return unverified;
 }
 function start({slotId,report,timeoutMs=12000}){
  const container=document.getElementById('preview-ad');
  const stage=document.getElementById('preview-stage');
  const status=document.getElementById('preview-status');
  if(!container||!stage){report('detector_error');return;}
  let finished=false,phase='',heightSeen=0,widthSeen=0;
  const resize=(candidate)=>{
   if(!candidate)return;
   const el=candidate.element;
   const box=el.getBoundingClientRect();
   const naturalWidth=Math.max(0,stage.scrollWidth,el.offsetWidth,box.width);
   const naturalHeight=Math.max(90,stage.scrollHeight,el.offsetHeight,box.height);
   const available=Math.max(1,container.clientWidth);
   const scale=Math.min(1,available/Math.max(available,naturalWidth));
   if(naturalWidth===widthSeen&&Math.round(naturalHeight*scale)===heightSeen)return;
   widthSeen=naturalWidth;heightSeen=Math.round(naturalHeight*scale);
   stage.style.width=Math.ceil(naturalWidth)+'px';
   stage.style.transformOrigin='top left';
   stage.style.transform='scale('+scale+')';
   container.style.height=Math.max(90,Math.min(1000,heightSeen))+'px';
   report('layout',{height:Math.max(160,Math.min(1100,heightSeen+(status?.offsetHeight||40)+10)),
    original_width:Math.round(naturalWidth),scale:Math.round(scale*100)});
  };
  const check=()=>{
   if(finished)return;
   const slot=owned(slotId);
   if(!slot)return;
   const candidate=findCreative(slot);
   if(!candidate)return;
   resize(candidate);
   if(candidate.confirmed&&phase!=='creative_visible'){
    phase='creative_visible';
    report('creative_visible',{type:candidate.type});
   }else if(!candidate.confirmed&&!phase){
    phase='creative_container';
    report('creative_container',{type:candidate.type});
   }
  };
  // AdOcean rewrites the original LEAF element, and nested ad providers can
  // update their size without changing attributes. Both paths are observed.
  const observer=new MutationObserver(check);
  observer.observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('load',check,true);
  const poll=setInterval(check,500);
  check();
  const timer=setTimeout(()=>{
   finished=true;observer.disconnect();clearInterval(poll);
   document.removeEventListener('load',check,true);
   if(phase==='creative_visible')return;
   if(phase==='creative_container'){report('creative_unverified');return;}
   const slot=owned(slotId);
   const thirdParty=!!slot?.querySelector('.aoadsense,ins.adsbygoogle');
   if(thirdParty||window.comesyPreviewDelivery==='selected')
    report('provider_selected_no_visible',{thirdParty});
   else if(window.comesyPreviewDelivery==='empty')report('provider_empty');
   else report('no_creative');
  },timeoutMs);
  return ()=>{finished=true;clearTimeout(timer);clearInterval(poll);observer.disconnect();document.removeEventListener('load',check,true);};
 }
 window.comesyAdOceanPreviewDetect=Object.freeze({start,findCreative});
})();
