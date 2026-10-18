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
