/* comesy AdOcean adapter: explicit, opt-in, on-page MASTER/SLAVE rendering.
 * No network request occurs before consentGranted === true.
 * Usage: window.comesyAdOcean.mount({siteId,masterNodeId,placements:[{nodeId,element}],consentGranted:true})
 */
(()=>{
 'use strict';
 const sdkByServer=new Map(),rendered=new Set(),masters=new Set();
 let initialized=false;
 const safeId=id=>Number.isSafeInteger(Number(id))&&Number(id)>0;
 const baseDefault=window.location.origin;
 function loadSdk(server){
  if(sdkByServer.has(server))return sdkByServer.get(server);
  if(!/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.adocean\.pl$/.test(server))return Promise.reject(new Error('Unknown AdOcean server'));
  const promise=new Promise((resolve,reject)=>{
   if(window.ado&&typeof window.ado.master==='function'){resolve();return;}
   const script=document.createElement('script');
   script.src='https://'+server+'/files/js/ado.js';
   script.async=true;script.onload=resolve;script.onerror=()=>reject(new Error('AdOcean SDK loading failed'));
   document.head.append(script);
  });
  sdkByServer.set(server,promise);
  return promise;
 }
 function targetElement(value){
  if(value instanceof Element)return value;
  if(typeof value!=='string'||value.length>250)return null;
  try{return document.querySelector(value);}catch(_){return null;}
 }
 async function mount(options){
  if(!options||options.consentGranted!==true)return {status:'consent_required',count:0};
  const siteId=Number(options.siteId),masterId=Number(options.masterNodeId||0),folderId=Number(options.folderNodeId||0);
  const candidate=Array.isArray(options.placements)?options.placements:[];
  if(!safeId(siteId)||candidate.length>50||candidate.length===0||masterId<0||folderId<0)
   throw new Error('Invalid AdOcean mount parameters');
  const visible=candidate.map(p=>({nodeId:Number(p.nodeId),target:targetElement(p.element)}))
    .filter(p=>safeId(p.nodeId)&&p.target);
  if(!visible.length)return {status:'no_placements',count:0};
  const ids=[...new Set(visible.map(p=>p.nodeId))];
  const url=new URL('/ad-external-feed.php',options.apiBase||baseDefault);
  url.searchParams.set('site_id',String(siteId));
  url.searchParams.set('master_node',String(masterId));
  if(folderId)url.searchParams.set('folder_node',String(folderId));
  url.searchParams.set('nodes',ids.join(','));
  const response=await fetch(url.toString(),{credentials:'omit',mode:'cors',cache:'no-store'});
  if(!response.ok)throw new Error('AdOcean inventory denied ('+response.status+')');
  const data=await response.json();
  if(data.provider!=='adocean'||!Array.isArray(data.positions))throw new Error('Invalid inventory response');
  await loadSdk(data.server);
  if(!window.ado||typeof window.ado.config!=='function')throw new Error('AdOcean API is unavailable');
  if(!initialized){
   window.ado.config({mode:'new',xml:false,consent:true,characterEncoding:true});
   if(typeof window.ado.preview==='function')window.ado.preview({enabled:false});
   initialized=true;
  }
  // All placeholders must exist before the MASTER call and subsequent SLAVE scripts.
  const pending=[];
  for(const position of data.positions){
   const p=visible.find(p=>p.nodeId===position.node_id);
   if(!p||rendered.has(position.code_id))continue;
   if(!/^[A-Za-z0-9_.-]{1,190}$/.test(position.code_id))continue;
   if(document.getElementById(position.code_id))continue;
   if(!((position.kind==='slave'&&data.master)||position.kind==='placement'))continue;
   const element=document.createElement('div');
   element.id=position.code_id;
   p.target.appendChild(element);
   pending.push({position,element});
  }
  try{
   if(data.master&&!masters.has(data.master)){
    const slaveIds=pending.filter(({position})=>position.kind==='slave').map(({position})=>position.code_id);
    window.ado.master({id:data.master,server:data.server,slaveIds});
    masters.add(data.master);
   }
   for(const {position} of pending){
    if(position.kind==='slave')window.ado.slave(position.code_id,{myMaster:data.master});
    else window.ado.placement({id:position.code_id,server:data.server});
    rendered.add(position.code_id);
   }
  }catch(error){
   for(const {position,element} of pending)if(!rendered.has(position.code_id))element.remove();
   throw error;
  }
  return {status:'mounted',count:pending.length};
 }
 window.comesyAdOcean=Object.freeze({mount});
})();
