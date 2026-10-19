import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';
const script=readFileSync(new URL('../assets/ad-external-page.js',import.meta.url),'utf8');
const groups=[
 {folder_node:10,master_node:11,positions:[{nodeId:12,element:'#one'},{nodeId:13,element:'#two'}]},
 {folder_node:10,master_node:0,positions:[{nodeId:14,element:'#three'}]}
];
const calls=[];
const window={
 location:{origin:'https://cms.8media.hu'},
 comesyAdOcean:{mount:async opts=>{calls.push(opts);return {status:'mounted',count:opts.placements.length};}}
};
const document={getElementById(id){return id==='cms-ad-external'?{
 textContent:JSON.stringify({site_id:1,groups})
}:null;}};
runInNewContext(script,{window,document,console});
assert.equal(calls.length,0,'No provider calls at page load.');
assert.ok(window.comesyAdOceanPage,'Publisher integration should register an explicit API.');
const denied=await window.comesyAdOceanPage.activate();
assert.equal(denied.status,'consent_required');
assert.equal(calls.length,0,'No provider calls before explicit consent.');
const activated=await window.comesyAdOceanPage.activate({consentGranted:true});
assert.equal(activated.status,'mounted');
assert.equal(activated.count,3);
assert.equal(calls.length,2,'Each MASTER context should mount only once.');
assert.equal(calls[0].masterNodeId,11);
assert.equal(calls[0].placements.length,2);
assert.equal(calls[1].masterNodeId,0);
assert.equal(calls[0].apiBase,'https://cms.8media.hu');
const again=await window.comesyAdOceanPage.activate({consentGranted:true});
assert.equal(again.status,'already_activated');
assert.equal(calls.length,2);
console.log('AdOcean publisher consent, grouping and idempotency: OK');

// A single editorial ad slot may map to two independent AdOcean MASTERs.
const responsiveGroups=[
 {folder_node:10,master_node:11,positions:[{nodeId:12,element:'#desktop',device:'desktop'}]},
 {folder_node:10,master_node:13,positions:[{nodeId:14,element:'#mobile',device:'mobile'}]},
 {folder_node:10,master_node:0,positions:[{nodeId:15,element:'#shared',device:'all'}]}
];
for(const viewport of ['mobile','desktop']){
 const requests=[];
 const w={
  location:{origin:'https://cms.8media.hu'},
  matchMedia:query=>({matches:viewport==='mobile'&&query==='(max-width: 767px)'}),
  comesyAdOcean:{mount:async opts=>{requests.push(opts);return {status:'mounted',count:opts.placements.length};}}
 };
 const d={getElementById:id=>id==='cms-ad-external'?{
  textContent:JSON.stringify({site_id:1,groups:responsiveGroups})
 }:null};
 runInNewContext(script,{window:w,document:d,console});
 assert.equal(requests.length,0,'No AdOcean calls without explicit CMP consent.');
 const result=await w.comesyAdOceanPage.activate({consentGranted:true});
 assert.equal(result.count,2,'Only the active device and shared slot should be mounted.');
 assert.equal(result.device,viewport);
 assert.equal(requests.length,2);
 const ids=requests.flatMap(call=>call.placements.map(x=>x.nodeId));
 assert.deepEqual(ids,viewport==='mobile'?[14,15]:[12,15]);
 assert(!ids.includes(viewport==='mobile'?12:14),'Inactive device LEAF cannot trigger a provider request.');
 const same=await w.comesyAdOceanPage.activate({consentGranted:true});
 assert.equal(same.status,'already_activated','Ads must not be called twice when activation is repeated.');
}
console.log('AdOcean desktop/mobile responsive request routing: OK');
