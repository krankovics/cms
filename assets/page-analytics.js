(()=>{
'use strict';
const config=document.getElementById('cms-page-analytics');
if(!config)return;
let data;try{data=JSON.parse(config.textContent);}catch(_){return;}
if(!Number.isInteger(data.site_id)||!data.site_id)return;
const viewport=()=>Math.max(0,window.innerWidth||0);
const device=viewport()<768?'mobile':viewport()<1100?'tablet':'desktop';
const pagePath='/public.php?site='+encodeURIComponent(data.slug)+'&'+data.kind+'='+encodeURIComponent(data.content||'home');
const start=Date.now();
let maxScroll=0,active=Date.now(),activeTime=0,sent=false;
const scroll=()=>{const doc=document.documentElement;const height=Math.max(doc.scrollHeight,document.body?.scrollHeight||0,1);const pct=Math.min(100,Math.round((window.scrollY+window.innerHeight)/height*100));maxScroll=Math.max(maxScroll,pct);};
function send(event,seconds=0){
 const payload=JSON.stringify({site_id:data.site_id,event,page_kind:data.kind,device,page_path:pagePath,seconds,scroll:maxScroll});
 try{
  if(navigator.sendBeacon&&navigator.sendBeacon('/page-analytics.php',new Blob([payload],{type:'text/plain'})))return;
  fetch('/page-analytics.php',{method:'POST',headers:{'Content-Type':'text/plain'},body:payload,keepalive:true,credentials:'omit'}).catch(()=>{});
 }catch(_){}
}
send('view');scroll();
window.addEventListener('scroll',scroll,{passive:true});
const stop=()=>{
 if(sent)return;
 sent=true;scroll();
 if(active!==null){activeTime+=Math.max(0,Date.now()-active);active=null;}
 if(activeTime>=10000||maxScroll>=50)send('engagement',Math.round(Math.min(1800000,activeTime)/1000));
};
document.addEventListener('visibilitychange',()=>{
 if(document.visibilityState==='hidden'){scroll();if(active!==null){activeTime+=Date.now()-active;active=null;}}
 else if(!sent)active=Date.now();
});
window.addEventListener('pagehide',stop,{once:true});
})();