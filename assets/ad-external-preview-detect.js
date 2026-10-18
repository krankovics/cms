/* Editor-only AdOcean creative visibility diagnostics.
 * Detect only meaningful, painted-sized content inside the selected LEAF.
 * Never count global tracking pixels or mere SDK/iframe network completion.
 */
(()=>{
 'use strict';
 function visible(el){
  if(!el.isConnected)return false;
  for(let p=el;p&&p.nodeType===1;p=p.parentElement){
   const st=getComputedStyle(p);
   if(st.display==='none'||st.visibility==='hidden'||st.visibility==='collapse'||Number(st.opacity)===0)return false;
  }
  return true;
 }
 function kind(el){
  if(!visible(el))return null;
  const rect=el.getBoundingClientRect();
  if(rect.width<100||rect.height<45)return null;
  const tag=el.tagName;
  if(tag==='IMG'){
   if(!el.complete||el.naturalWidth<100||el.naturalHeight<45)return null;
   return 'image';
  }
  if(tag==='VIDEO'){
   return el.readyState>=2&&el.videoWidth>=100&&el.videoHeight>=45?'video':null;
  }
  if(tag==='IFRAME'||tag==='OBJECT'||tag==='EMBED')return 'frame';
  if(tag==='CANVAS')return el.width>=100&&el.height>=45?'canvas':null;
  if(tag==='SVG')return 'svg';
  return null;
 }
 function findCreative(slot){
  if(!slot)return null;
  const candidates=slot.querySelectorAll('img,video,iframe,object,embed,canvas,svg');
  let framed=null;
  for(const element of candidates){
   const type=kind(element);
   if(!type)continue;
   if(type==='frame'){framed=framed||element;continue;}
   return {type,element,confirmed:true};
  }
  // Text/banner creatives can have backgrounds instead of images, but only
  // count a genuine content box, never an empty wrapper or a 1x1 tracker.
  const possible=slot.querySelectorAll('a,article,div');
  for(const element of possible){
   if(!visible(element))continue;
   const box=element.getBoundingClientRect(),st=getComputedStyle(element);
   if(box.width<120||box.height<45)continue;
   if(st.backgroundImage!=='none'&&st.backgroundImage.includes('url('))
    return {type:'background',element,confirmed:true};
   if(element.textContent.trim().length>=20&&element.children.length===0)
    return {type:'text',element,confirmed:true};
  }
  return framed?{type:'frame',element:framed,confirmed:false}:null;
 }
 function start({slotId,report,timeoutMs=12000}){
  const slot=document.getElementById(slotId);
  const stage=document.getElementById('preview-stage');
  const container=document.getElementById('preview-ad');
  if(!slot||!stage||!container)return;
  let phase='',timer,observer,scale=1;
  function resize(target){
   if(!target)return;
   // Content is inserted asynchronously. Preserve its native desktop width
   // but scale it down to fit the narrow editorial preview viewport.
   const base=target.element;
   const rect=base.getBoundingClientRect();
   const stageRect=stage.getBoundingClientRect();
   const naturalWidth=Math.max(0,stage.scrollWidth,slot.scrollWidth,
    base.offsetWidth,rect.width/scale,(rect.right-stageRect.left)/scale);
   const naturalHeight=Math.max(90,stage.scrollHeight,slot.scrollHeight,
    base.offsetHeight,rect.height/scale,(rect.bottom-stageRect.top)/scale);
   const available=Math.max(1,container.clientWidth);
   scale=Math.min(1,available/Math.max(available,naturalWidth));
   stage.style.width=Math.ceil(naturalWidth)+'px';
   stage.style.transform='scale('+scale+')';
   const height=Math.max(90,Math.min(1000,Math.ceil(naturalHeight*scale)));
   container.style.height=height+'px';
   const status=document.getElementById('preview-status');
   const total=Math.ceil(height+(status?.offsetHeight||40)+10);
   report('layout',{height:Math.max(160,Math.min(1100,total)),
    original_width:Math.round(naturalWidth),scale:Math.round(scale*100)});
  }
  function check(){
   const candidate=findCreative(slot);
   if(!candidate)return;
   resize(candidate);
   if(candidate.confirmed&&phase!=='creative_visible'){
    phase='creative_visible';
    report('creative_visible',{type:candidate.type});
   }else if(!candidate.confirmed&&phase===''){
    phase='creative_container';
    report('creative_container',{type:'iframe'});
   }
  }
  observer=new MutationObserver(check);
  observer.observe(slot,{childList:true,subtree:true,characterData:true,attributes:true,attributeFilter:['src','style','class','width','height']});
  slot.addEventListener('load',check,true);
  check();
  timer=setTimeout(()=>{
   observer.disconnect();
   slot.removeEventListener('load',check,true);
   if(phase!=='creative_visible'){
    report(phase==='creative_container'?'creative_unverified':'no_creative');
   }
  },timeoutMs);
  return ()=>{clearTimeout(timer);observer.disconnect();slot.removeEventListener('load',check,true);};
 }
 window.comesyAdOceanPreviewDetect=Object.freeze({start,findCreative});
})();
