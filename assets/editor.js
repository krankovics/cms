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
 document.getElementById('insert-recommendation').addEventListener('click',()=>{
  const kind=prompt('Ajánló típusa: cikk vagy könyv?','cikk');
  if(!['cikk','könyv'].includes(kind))return;
  const url=prompt('Az ajánlott tartalom URL-je (https://)');
  if(!url)return;
  try{const u=new URL(url);if(!['https:','http:'].includes(u.protocol))throw Error();const index=quill.getSelection(true)?.index??quill.getLength();quill.insertText(index,'\n['+(kind==='könyv'?'Könyvajánló':'Cikkajánló')+'] '+url+'\n','user')}catch(e){alert('Érvénytelen URL')}
 });
 const serialize=()=>[{type:'richtext',text:JSON.stringify(quill.getContents())}];
 const backupKey='comesy-wysiwyg-'+new URLSearchParams(location.search).get('id');
 const saved=(()=>{try{return JSON.parse(localStorage.getItem(backupKey)||'null')}catch(e){return null}})();
 if(saved&&saved.time&&saved.time>Date.now()-7*86400000&&confirm('Találtam egy helyi biztonsági másolatot. Visszaállítod?')){
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