document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('editor-form');
 const status=document.getElementById('local-save');
 const metaPanels=[...document.querySelectorAll('.ui-editor-meta')];
 const smallEditor=window.matchMedia('(max-width:767px)');
 const syncMeta=()=>{
  metaPanels.forEach((panel,index)=>{panel.open=!smallEditor.matches||index===0;});
 };
 if(metaPanels.length){syncMeta();smallEditor.addEventListener('change',syncMeta);}
 const raw=document.getElementById('initial-content');
 let initial=[];try{initial=JSON.parse(raw.textContent)}catch(e){}
 if(typeof window.Quill!=='function'){
  const error=document.createElement('p');error.className='error';error.setAttribute('role','alert');
  error.textContent='A szövegszerkesztő nem töltődött be. Ellenőrizd a kapcsolatot, majd frissítsd az oldalt. A cikkmentést a tartalom védelmében letiltottuk.';
  form.prepend(error);
  document.querySelectorAll('.editor-save-button,[form="editor-form"]').forEach(button=>button.disabled=true);
  form.addEventListener('submit',e=>e.preventDefault());
  return;
 }
 const statusInput=document.getElementById('status'),scheduleFields=document.getElementById('editor-schedule-fields');
 const scheduleInput=document.getElementById('schedule-at');
 const syncSchedule=()=>{
  const visible=statusInput?.value==='scheduled';
  if(scheduleFields)scheduleFields.hidden=!visible;
  if(scheduleInput){scheduleInput.required=!!visible;scheduleInput.disabled=!visible;}
 };
 statusInput?.addEventListener('change',syncSchedule);syncSchedule();
 const featured=document.querySelector('#og-image-url');
 featured?.addEventListener('change',()=>{
  const preview=document.querySelector('#featured-image-preview');
  if(!preview)return;
  preview.hidden=!(featured.value.trim().startsWith('https://')||featured.value.trim().startsWith('http://'));
  if(!preview.hidden)preview.src=featured.value.trim();
 });
 const quill=new Quill('#rich-editor',{theme:'snow',modules:{toolbar:'#editor-toolbar'},placeholder:'Kezdd el írni a cikket…'});
 // Preserve every supported imported figure, table, emphasis and media embed.
 // Unlike Quill's default import, the contenteditable DOM retains these nodes.
 const richMode=initial.length===1&&initial[0].type==='richhtml';
 let richSource=null;
 if(richMode){
  document.getElementById('editor-toolbar').hidden=true;
  document.getElementById('rich-editor').hidden=true;
  const wrap=document.createElement('section');wrap.className='ed-visual-rich';
  const bar=document.createElement('div');bar.className='ed-rich-toolbar';bar.setAttribute('role','toolbar');
  bar.setAttribute('aria-label','Cikktörzs formázása');
  const edit=document.createElement('div');
  edit.className='ed-rich-canvas';edit.id='import-visual-editor';
  edit.contentEditable='true';edit.spellcheck=true;edit.setAttribute('role','textbox');
  edit.setAttribute('aria-label','Cikktörzs vizuális szerkesztése');edit.setAttribute('aria-multiline','true');
  edit.innerHTML=initial[0].text||'';
  richSource=edit;
  const info=document.createElement('p');info.className='ed-rich-notice';
  info.textContent='A képek, táblázatok és beágyazások a szöveggel együtt megmaradnak. Az előnézet külön lapon nyitható meg.';
  const isInside=()=>{const selection=window.getSelection();return !!selection?.anchorNode&&edit.contains(selection.anchorNode)};
  let savedRange=null;
  const remember=()=>{if(isInside()&&window.getSelection().rangeCount)savedRange=window.getSelection().getRangeAt(0).cloneRange()};
  const restore=()=>{
   edit.focus();
   if(savedRange&&edit.contains(savedRange.commonAncestorContainer)){
    const selection=window.getSelection();selection.removeAllRanges();selection.addRange(savedRange);
   }
  };
  ['keyup','mouseup','focusout'].forEach(type=>edit.addEventListener(type,remember));
  bar.addEventListener('mousedown',e=>{if(e.target.closest('button'))e.preventDefault()});
  const command=(cmd,value=null)=>{restore();document.execCommand(cmd,false,value);remember();edit.dispatchEvent(new Event('input',{bubbles:true}))};
  const tool=(label,title,action)=>{
   const button=document.createElement('button');button.type='button';button.className='ed-rich-tool';
   button.textContent=label;button.title=title;button.setAttribute('aria-label',title);
   button.addEventListener('click',action);bar.append(button);return button;
  };
  const format=document.createElement('select');format.className='ed-rich-format';
  format.setAttribute('aria-label','Bekezdés formázása');
  [['p','Normál'],['h2','Címsor 2'],['h3','Címsor 3'],['h4','Címsor 4']].forEach(([tag,name])=>{const option=document.createElement('option');option.value=tag;option.textContent=name;format.append(option)});
  format.addEventListener('change',()=>command('formatBlock',format.value));
  bar.append(format);
  tool('B','Félkövér',()=>command('bold')).classList.add('ed-tool-bold');
  tool('I','Dőlt',()=>command('italic')).classList.add('ed-tool-italic');
  tool('U','Aláhúzott',()=>command('underline')).classList.add('ed-tool-underline');
  tool('▧','Szövegkiemelés',()=>command('hiliteColor','#fff2b3'));
  tool('❝','Idézet',()=>command('formatBlock','blockquote'));
  tool('•','Felsorolás',()=>command('insertUnorderedList'));
  tool('1.','Számozott lista',()=>command('insertOrderedList'));
  tool('🔗','Hivatkozás beszúrása',()=>{
   const link=window.prompt('Hivatkozás URL-je (https:// vagy http://):','https://');
   if(link===null)return;
   try{const value=new URL(link.trim());if(!['https:','http:'].includes(value.protocol))throw Error();command('createLink',value.href)}catch(e){window.alert('Csak HTTP vagy HTTPS hivatkozás adható meg.')}
  });
  tool('Kép','Kép feltöltése',()=>document.querySelector('#editor-image-picker')?.click());
  tool('▦','2×2 táblázat beszúrása',()=>{
   restore();
   document.execCommand('insertHTML',false,'<table><tbody><tr><td>1. cella</td><td>2. cella</td></tr><tr><td>3. cella</td><td>4. cella</td></tr></tbody></table><p></p>');
   remember();edit.dispatchEvent(new Event('input',{bubbles:true}));
  });
  // Imported rich content is never turned into an unvalidated executable document.
  // HTML clipboard and drop are disabled; only plain text may be pasted.
  edit.addEventListener('paste',e=>{
   e.preventDefault();
   const plain=e.clipboardData?.getData('text/plain')||'';
   document.execCommand('insertText',false,plain);
  });
  edit.addEventListener('drop',e=>e.preventDefault());
  wrap.append(bar,edit,info);
  document.getElementById('rich-editor').after(wrap);
 }

 if(initial.length===1&&initial[0].type==='richtext'){
  try{const delta=JSON.parse(initial[0].text);if(Array.isArray(delta.ops))quill.setContents(delta,'silent')}catch(e){}
 }else if(initial.length&&!richMode){
  const ops=[];
  for(const b of initial){
   if(b.type==='image'){ops.push({insert:{image:b.text}});ops.push({insert:'\n'});continue}
   if(b.type==='embed'){ops.push({insert:b.text,attributes:{link:b.text}});ops.push({insert:'\n'});continue}
   ops.push({insert:(b.text||'')+'\n',attributes:b.type==='heading'?{header:2}:b.type==='quote'?{blockquote:true}:b.type==='list'?{list:'bullet'}:{}})
  }
  if(ops.length)quill.setContents({ops},'silent');
 }
 const imagePicker=document.createElement('input');
 imagePicker.type='file';imagePicker.id='editor-image-picker';imagePicker.accept='image/jpeg,image/png,image/webp,image/gif';imagePicker.hidden=true;
 document.body.append(imagePicker);
 let uploadingImage=false;
 async function uploadImage(file,site){
  if(file.size<1||file.size>10485760)throw new Error('A kép mérete legfeljebb 10 MB lehet.');
  const data=new FormData();
  data.append('image',file,file.name||'beillesztett-kep.png');
  data.append('site_id',site);
  data.append('csrf',form.querySelector('[name=csrf]').value);
  const response=await fetch('/editor-upload.php',{method:'POST',body:data,credentials:'same-origin'});
  let result;try{result=await response.json();}catch(e){throw new Error(response.status===413?'A fájl túl nagy a kiszolgáló számára.':'A kiszolgáló nem tudta feldolgozni a képfeltöltést.');}
  if(!response.ok||!result.ok)throw new Error(result.error||'Kép feltöltési hiba');
  return new URL(result.url,location.origin).href;
 }
 quill.getModule('toolbar').addHandler('image',()=>imagePicker.click());
 imagePicker.addEventListener('change',async()=>{
  const file=imagePicker.files?.[0];if(!file)return;
  imagePicker.value='';
  if(file.size>10485760){await showDialog('Túl nagy kép',[],[{label:'Rendben',primary:true}]);return}
  const site=(document.querySelector('.ed-site-check[data-primary="1"]:checked')||document.querySelector('.ed-site-check:checked'))?.value;
  if(!site){await showDialog('Válassz webhelyet',[],[{label:'Rendben',primary:true}]);return}
  uploadingImage=true;
  const saveButton=document.querySelector('.editor-save-button');
  if(saveButton)saveButton.disabled=true;
  status.textContent='Kép feltöltése folyamatban…';
  try{
   const url=await uploadImage(file,site);
   const index=quill.getSelection(true)?.index??quill.getLength();
   quill.insertEmbed(index,'image',url,'user');
   quill.setSelection(index+1);
   status.textContent='Kép feltöltve a médiatárba';
  }catch(error){
   status.textContent='A kép feltöltése sikertelen';
   await showDialog('Nem sikerült feltölteni a képet',[{label:'Hiba',value:error.message}],[{label:'Rendben',primary:true}]);
  }finally{uploadingImage=false;if(saveButton)saveButton.disabled=false}
 });
 form.addEventListener('submit',e=>{if(uploadingImage){e.preventDefault();showDialog('Kép feltöltése folyamatban',[],[{label:'Rendben',primary:true}])}});
 // Convert images pasted or dragged from mobile apps into permanent media URLs before submitting.
 let processingPasted=false;
 form.addEventListener('submit',async e=>{
  if(processingPasted){e.preventDefault();return;}
  if(e.submitter?.name==='action'&&e.submitter.value==='restore')return;
  const ops=quill.getContents().ops;
  const inline=ops.filter(op=>typeof op.insert?.image==='string'&&op.insert.image.startsWith('data:image/'));
  if(!inline.length)return;
  e.preventDefault();
  const site=(document.querySelector('.ed-site-check[data-primary="1"]:checked')||document.querySelector('.ed-site-check:checked'))?.value;
  if(!site){await showDialog('Válassz webhelyet',[],[{label:'Rendben',primary:true}]);return;}
  processingPasted=true;uploadingImage=true;status.textContent='Beillesztett képek feltöltése…';
  const submitter=e.submitter;
  try{
   const replacements=new Map();
   for(const op of inline){
    const uri=op.insert.image;
    if(replacements.has(uri))continue;
    const blob=await (await fetch(uri)).blob();
    const type=blob.type;
    if(!['image/jpeg','image/png','image/webp','image/gif'].includes(type))throw new Error('Nem támogatott képformátum.');
    const ext={'image/jpeg':'jpg','image/png':'png','image/webp':'webp','image/gif':'gif'}[type];
    const url=await uploadImage(new File([blob],'beillesztett-kep.'+ext,{type}),site);
    replacements.set(uri,url);
   }
   const updated={ops:ops.map(op=>typeof op.insert?.image==='string'&&replacements.has(op.insert.image)?{...op,insert:{image:replacements.get(op.insert.image)}}:op)};
   quill.setContents(updated,'silent');dirty=true;status.textContent='Képek feltöltve';
  }catch(error){status.textContent='Képfeltöltési hiba';await showDialog('A kép mentése sikertelen',[{label:'Hiba',value:error.message}],[{label:'Rendben',primary:true}]);return;}
  finally{processingPasted=false;uploadingImage=false;}
  if(submitter&&submitter.form===form)form.requestSubmit(submitter);else form.requestSubmit();
 },true);
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
  // All taxonomy entries below are already filtered by server-side access rights.
  // A user with explicit permission may attach global/other-site terms.
  document.querySelectorAll('.ed-term,#ed-tag-existing label').forEach(el=>{
   el.hidden=false;
   const input=el.querySelector('input');if(input)input.disabled=false;
  });
  refreshTags();
 };
 siteChecks.forEach(el=>el.addEventListener('change',()=>{
  if(el.dataset.primary==='1'&&!el.checked)el.checked=true;
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
 const serialize=()=>richMode?[{type:'richhtml',text:richSource.value}]:[{type:'richtext',text:JSON.stringify(quill.getContents())}];
 let dirty=false,unsaved=false,isSubmitting=false;
 const markChanged=()=>{dirty=true;unsaved=true;};
 quill.on('text-change',(_delta,_old,source)=>{if(source==='user')markChanged();});
 form.addEventListener('input',markChanged);
 form.addEventListener('change',markChanged);
 window.addEventListener('beforeunload',event=>{
  if(unsaved&&!isSubmitting){event.preventDefault();event.returnValue='';}
 });
 const backupKey='comesy-wysiwyg-'+new URLSearchParams(location.search).get('id');
 const saveConfirmed=new URLSearchParams(location.search).has('saved');
 if(saveConfirmed){try{localStorage.removeItem(backupKey);localStorage.removeItem('comesy-wysiwyg-null')}catch(e){}}
 const saved=saveConfirmed?null:(()=>{try{return JSON.parse(localStorage.getItem(backupKey)||'null')}catch(e){return null}})();
 if(saved&&saved.time&&saved.time>Date.now()-7*86400000){
  awaitRestore(saved);
 }
 async function awaitRestore(saved){
 const answer=await showDialog('Helyi piszkozat található',[],[{label:'Megtartom a jelenlegit',cancel:true},{label:'Piszkozat visszaállítása',primary:true}]);
 if(answer===null)return;
  document.getElementById('title').value=saved.title||'';
  document.getElementById('lead').value=saved.lead||'';
  if(richMode&&typeof saved.richHTML==='string'){richSource.value=saved.richHTML;updateRichPreview();}
  else if(saved.delta?.ops)quill.setContents(saved.delta);
  markChanged();
 }
 form.addEventListener('submit',e=>{
  document.getElementById('blocks-data').value=JSON.stringify(serialize());
  if(!e.defaultPrevented)isSubmitting=true;
  try{localStorage.setItem('comesy-pending-save',backupKey)}catch(error){}
 });
 setInterval(()=>{if(!dirty)return;try{localStorage.setItem(backupKey,JSON.stringify({title:document.getElementById('title').value,lead:document.getElementById('lead').value,delta:richMode?null:quill.getContents(),richHTML:richMode?richSource.value:null,time:Date.now()}));status.textContent='Helyi piszkozat mentve';dirty=false;}catch(e){}},15000);
 document.getElementById('preview-button').addEventListener('click',()=>{
  if(richMode){updateRichPreview();richFrame.scrollIntoView({behavior:'smooth',block:'center'});return;}
  const w=window.open('','_blank');if(!w)return;
  const d=w.document;d.title='comesy cikkelőnézet';
  const style=d.createElement('style');style.textContent='body{max-width:760px;margin:40px auto;padding:20px;font:18px/1.7 system-ui}img{max-width:100%}h1{line-height:1.2}';d.head.append(style);
  const title=d.createElement('h1');title.textContent=document.getElementById('title').value;d.body.append(title);
  const lead=d.createElement('p');lead.textContent=document.getElementById('lead').value;d.body.append(lead);
  const body=d.createElement('div');body.innerHTML=quill.getSemanticHTML();body.querySelectorAll('script,iframe,object,embed,form').forEach(el=>el.remove());body.querySelectorAll('*').forEach(el=>{for(const a of [...el.attributes])if(a.name.startsWith('on'))el.removeAttribute(a.name)});d.body.append(body);
 });
});