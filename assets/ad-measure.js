(()=>{
'use strict';
const script=document.currentScript||document.querySelector('script[data-token]');
const token=script?.dataset?.token||'';
if(!/^[a-f0-9]{32}$/.test(token))return;
const report=event=>{
 const data=JSON.stringify({token,event});
 try{
  if(navigator.sendBeacon&&navigator.sendBeacon('/ad-event.php',new Blob([data],{type:'text/plain'})))return;
  fetch('/ad-event.php',{method:'POST',body:data,headers:{'Content-Type':'text/plain'},keepalive:true,credentials:'omit'}).catch(()=>{});
 }catch(_){}
};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>report('render'),{once:true});
else report('render');
// v1 viewability approximation: at least 50% of the iframe's area in the
// visual viewport for >=1s. This is NOT independent audited measurement.
let timer=null,reported=false;
if('IntersectionObserver' in window){
 const observer=new IntersectionObserver(entries=>{
  const visible=entries.some(e=>e.isIntersecting&&e.intersectionRatio>=.5);
  if(visible&&!reported&&!timer)timer=setTimeout(()=>{reported=true;report('viewable');observer.disconnect();},1000);
  if(!visible&&timer){clearTimeout(timer);timer=null;}
 },{threshold:[0,.5,1]});
 observer.observe(document.body);
}
})();