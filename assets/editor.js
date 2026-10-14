document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('editor-form');
 const status=document.getElementById('local-save');
 const raw=document.getElementById('initial-content');
 let initial=[];try{initial=JSON.parse(raw.textContent)}catch(e){}
 const quill=new Quill('#rich-editor',{theme:'snow',modules:{toolbar:'#editor-toolbar'},placeholder:'Kezdd el írni a cikket…'});
 if(initial.length===1&&initial[0].type==='richtext'){
  try{const delta=JSON.parse(initial[0].text);if(Array.isArray(delta.ops))quill.setContents(delta,'silent')}catch(e){}
 }else if(initial.length){
  const ops=[];
  for(const b of initial){
   if(b.type==='image'){ops.push({insert:{image:b.text}});ops.push({insert:'\n'});continue}
   if(b.type==='embed'){ops.push({insert:b.text,attributes:{link:b.text}});ops.push({insert:'\n'});continue}
   ops.push({insert:(b.text||'')+'\n',attributes:b.type==='heading'?{header:2}:b.type==='quote'?{blockquote:true}:b.type==='list'?{list:'bullet'}:{}})
  }
  if(ops.length)quill.setContents({ops},'silent');
 }
 const modal=document.getElementById('ed-modal');
 const modalTitle=document.getElementById('ed-modal-title');
 const modalBody=document.getElementById('ed-modal-body');
 const modalActions=document.getElementById('ed-modal-actions');
 function showDialog(title,fields,buttons){
  return new Promise(resolve=>{
   modalTitle.textContent=title;modalBody.replaceChildren();modalActions.replaceChildren();
   const inputs=fields.map(field=>{const label=document.createElement('label');label.textContent=field.label;const input=document.createElement('input');input.value=field.value||'';input.placeholder=field.placeholder||'';input.type=field.type||'text';label.append(input);modalBody.append(label);return input});
   let finished=false;
   const done=value=>{if(finished)return;finished=true;modal.close();resolve(value)};
   buttons.forEach(button=>{const b=document.createElement('button');b.type='button';b.textContent=button.label;b.className=button.primary?'ed-modal-primary':'ed-modal-secondary';b.onclick=()=>done(button.cancel?null:inputs.map(x=>x.value));modalActions.append(b)});
   modal.oncancel=e=>{e.preventDefault();done(null)};
   modal.showModal();inputs[0]?.focus();
  });
 }
 document.getElementById('insert-recommendation').addEventListener('click',async()=>{
  const kind=await showDialog('Ajánló beszúrása',[{label:'Típus',value:'cikk',placeholder:'cikk vagy könyv'},{label:'Ajánlott tartalom URL-je',type:'url',placeholder:'https://'}],[{label:'Mégse',cancel:true},{label:'Beszúrás',primary:true}]);
  if(!kind)return;
  const [type,url]=kind;
  if(!['cikk','könyv'].includes(type.trim().toLowerCase())){await showDialog('Érvénytelen típus',[],[{label:'Rendben',primary:true}]);return}
  try{const u=new URL(url);if(!['https:','http:'].includes(u.protocol))throw Error();const index=quill.getSelection(true)?.index??quill.getLength();quill.insertText(index,'\\n['+(type==='könyv'?'Könyvajánló':'Cikkajánló')+'] '+url+'\\n','user')}catch(e){await showDialog('Érvénytelen URL',[],[{label:'Rendben',primary:true}])}
 });
 const siteChecks=[...document.querySelectorAll('.ed-site-check')];
 const syncSite=()=>{
  const checked=siteChecks.filter(el=>el.checked);
  const active=checked[0]?.value;
  document.querySelectorAll('.ed-term,#ed-tag-existing label').forEach(el=>{
   const visible=!active||el.dataset.site===active||el.dataset.site==='0';
   el.hidden=!visible;
   const input=el.querySelector('input');
   if(input)input.disabled=!visible;
  });
  refreshTags();
 };
 siteChecks.forEach(el=>el.addEventListener('change',()=>{
  if(el.checked)siteChecks.forEach(other=>{if(other!==el)other.checked=false});
  if(!siteChecks.some(x=>x.checked))el.checked=true;
  syncSite();
 }));
 if(siteChecks.length===1)siteChecks[0].checked=true;
 const search=document.getElementById('ed-tag-search');
 const suggestions=document.getElementById('ed-tag-suggestions');
 const chips=document.getElementById('ed-tag-chips');
 const newTags=new Map();
 const allTags=[...document.querySelectorAll('#ed-tag-existing label')];
 const normalize=s=>s.trim().toLocaleLowerCase('hu');
 function refreshTags(){
  chips.replaceChildren();
  allTags.filter(l=>!l.hidden&&l.querySelector('input').checked).forEach(l=>{
   const chip=document.createElement('span');chip.className='ed-chip';chip.textContent=l.dataset.label+' ';
   const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.setAttribute('aria-label',l.dataset.label+' eltávolítása');remove.onclick=()=>{l.querySelector('input').checked=false;refreshTags()};chip.append(remove);chips.append(chip);
  });
  for(const [key,label] of newTags){
   const chip=document.createElement('span');chip.className='ed-chip';chip.textContent=label+' ';
   const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.onclick=()=>{newTags.delete(key);refreshTags()};chip.append(remove);chips.append(chip);
  }
  document.getElementById('ed-tag-names').value=[...newTags.values()].join(',');
 }
 function chooseTag(label){
  const key=normalize(label);if(!key)return;
  const found=allTags.find(l=>!l.hidden&&normalize(l.dataset.label)===key);
  if(found)found.querySelector('input').checked=true;
  else if(![...newTags.keys()].includes(key))newTags.set(key,label.trim());
  search.value='';suggestions.hidden=true;search.setAttribute('aria-expanded','false');refreshTags();
 }
 function showSuggestions(){
  const value=normalize(search.value);
  suggestions.replaceChildren();
  if(!value){suggestions.hidden=true;search.setAttribute('aria-expanded','false');return}
  const matches=allTags.filter(l=>!l.hidden&&normalize(l.dataset.label).includes(value)).slice(0,8);
  matches.forEach(l=>{const b=document.createElement('button');b.type='button';b.textContent=l.dataset.label;b.onclick=()=>chooseTag(l.dataset.label);suggestions.append(b)});
  if(!matches.some(l=>normalize(l.dataset.label)===value)){
   const b=document.createElement('button');b.type='button';b.textContent='+ Új címke: '+search.value.trim();b.onclick=()=>chooseTag(search.value);suggestions.append(b);
  }
  suggestions.hidden=false;search.setAttribute('aria-expanded','true');
 }
 search?.addEventListener('input',showSuggestions);
 search?.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===','){e.preventDefault();const first=suggestions.querySelector('button');if(first&&e.key==='Enter')first.click();else chooseTag(search.value)}if(e.key==='Escape')suggestions.hidden=true});
 allTags.forEach(l=>l.querySelector('input').addEventListener('change',refreshTags));
 syncSite();
 form.addEventListener('submit',e=>{if(!siteChecks.some(x=>x.checked)){e.preventDefault();showDialog('Válassz webhelyet',[],[{label:'Rendben',primary:true}]);return}if(search.value.trim())chooseTag(search.value)});
 const serialize=()=>[{type:'richtext',text:JSON.stringify(quill.getContents())}];
 const backupKey='comesy-wysiwyg-'+new URLSearchParams(location.search).get('id');
 const saved=(()=>{try{return JSON.parse(localStorage.getItem(backupKey)||'null')}catch(e){return null}})();
 if(saved&&saved.time&&saved.time>Date.now()-7*86400000){
  const answer=awaitRestore(saved);
 }
 async function awaitRestore(saved){
 const answer=await showDialog('Helyi piszkozat található',[],[{label:'Megtartom a jelenlegit',cancel:true},{label:'Piszkozat visszaállítása',primary:true}]);
 if(answer===null)return;
  document.getElementById('title').value=saved.title||'';
  document.getElementById('lead').value=saved.lead||'';
  if(saved.delta?.ops)quill.setContents(saved.delta);
 }
 form.addEventListener('submit',()=>{document.getElementById('blocks-data').value=JSON.stringify(serialize())});
 setInterval(()=>{try{localStorage.setItem(backupKey,JSON.stringify({title:document.getElementById('title').value,lead:document.getElementById('lead').value,delta:quill.getContents(),time:Date.now()}));status.textContent='Helyi piszkozat mentve'}catch(e){}},15000);
 document.getElementById('preview-button').addEventListener('click',()=>{
  const w=window.open('','_blank');if(!w)return;
  const d=w.document;d.title='comesy cikkelőnézet';
  const style=d.createElement('style');style.textContent='body{max-width:760px;margin:40px auto;padding:20px;font:18px/1.7 system-ui}img{max-width:100%}h1{line-height:1.2}';d.head.append(style);
  const title=d.createElement('h1');title.textContent=document.getElementById('title').value;d.body.append(title);
  const lead=d.createElement('p');lead.textContent=document.getElementById('lead').value;d.body.append(lead);
  const body=d.createElement('div');body.innerHTML=quill.getSemanticHTML();body.querySelectorAll('script,iframe,object,embed,form').forEach(el=>el.remove());body.querySelectorAll('*').forEach(el=>{for(const a of [...el.attributes])if(a.name.startsWith('on'))el.removeAttribute(a.name)});d.body.append(body);
 });
});