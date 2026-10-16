document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('editor-form');
 const status=document.getElementById('local-save');
 const metaPanels=[...document.querySelectorAll('.ui-editor-meta')];
 const smallEditor=window.matchMedia('(max-width:767px)');
 const syncMeta=()=>{
  metaPanels.forEach((panel,index)=>{panel.open=!smallEditor.matches||index===0;});
 };
 if(metaPanels.length){syncMeta();smallEditor.addEventListener('change',syncMeta);}
 const styleNode=document.getElementById('source-style-data');
 let sourceStyle={css:'',source:'',host:''};
 try{if(styleNode)sourceStyle=JSON.parse(styleNode.textContent||'{}')}catch{}
 if(typeof sourceStyle.css!=='string'||sourceStyle.css.length>36000)sourceStyle.css='';

 const shell=sourceStyle.site_design&&typeof sourceStyle.site_design==='object'?sourceStyle.site_design:{};
 const safeSourceUrl=value=>{
  try{const url=new URL(value);return ['https:','http:'].includes(url.protocol)?url.href:''}catch{return ''}
 };
 // Build the global source shell only from validated text / URLs.
 // This is a design preview, never a copy of source DOM or executable JS.
 const sourceChrome=(doc)=>{
  if(!shell.name&&!shell.logo_url&&!shell.header_links?.length&&!shell.footer_links?.length)return;
  const sheet=doc.createElement('style');
  sheet.textContent="body{margin:0!important;background:#fff!important;max-width:none!important;padding:0!important}.cms-source-header{background:#fff;border-bottom:1px solid #061126}.cms-source-head-inner{max-width:1240px;min-height:81px;margin:auto;display:grid;grid-template-columns:43px minmax(0,1fr) 82px;align-items:center;gap:9px;padding:12px 17px}.cms-source-brand{grid-column:2;grid-row:1;display:flex;justify-content:center;align-items:center;text-decoration:none;color:#080e20;font:800 28px Georgia,serif;text-align:center}.cms-source-brand img{max-width:55vw;max-height:59px;object-fit:contain}.cms-source-brand img+span{display:none}.cms-source-head-start{grid-column:1;grid-row:1}.cms-source-head-end{grid-column:3;grid-row:1;display:flex;align-items:center;gap:13px;justify-content:flex-end}.cms-source-icon{width:23px;height:23px;stroke:#080e20;stroke-width:2;fill:none;stroke-linecap:round}.cms-source-menu-toggle{position:relative}.cms-source-menu-toggle summary{cursor:pointer;list-style:none}.cms-source-menu-toggle summary::-webkit-details-marker{display:none}.cms-source-menu-toggle nav{display:none;position:absolute;top:35px;right:0;z-index:12;width:min(320px,82vw);max-height:65vh;overflow:auto;background:#fff;padding:16px;border:1px solid #dce1e9;border-radius:8px;box-shadow:0 10px 28px #0002}.cms-source-menu-toggle[open] nav{display:grid;gap:10px}.cms-source-menu-toggle nav a{font:600 14px system-ui;color:#071124;text-decoration:none}.cms-source-trends{overflow-x:auto;border-top:1px solid #071124;border-bottom:2px solid #071124}.cms-source-trends-inner{display:flex;gap:45px;padding:10px 13px;white-space:nowrap}.cms-source-trends a{font:800 14px system-ui;text-transform:uppercase;color:#080e20;text-decoration:none}.cms-source-breadcrumb{display:flex;gap:14px;align-items:center;color:#6b7484;font:600 15px system-ui;margin:55px 0 35px}.cms-source-breadcrumb a{color:#080e20;text-decoration:none}main.cms-source-article,article.cms-source-article{max-width:980px;margin:0 auto 30px;padding:0 17px 30px;border:0;box-shadow:none;border-radius:0;background:#fff;color:#080e20}.cms-source-article h1{font:800 clamp(28px,7.3vw,48px)/1.22 system-ui;color:#080e20;letter-spacing:-.02em;margin:0 0 28px}.cms-source-article p.lead{font:18px/1.65 system-ui;color:#111729}.cms-source-footer{background:#17243e;color:#fff;padding:26px 18px;margin-top:30px}.cms-source-footer-inner{max-width:1200px;margin:auto;display:grid;gap:15px}.cms-source-footer nav{display:flex;flex-wrap:wrap;gap:15px}.cms-source-footer a{font:600 13px system-ui;color:inherit;text-decoration:none}.cms-source-footer p{font:13px/1.5 system-ui;margin:0}";
  doc.head.append(sheet);
  if(sourceStyle.css){const designCss=doc.createElement('style');designCss.textContent=sourceStyle.css;doc.head.append(designCss);}
  const nav=(items,where)=>{
   const links=(Array.isArray(items)?items:[]).slice(0,14);
   if(!links.length)return;
   const el=doc.createElement('nav');el.className=where==='header'?'cms-source-menu':'';
   el.setAttribute('aria-label',where==='header'?'A forrás főmenüje':'A forrás láblécmenüje');
   for(const item of links){
    const href=safeSourceUrl(item?.url||'');
    if(!href||!item?.label)continue;
    const link=doc.createElement('a');link.href=href;link.textContent=String(item.label).slice(0,80);
    link.target='_blank';link.rel='noopener noreferrer';el.append(link);
   }
   return el;
  };

  const makeIcon=path=>{
   const svg=doc.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('class','cms-source-icon');
   const shape=doc.createElementNS('http://www.w3.org/2000/svg','path');shape.setAttribute('d',path);svg.append(shape);return svg;
  };
  const header=doc.createElement('header');header.className='cms-source-header';
  const inner=doc.createElement('div');inner.className='cms-source-head-inner';
  const search=doc.createElement('span');search.className='cms-source-head-start';search.setAttribute('aria-hidden','true');search.append(makeIcon('M16.5 16.5 21 21 M18 10.5a7.5 7.5 0 1 1-15 0a7.5 7.5 0 1 1 15 0'));inner.append(search);
  const brand=doc.createElement('a');brand.className='cms-source-brand';
  brand.href=safeSourceUrl(sourceStyle.source)||'#';brand.target='_blank';brand.rel='noopener noreferrer';
  const logo=safeSourceUrl(shell.logo_url||'')||(['magyarnemzet.hu','www.magyarnemzet.hu'].includes(sourceStyle.host)?'https://magyarnemzet.hu/assets/images/logo-mno-dark.svg':'');
  if(logo){const img=doc.createElement('img');img.src=logo;img.alt=String(shell.name||sourceStyle.host||'').slice(0,100);brand.append(img);}
  const name=doc.createElement('span');name.textContent=String(shell.name||sourceStyle.host||'').slice(0,100);brand.append(name);inner.append(brand);
  const tools=doc.createElement('div');tools.className='cms-source-head-end';
  const account=doc.createElement('span');account.setAttribute('aria-hidden','true');account.append(makeIcon('M12 11a4 4 0 1 0 0-8a4 4 0 1 0 0 8 M4 21v-3a8 8 0 0 1 16 0v3'));tools.append(account);
  const drawer=doc.createElement('details');drawer.className='cms-source-menu-toggle';
  const summary=doc.createElement('summary');summary.setAttribute('aria-label','Menü megnyitása');summary.append(makeIcon('M3 6h18 M3 12h18 M3 18h18'));drawer.append(summary);
  drawer.append(nav(shell.header_links,'drawer','A forrás főmenüje'));tools.append(drawer);inner.append(tools);header.append(inner);
  const topics=nav(shell.topic_links,'','Kiemelt témák');
  if(topics.children.length){const bar=doc.createElement('div');bar.className='cms-source-trends';const row=doc.createElement('div');row.className='cms-source-trends-inner';while(topics.firstChild)row.append(topics.firstChild);bar.append(row);header.append(bar);}
  doc.body.prepend(header);
  const article=doc.querySelector('main.cms-source-article,article.cms-source-article');
  if(article){
   const breadcrumb=doc.createElement('nav');breadcrumb.className='cms-source-breadcrumb';breadcrumb.setAttribute('aria-label','Morzsanavigáció');
   const title=doc.createElement('a');title.href=safeSourceUrl(sourceStyle.source)||'#';title.textContent=String(shell.name||sourceStyle.host||'');title.target='_blank';title.rel='noopener noreferrer';breadcrumb.append(title);
   try{const url=new URL(sourceStyle.source);const section=url.pathname.split('/').filter(Boolean)[0]||'';
    if(section){const span=doc.createElement('span');span.textContent='›';breadcrumb.append(span);const label=doc.createElement('span');label.textContent=({belfold:'Belföld',kulfold:'Külföld',gazdasag:'Gazdaság',sport:'Sport',kultura:'Kultúra',tudomany:'Tudomány'})[section]||section;breadcrumb.append(label);}
   }catch{}
   article.prepend(breadcrumb);
  }
  const footer=doc.createElement('footer');footer.className='cms-source-footer';
  const footerInner=doc.createElement('div');footerInner.className='cms-source-footer-inner';
  const footerNav=nav(shell.footer_links,'footer');if(footerNav)footerInner.append(footerNav);
  const copyright=String(shell.copyright||shell.footer_text||'').slice(0,420);
  if(copyright){const text=doc.createElement('p');text.textContent=copyright;footerInner.append(text);}
  footer.append(footerInner);doc.body.append(footer);
 };

 const sourceRefresh=document.getElementById('source-style-refresh');
 sourceRefresh?.addEventListener('click',async()=>{
  sourceRefresh.disabled=true;
  const label=document.getElementById('source-style-label');
  if(label)label.textContent='Forrásarculat letöltése folyamatban…';
  try{
   const payload=new FormData();payload.set('id',sourceRefresh.dataset.id);payload.set('csrf',sourceRefresh.dataset.csrf);
   const response=await fetch('/source-style-refresh.php',{method:'POST',body:payload,credentials:'same-origin'});
   const data=await response.json();
   if(!response.ok||!data.ok)throw new Error(data.error||'A letöltés sikertelen.');
   if(label)label.textContent=data.available?'Stílus rögzítve: '+data.host+'. Az előnézet frissítéséhez töltsd újra ezt az oldalt.':'A forrásból nem sikerült használható CSS-t kiolvasni.';
  }catch(err){if(label)label.textContent=err.message||'A forrásarculat frissítése nem sikerült.';}
  finally{sourceRefresh.disabled=false;}
 });
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
 const featuredPreview=document.getElementById('featured-image-preview');
 const featuredEmpty=document.getElementById('featured-empty-message');
 const featuredClear=document.getElementById('featured-clear');
 const picker=document.getElementById('featured-media-picker');
 const updateFeatured=()=>{
  const raw=featured?.value.trim()||'';
  const valid=raw.startsWith('/media-file.php?id=')||raw.startsWith('https://')||raw.startsWith('http://');
  if(featuredPreview){featuredPreview.hidden=!valid;featuredPreview.src=valid?raw:'';}
  if(featuredEmpty)featuredEmpty.hidden=!!valid;
  if(featuredClear)featuredClear.hidden=!valid;
 };
 const setFeatured=url=>{
  if(!featured)return;
  featured.value=url?new URL(url,location.origin).href:'';updateFeatured();
  featured.dispatchEvent(new Event('input',{bubbles:true}));
  if(picker?.open)picker.close();
 };
 featured?.addEventListener('input',updateFeatured);
 updateFeatured();
 featuredClear?.addEventListener('click',()=>setFeatured(''));
 const searchMedia=document.getElementById('featured-picker-search');
 const mediaGrid=document.getElementById('featured-picker-grid');
 const mediaMore=document.getElementById('featured-picker-more');
 const mediaFeedback=document.getElementById('featured-picker-feedback');
 let mediaButtons=[...document.querySelectorAll('.ui-picker-asset')];
 const normalizeMedia=value=>(value||'').toLocaleLowerCase('hu').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
 const filterMedia=()=>{
  const q=normalizeMedia(searchMedia?.value.trim()||'');
  const selected=featured?.value||'';
  let count=0;
  for(const button of mediaButtons){
   const visible=!q||normalizeMedia(button.dataset.featuredFilter).includes(q);
   button.hidden=!visible;
   button.setAttribute('aria-pressed',String(new URL(button.dataset.featuredUrl,location.origin).href===selected));
   if(visible)count++;
  }
  const empty=document.getElementById('featured-picker-no-results');
  if(empty)empty.hidden=count!==0;
  const counter=document.getElementById('featured-picker-count');
  if(counter)counter.textContent=count+' megjelenített kép';
 };
 let mediaPage=1,mediaHasMore=false,mediaRequest=0,mediaTimer;
 const loadMedia=async(page=1)=>{
  const ticket=++mediaRequest;
  if(mediaMore)mediaMore.disabled=true;
  if(mediaFeedback)mediaFeedback.textContent='Képek betöltése…';
  const params=new URLSearchParams({q:searchMedia?.value.trim()||'',page:String(page)});
  try{
   const response=await fetch('/editor-media-search.php?'+params,{credentials:'same-origin'});
   if(!response.ok)throw new Error('A képek keresése sikertelen.');
   const data=await response.json();
   if(ticket!==mediaRequest||!picker?.open)return;
   if(!data||!Array.isArray(data.items))throw new Error('Hibás médiatár-válasz.');
   if(page===1)mediaGrid.replaceChildren();
   for(const image of data.items){
    const button=document.createElement('button');
    button.type='button';button.className='ui-picker-asset';
    button.dataset.featuredUrl=image.url;
    button.dataset.featuredSite=String(image.site_id);
    button.dataset.featuredFilter=[image.name,image.alt,image.site].join(' ');
    button.setAttribute('aria-label',image.name+' kiválasztása');
    const img=document.createElement('img');img.src=image.url;img.alt=image.alt||'';img.loading='lazy';
    const filename=document.createElement('span');filename.textContent=image.name;
    const site=document.createElement('small');site.textContent=image.site;
    button.append(img,filename,site);mediaGrid.append(button);
   }
   mediaButtons=[...mediaGrid.querySelectorAll('.ui-picker-asset')];
   mediaPage=data.page;mediaHasMore=!!data.has_more;
   if(mediaMore){mediaMore.hidden=!mediaHasMore;mediaMore.disabled=false;}
   if(mediaFeedback)mediaFeedback.textContent='';
   filterMedia();
   const counter=document.getElementById('featured-picker-count');
   if(counter)counter.textContent=mediaButtons.length+' / '+data.total+' kép';
  }catch(err){
   if(ticket!==mediaRequest)return;
   if(mediaFeedback)mediaFeedback.textContent=err.message||'A médiatár nem érhető el.';
   if(mediaMore)mediaMore.disabled=false;
   // Keep the server-rendered gallery if the network query fails.
  }
 };
 searchMedia?.addEventListener('input',()=>{
  clearTimeout(mediaTimer);
  mediaTimer=setTimeout(()=>loadMedia(1),250);
 });
 mediaMore?.addEventListener('click',()=>{if(mediaHasMore)loadMedia(mediaPage+1)});
 document.getElementById('featured-choose')?.addEventListener('click',()=>{
  picker?.showModal();searchMedia?.focus();loadMedia(1);
 });
 mediaGrid?.addEventListener('click',e=>{
  const button=e.target.closest('.ui-picker-asset');
  if(button&&mediaGrid.contains(button))setFeatured(button.dataset.featuredUrl||'');
 });
 const closePicker=()=>picker?.close();
 document.getElementById('featured-picker-close')?.addEventListener('click',closePicker);
 document.getElementById('featured-picker-cancel')?.addEventListener('click',closePicker);
 picker?.addEventListener('click',e=>{if(e.target===picker)closePicker();});
 const pickerFile=document.getElementById('featured-picker-file');
 const pickerUpload=document.getElementById('featured-picker-upload');
 const pickerFeedback=document.getElementById('featured-picker-feedback');
 pickerUpload?.addEventListener('click',()=>pickerFile?.click());
 pickerFile?.addEventListener('change',async()=>{
  const file=pickerFile.files?.[0];if(!file)return;
  pickerFile.value='';
  const site=(document.querySelector('.ed-site-check[data-primary="1"]:checked')||document.querySelector('.ed-site-check:checked'))?.value;
  if(!site){pickerFeedback.textContent='Előbb válassz webhelyet a cikkhez.';return;}
  if(file.size<1||file.size>10485760){pickerFeedback.textContent='A kép legfeljebb 10 MB lehet.';return;}
  pickerUpload.disabled=true;pickerFeedback.textContent='A kép feltöltése folyamatban…';
  try{
   const url=await uploadImage(file,site);
   pickerFeedback.textContent='Kép feltöltve, kiemelt képként kiválasztva.';
   setFeatured(url);
  }catch(error){pickerFeedback.textContent=error.message||'A feltöltés nem sikerült.';}
  finally{pickerUpload.disabled=false;}
 });
 const quill=new Quill('#rich-editor',{theme:'snow',modules:{toolbar:'#editor-toolbar'},placeholder:'Kezdd el írni a cikket…'});
 // Preserve every supported imported figure, table, emphasis and media embed.
 // Unlike Quill's default import, the contenteditable DOM retains these nodes.
 const richMode=initial.length===1&&initial[0].type==='richhtml';
 let richSource=null,insertRichHTML=null;
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
  insertRichHTML=html=>{restore();document.execCommand('insertHTML',false,html);remember();edit.dispatchEvent(new Event('input',{bubbles:true}))};
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
   if(richMode){
    const safeURL=new URL(url,location.href).href.replaceAll('&','&amp;').replaceAll('"','&quot;');
    insertRichHTML('<figure><img src="'+safeURL+'" alt=""></figure><p></p>');
   }else{
    const index=quill.getSelection(true)?.index??quill.getLength();
    quill.insertEmbed(index,'image',url,'user');
    quill.setSelection(index+1);
   }
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
 const serialize=()=>richMode?[{type:'richhtml',text:richSource.innerHTML}]:[{type:'richtext',text:JSON.stringify(quill.getContents())}];
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
  if(richMode&&typeof saved.richHTML==='string'){richSource.innerHTML=saved.richHTML;}
  else if(saved.delta?.ops)quill.setContents(saved.delta);
  markChanged();
 }
 form.addEventListener('submit',e=>{
  document.getElementById('blocks-data').value=JSON.stringify(serialize());
  if(!e.defaultPrevented)isSubmitting=true;
  try{localStorage.setItem('comesy-pending-save',backupKey)}catch(error){}
 });
 setInterval(()=>{if(!dirty)return;try{localStorage.setItem(backupKey,JSON.stringify({title:document.getElementById('title').value,lead:document.getElementById('lead').value,delta:richMode?null:quill.getContents(),richHTML:richMode?richSource.innerHTML:null,time:Date.now()}));status.textContent='Helyi piszkozat mentve';dirty=false;}catch(e){}},15000);
 document.getElementById('preview-button').addEventListener('click',()=>{
  // Separate tab, only on demand. Rich HTML stays inside an isolated sandbox
  // with no script execution and no access to the authenticated CMS origin.
  const titleText=document.getElementById('title').value;
  const leadText=document.getElementById('lead').value;
  const w=window.open('','_blank');
  if(!w)return;
  if(richMode){
   const d=w.document;d.title=(titleText||'Cikkelőnézet')+' · comesy';
   d.documentElement.lang='hu';
   const headStyle=d.createElement('style');
   headStyle.textContent='body{margin:0;background:#f5f7fb;color:#1d2a3c;font-family:system-ui}main{max-width:900px;margin:24px auto;padding:26px;background:white;border:1px solid #e2e8f0;border-radius:12px}h1{font-size:clamp(22px,4vw,34px)}.lead{font-size:18px;color:#5d6777;line-height:1.6}iframe{width:100%;height:75vh;border:0}';
   d.head.append(headStyle);
   const main=d.createElement('main'),h=d.createElement('h1'),lead=d.createElement('p');
   h.textContent=titleText;lead.textContent=leadText;lead.className='lead';main.append(h,lead);
   const frame=d.createElement('iframe');frame.title='A cikk biztonságos előnézete';
   frame.setAttribute('sandbox','');frame.setAttribute('referrerpolicy','no-referrer');
   frame.srcdoc='<meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; img-src https: http: data:; style-src &#39;unsafe-inline&#39;; media-src https: http:; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://open.spotify.com https://embed.spotify.com https://www.facebook.com https://www.instagram.com https://w.soundcloud.com https://www.tiktok.com"><style>body{font:16px/1.7 system-ui;margin:10px;color:#1b2636}img,video{max-width:100%;height:auto}figure{margin:20px 0}figcaption{font-size:13px;color:#637083}table{border-collapse:collapse;display:block;overflow:auto;max-width:100%}td,th{border:1px solid #d3dce8;padding:9px}iframe{border:1px solid #d3dce8;max-width:100%;width:100%;min-height:260px}blockquote{border-left:3px solid #5067a2;padding-left:18px}mark{background:#fff1af}</style><style>'+sourceStyle.css+'</style><main class="cms-source-article">'+richSource.innerHTML+'</main>';
   main.append(frame);d.body.append(main);
   sourceChrome(d);
   w.opener=null;
   return;
  }
  const d=w.document;d.title='comesy cikkelőnézet';
  const style=d.createElement('style');style.textContent='body{max-width:760px;margin:40px auto;padding:20px;font:18px/1.7 system-ui}img{max-width:100%}h1{line-height:1.2}';d.head.append(style);
  if(sourceStyle.css){const sourceSheet=d.createElement('style');sourceSheet.textContent=sourceStyle.css;d.head.append(sourceSheet);d.body.className='cms-source-article';}
  const title=d.createElement('h1');title.textContent=document.getElementById('title').value;d.body.append(title);
  const lead=d.createElement('p');lead.textContent=document.getElementById('lead').value;d.body.append(lead);
  const body=d.createElement('div');body.innerHTML=quill.getSemanticHTML();body.querySelectorAll('script,iframe,object,embed,form').forEach(el=>el.remove());body.querySelectorAll('*').forEach(el=>{for(const a of [...el.attributes])if(a.name.startsWith('on'))el.removeAttribute(a.name)});d.body.append(body);
  if(sourceStyle.source){const wrap=d.createElement('article');wrap.className='cms-source-article';for(const node of [title,lead,body])wrap.append(node);d.body.append(wrap);d.body.classList.remove('cms-source-article');}
  sourceChrome(d);
  w.opener=null;
 });
});