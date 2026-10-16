(()=>{
'use strict';
const form=document.querySelector('[data-builder-form]');
if(!form)return;
const readOnly=form.dataset.readonly==='1';
const layoutInput=form.querySelector('[name="layout_json"]');
let items;try{items=JSON.parse(layoutInput.value)}catch(e){items=[];}
if(!Array.isArray(items))items=[];
const list=document.getElementById('builder-block-list');
let dirty=false;
const texts={hero:'Hero',latest:'Cikklista',text:'Szöveg',image:'Kép',ad:'Hirdetési hely',article:'Cikktartalom',page_body:'Statikus oldaltartalom',reader_form:'Olvasói bejelentkezés'};
const esc=(s)=>String(s??'');
const field=(name,value,kind='text')=>{
 const label=document.createElement('label');label.className='ui-builder-field';
 const caption=document.createElement('span');
 caption.textContent={title:'Cím',text:'Szöveg',columns:'Oszlopok',limit:'Megjelenített cikkek',offset:'Első kihagyott cikk (lapozás)',image_url:'Kép URL',zone:'Zónakód'}[name]||name;
 label.append(caption);
 const input=kind==='textarea'?document.createElement('textarea'):document.createElement('input');
 if(kind!=='textarea')input.type=kind;
 input.value=esc(value);
 if(kind==='number'){input.min=name==='offset'?'0':'1';input.max=name==='columns'?'4':name==='offset'?'150':'30';}
 input.maxLength=name==='text'?3000:name==='title'?190:1000;
 input.disabled=readOnly;
 input.addEventListener('input',()=>{
  if(name==='columns'||name==='limit'||name==='offset')items[Number(label.closest('[data-block-index]').dataset.blockIndex)][name]=Math.max(name==='offset'?0:1,Number(input.value)||0);
  else items[Number(label.closest('[data-block-index]').dataset.blockIndex)][name]=input.value;
  dirty=true;
 });
 label.append(input);return label;
};
function render(){
 list.replaceChildren();
 items.forEach((item,index)=>{
  const card=document.createElement('article');card.className='ui-builder-block';card.dataset.blockIndex=String(index);card.draggable=!readOnly;
  const head=document.createElement('header');const title=document.createElement('strong');
  title.textContent=(index+1)+'. '+(texts[item.type]||item.type);
  const actions=document.createElement('div');actions.className='ui-builder-block-actions';
  for(const [label,delta] of [['↑',-1],['↓',1]]){
   const button=document.createElement('button');button.type='button';button.textContent=label;button.disabled=readOnly||index+delta<0||index+delta>=items.length;
   button.setAttribute('aria-label',delta===-1?'Komponens feljebb':'Komponens lejjebb');
   button.addEventListener('click',()=>{[items[index],items[index+delta]]=[items[index+delta],items[index]];dirty=true;render();});
   actions.append(button);
  }
  const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.className='ui-builder-remove';remove.disabled=readOnly||items.length===1;remove.title='Komponens eltávolítása';
  remove.addEventListener('click',()=>{if(items.length>1&&confirm('Eltávolítod a komponenst?')){items.splice(index,1);dirty=true;render();}});
  actions.append(remove);head.append(title,actions);card.append(head);
  const body=document.createElement('div');body.className='ui-builder-block-fields';
  if(item.type!=='article'){body.append(field('title',item.title||''));}
  if(['hero','text'].includes(item.type))body.append(field('text',item.text||'','textarea'));
  if(item.type==='image')body.append(field('image_url',item.image_url||'','url'));
  if(item.type==='ad')body.append(field('zone',item.zone||''));
  if(['latest'].includes(item.type))body.append(field('limit',item.limit||10,'number'));
  if(['latest'].includes(item.type))body.append(field('columns',item.columns||2,'number'));
  if(['latest'].includes(item.type))body.append(field('offset',item.offset||0,'number'));
  card.append(body);
  card.addEventListener('dragstart',event=>{event.dataTransfer.setData('text/plain',String(index));event.dataTransfer.effectAllowed='move';});
  card.addEventListener('dragover',event=>{if(!readOnly){event.preventDefault();card.classList.add('is-drag-target');}});
  card.addEventListener('dragleave',()=>card.classList.remove('is-drag-target'));
  card.addEventListener('drop',event=>{
   event.preventDefault();card.classList.remove('is-drag-target');
   const from=Number(event.dataTransfer.getData('text/plain'));
   if(!Number.isInteger(from)||from===index||from<0||from>=items.length)return;
   const moved=items.splice(from,1)[0];items.splice(index,0,moved);dirty=true;render();
  });
  list.append(card);
 });
}
render();
document.querySelector('[data-builder-add]')?.addEventListener('click',()=>{
 if(items.length>=30){alert('Legfeljebb 30 komponens lehet.');return;}
 const type=document.getElementById('builder-new-type').value;
 if(!texts[type])return;
 const block={type,title:'',text:'',columns:type==='latest'?3:1,limit:10,zone:'',image_url:'',offset:0};
 items.push(block);dirty=true;render();list.lastElementChild?.scrollIntoView({behavior:'smooth',block:'nearest'});
});
form.addEventListener('submit',event=>{
 if(items.length<1){event.preventDefault();alert('Legalább egy komponens szükséges.');return;}
 const action=event.submitter?.value;
 if(action==='publish'&&!confirm('Publikálod a sablon új verzióját? Ez a publikus oldalon is megjelenik.')){event.preventDefault();return;}
 layoutInput.value=JSON.stringify(items);dirty=false;
});
const menuForm=document.getElementById('builder-menu-form');
const menuInput=menuForm?.querySelector('[name=menu_json]');
let links=[];try{links=JSON.parse(menuInput?.value||'[]')}catch(e){}
if(!Array.isArray(links))links=[];
const menuList=document.getElementById('builder-menu-rows');
function renderMenu(){
 if(!menuList)return;
 menuList.replaceChildren();
 links.forEach((item,index)=>{
  const row=document.createElement('div');row.className='ui-builder-menu-row';
  for(const [name,label] of [['label','Menüpont'],['url','Cél URL']]){
   const wrap=document.createElement('label');wrap.className='ui-builder-field';const caption=document.createElement('span');caption.textContent=label;
   const input=document.createElement('input');input.value=item[name]||'';input.required=true;input.disabled=readOnly;input.maxLength=name==='label'?120:1000;
   input.addEventListener('input',()=>{links[index][name]=input.value;dirty=true;});
   wrap.append(caption,input);row.append(wrap);
  }
  const on=document.createElement('label');on.className='ui-builder-menu-toggle';
  const box=document.createElement('input');box.type='checkbox';box.checked=!!Number(item.active);box.disabled=readOnly;
  box.addEventListener('change',()=>{links[index].active=box.checked?1:0;dirty=true;});
  on.append(box,' Aktív');row.append(on);
  const buttons=document.createElement('div');buttons.className='ui-builder-block-actions';
  for(const [title,offset] of [['Feljebb',-1],['Lejjebb',1]]){
   const button=document.createElement('button');button.type='button';button.textContent=offset===-1?'↑':'↓';
   button.disabled=readOnly||index+offset<0||index+offset>=links.length;button.title=title;
   button.addEventListener('click',()=>{[links[index],links[index+offset]]=[links[index+offset],links[index]];dirty=true;renderMenu();});
   buttons.append(button);
  }
  const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.title='Menüpont törlése';remove.disabled=readOnly;
  remove.addEventListener('click',()=>{links.splice(index,1);dirty=true;renderMenu();});
  buttons.append(remove);row.append(buttons);menuList.append(row);
 });
}
renderMenu();
document.querySelector('[data-menu-add]')?.addEventListener('click',()=>{
 if(links.length>=40){alert('Legfeljebb 40 menüpont lehetséges.');return;}
 links.push({label:'',url:'/',active:1});dirty=true;renderMenu();
});
menuForm?.addEventListener('submit',()=>{menuInput.value=JSON.stringify(links);dirty=false;});
window.addEventListener('beforeunload',event=>{
 if(dirty){event.preventDefault();event.returnValue='';}
});
const previewFrame=document.getElementById('builder-preview-frame');
document.querySelectorAll('[data-builder-width]').forEach(button=>{
 button.addEventListener('click',()=>{
  document.querySelectorAll('[data-builder-width]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));
  previewFrame.style.width=button.dataset.builderWidth;
 });
});
document.querySelector('[data-builder-refresh]')?.addEventListener('click',()=>{previewFrame.src=previewFrame.src;});
})();