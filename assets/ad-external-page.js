/* First-party publisher orchestrator: inert until the site's CMP explicitly calls activate().
 * This adapter contains no consent policy and must NEVER infer consent from cookie presence,
 * page load or an untrusted query parameter.
 */
(()=>{
 'use strict';
 const settings=document.getElementById('cms-ad-external');
 if(!settings)return;
 let config;
 try{config=JSON.parse(settings.textContent);}catch(_){return;}
 if(!Number.isSafeInteger(config.site_id)||!Array.isArray(config.groups))return;
 let activated=false;
 async function activate(options={}){
  if(options.consentGranted!==true)return {status:'consent_required',count:0};
  if(activated)return {status:'already_activated',count:0};
  if(!window.comesyAdOcean||typeof window.comesyAdOcean.mount!=='function')
   throw new Error('The comesy AdOcean adapter has not loaded.');
  let count=0;
  const device=typeof window.matchMedia==='function'&&window.matchMedia('(max-width: 767px)').matches?'mobile':'desktop';
  // Only request the current device's LEAFs, not just hide the others
  // with CSS: that would still count ads and waste inventory requests.
  // One MASTER group is mounted at most once with its active LEAFs.
  for(const group of config.groups){
   if(!Number.isSafeInteger(group.master_node)||!Number.isSafeInteger(group.folder_node))continue;
   if(!Array.isArray(group.positions)||!group.positions.length)continue;
   const positions=group.positions.filter(position=>
    position.device===undefined||position.device==='all'||position.device===device);
   if(!positions.length)continue;
   const result=await window.comesyAdOcean.mount({
    apiBase:window.location.origin,
    siteId:config.site_id,
    masterNodeId:group.master_node,
    folderNodeId:group.folder_node,
    placements:positions,
    consentGranted:true
   });
   count+=result.count||0;
  }
  activated=true;
  return {status:'mounted',count,device};
 }
 window.comesyAdOceanPage=Object.freeze({activate});
})();
