(()=>{
'use strict';
const form=document.querySelector('[data-builder-form]');
if(!form)return;
const readOnly=form.dataset.readonly==='1';
const layoutInput=form.querySelector('[name="layout_json"]');
let data;try{data=JSON.parse(layoutInput.value);}catch{data=[];}
if(!Array.isArray(data))data=[];
const list=document.getElementById('builder-block-list');
const types={hero:'Hero',latest:'Cikklista',text:'Szöveg',image:'Kép',ad:'Hirdetési hely',article:'Cikktartalom'};
const blockTemplate=type=>({type,title:'',text:'',columns:type==='latest'?3:1,limit:10,zone:'',image_url:''});
const boxTemplate=type=>({type:'box',span:12,blocks:type?[blockTemplate(type)]:[]});
const rowTemplate=type=>({type:'row',columns:[boxTemplate(type)]});
// Existing templates are promoted in memory only; no legacy content is
// changed on disk until an editor explicitly saves the new layout.
let rows=data.map(item=>item.type==='row'?item:rowTemplate(item.type)).map((row,i)=>{
 if(data[i]?.type==='row')return row;
 row.columns[0].blocks[0]={...blockTemplate(itemType(data[i])),...data[i]};
 return row;
});
function itemType(item){return types[item?.type]?item.type:'text';}
if(!rows.length)rows=[rowTemplate('hero')];
let dirty=false;
const totalBlocks=()=>rows.reduce((acc,row)=>acc+row.columns.reduce((sum,box)=>sum+box.blocks.length,0),0);
const markDirty=()=>{dirty=true;};
const btn=(text,label,onClick,disabled=false,extra='')=>{
 const el=document.createElement('button');el.type='button';el.textContent=text;el.title=label;
 el.setAttribute('aria-label',label);el.disabled=readOnly||disabled;
 if(extra)el.className=extra;
 el.addEventListener('click',onClick);return el;
};
const move=(arr,i,j)=>{if(j<0||j>=arr.length||i===j)return;const node=arr.splice(i,1)[0];arr.splice(j,0,node);markDirty();render();};
const getBox=(r,c)=>rows[r]?.columns[c];
const getBlocks=(r,c)=>getBox(r,c)?.blocks;
const contentField=(name,value,type,ri,ci,bi)=>{
 const label=document.createElement('label');label.className='ui-builder-field';
 const span=document.createElement('span');
 span.textContent={title:'Cím',text:'Szöveg',columns:'Oszlopok',limit:'Megjelenített cikkek',image_url:'Kép URL',zone:'Zónakód'}[name]||name;
 label.append(span);
 const el=type==='textarea'?document.createElement('textarea'):document.createElement('input');
 if(type!=='textarea')el.type=type;el.value=String(value??'');
 if(type==='number'){el.min='1';el.max=name==='columns'?'4':'30';}
 el.maxLength=name==='text'?3000:name==='title'?190:1000;el.disabled=readOnly;
 el.addEventListener('input',()=>{
  const target=getBlocks(ri,ci)?.[bi];if(!target)return;
  target[name]=type==='number'?Math.max(1,Math.min(name==='columns'?4:30,Number(el.value)||1)):el.value;
  markDirty();
 });
 label.append(el);return label;
};
function enableDrag(handle,sourceType,position){
 if(readOnly)return;
 handle.draggable=true;handle.classList.add('ui-builder-drag');
 handle.setAttribute('aria-label','Húzd a '+sourceType+' elemet az új helyére');
 handle.addEventListener('dragstart',e=>{
  e.stopPropagation();e.dataTransfer.setData('application/x-comesy-builder',JSON.stringify({type:sourceType,position}));
  e.dataTransfer.effectAllowed='move';
 });
}
function acceptDrop(target,typesAllowed,apply){
 target.addEventListener('dragover',e=>{
  if(readOnly)return;
  const offered=[...e.dataTransfer.types];
  if(!offered.includes('application/x-comesy-builder'))return;
  e.preventDefault();e.stopPropagation();target.classList.add('is-drag-target');
  e.dataTransfer.dropEffect='move';
 });
 target.addEventListener('dragleave',e=>{if(!target.contains(e.relatedTarget))target.classList.remove('is-drag-target');});
 target.addEventListener('drop',e=>{
  e.preventDefault();e.stopPropagation();target.classList.remove('is-drag-target');
  if(readOnly)return;
  let payload;try{payload=JSON.parse(e.dataTransfer.getData('application/x-comesy-builder'));}catch{return;}
  if(!typesAllowed.includes(payload?.type)||!Array.isArray(payload.position))return;
  apply(payload.position);
 });
}
function render(){
 list.replaceChildren();
 rows.forEach((row,ri)=>{
  const section=document.createElement('section');
  section.className='ui-builder-row';section.dataset.rowIndex=String(ri);
  const header=document.createElement('header');header.className='ui-builder-row-header';
  const left=document.createElement('div');left.className='ui-builder-struct-title';
  const drag=document.createElement('span');drag.textContent='⠿';drag.className='ui-builder-grip';drag.tabIndex=readOnly?-1:0;
  enableDrag(drag,'row',[ri]);
  const title=document.createElement('strong');title.textContent='Sor '+(ri+1);
  left.append(drag,title);
  const tools=document.createElement('div');tools.className='ui-builder-block-actions';
  tools.append(
   btn('↑','Sor feljebb',()=>move(rows,ri,ri-1),ri===0),
   btn('↓','Sor lejjebb',()=>move(rows,ri,ri+1),ri===rows.length-1),
   btn('+ Doboz','Doboz hozzáadása',()=>addBox(ri),row.columns.length>=4,'ui-builder-box-add'),
   btn('×','Sor törlése',()=>{
    if(rows.length<=1){alert('Legalább egy sor szükséges.');return;}
    if(confirm('Törlöd a sort és a benne lévő összes blokkot?')){rows.splice(ri,1);markDirty();render();}
   },rows.length===1,'ui-builder-remove')
  );
  header.append(left,tools);section.append(header);
  const grid=document.createElement('div');grid.className='ui-builder-box-grid';
  row.columns.forEach((box,ci)=>{
   const card=document.createElement('div');card.className='ui-builder-box';
   card.style.setProperty('--builder-span',String(box.span||12));
   const subhead=document.createElement('div');subhead.className='ui-builder-box-header';
   const boxTitle=document.createElement('div');boxTitle.className='ui-builder-struct-title';
   const handle=document.createElement('span');handle.className='ui-builder-grip';handle.textContent='⠿';
   enableDrag(handle,'box',[ri,ci]);
   const caption=document.createElement('strong');caption.textContent='Doboz '+(ci+1);boxTitle.append(handle,caption);
   const boxActions=document.createElement('div');boxActions.className='ui-builder-block-actions';
   const widthLabel=document.createElement('label');widthLabel.className='ui-builder-box-width';
   widthLabel.append(document.createTextNode('Szélesség '));
   const select=document.createElement('select');select.setAttribute('aria-label','Doboz szélessége');
   for(const v of [2,3,4,6,8,9,12]){
    const option=document.createElement('option');option.value=String(v);option.textContent=v+'/12';select.append(option);
   }
   if(![2,3,4,6,8,9,12].includes(box.span)){
    const option=document.createElement('option');option.value=String(box.span);option.textContent=box.span+'/12';select.append(option);
   }
   select.value=String(box.span||12);select.disabled=readOnly;
   select.addEventListener('change',()=>{
    const chosen=Number(select.value),used=row.columns.reduce((sum,other,index)=>sum+(index===ci?chosen:Number(other.span)||12),0);
    if(used>12){alert('A sor dobozainak összes szélessége legfeljebb 12/12 lehet.');select.value=String(box.span);return;}
    box.span=chosen;markDirty();render();
   });
   widthLabel.append(select);boxActions.append(widthLabel);
   boxActions.append(btn('↑','Doboz balra',()=>move(row.columns,ci,ci-1),ci===0),btn('↓','Doboz jobbra',()=>move(row.columns,ci,ci+1),ci===row.columns.length-1));
   boxActions.append(btn('×','Doboz törlése',()=>{
    if(row.columns.length<=1){alert('Legalább egy doboz kell a sorba.');return;}
    if(confirm('Törlöd a dobozt a benne lévő blokkokkal?')){row.columns.splice(ci,1);balance(row);markDirty();render();}
   },row.columns.length===1,'ui-builder-remove'));
   subhead.append(boxTitle,boxActions);card.append(subhead);
   const blocks=document.createElement('div');blocks.className='ui-builder-box-blocks';
   box.blocks.forEach((block,bi)=>{
    const article=document.createElement('article');article.className='ui-builder-block';
    article.dataset.blockIndex=String(bi);article.dataset.rowIndex=String(ri);article.dataset.boxIndex=String(ci);
    const head=document.createElement('header');const bleft=document.createElement('div');bleft.className='ui-builder-struct-title';
    const grip=document.createElement('span');grip.className='ui-builder-grip';grip.textContent='⠿';enableDrag(grip,'block',[ri,ci,bi]);
    const name=document.createElement('strong');name.textContent=(bi+1)+'. '+(types[block.type]||block.type);
    bleft.append(grip,name);
    const actions=document.createElement('div');actions.className='ui-builder-block-actions';
    actions.append(btn('↑','Blokk feljebb',()=>move(box.blocks,bi,bi-1),bi===0),btn('↓','Blokk lejjebb',()=>move(box.blocks,bi,bi+1),bi===box.blocks.length-1));
    actions.append(btn('×','Blokk törlése',()=>{
     if(totalBlocks()<=1){alert('Legalább egy blokk szükséges.');return;}
     if(confirm('Eltávolítod a blokkot?')){box.blocks.splice(bi,1);markDirty();render();}
    },totalBlocks()===1,'ui-builder-remove'));
    head.append(bleft,actions);article.append(head);
    const body=document.createElement('div');body.className='ui-builder-block-fields';
    if(block.type!=='article')body.append(contentField('title',block.title||'','text',ri,ci,bi));
    if(['hero','text'].includes(block.type))body.append(contentField('text',block.text||'','textarea',ri,ci,bi));
    if(block.type==='image')body.append(contentField('image_url',block.image_url||'','url',ri,ci,bi));
    if(block.type==='ad')body.append(contentField('zone',block.zone||'','text',ri,ci,bi));
    if(block.type==='latest'){
     body.append(contentField('limit',block.limit||10,'number',ri,ci,bi));
     body.append(contentField('columns',block.columns||2,'number',ri,ci,bi));
    }
    article.append(body);
    acceptDrop(article,['block'],source=>moveBlock(source,[ri,ci,bi]));
    blocks.append(article);
   });
   if(!box.blocks.length){
    const empty=document.createElement('p');empty.className='ui-builder-empty-box';empty.textContent='Üres doboz. Húzz ide blokkot, vagy adj hozzá újat.';
    blocks.append(empty);
   }
   acceptDrop(blocks,['block'],source=>moveBlock(source,[ri,ci,box.blocks.length]));
   card.append(blocks);
   const add=document.createElement('div');add.className='ui-builder-box-footer';
   const type=document.createElement('select');type.className='ui-builder-box-type';type.disabled=readOnly;type.setAttribute('aria-label','Új blokk típusa');
   for(const [key,value] of Object.entries(types)){
    if(key==='article'&&form.dataset.template!=='article')continue;
    const option=document.createElement('option');option.value=key;option.textContent=value;type.append(option);
   }
   add.append(type,btn('+ Blokk','Blokk hozzáadása a dobozhoz',()=>{
    if(totalBlocks()>=60||box.blocks.length>=15){alert('Egy sablonban legfeljebb 60, egy dobozban legfeljebb 15 blokk lehet.');return;}
    box.blocks.push(blockTemplate(type.value));markDirty();render();
   },box.blocks.length>=15||totalBlocks()>=60,'ui-builder-block-add'));
   card.append(add);
   acceptDrop(card,['box'],source=>moveBox(source,[ri,ci]));
   grid.append(card);
  });
  section.append(grid);
  acceptDrop(section,['row'],source=>move(rows,source[0],ri));
  list.append(section);
 });
}
function balance(row){
 const count=row.columns.length;const base=Math.floor(12/count);let rem=12-base*count;
 row.columns.forEach(box=>box.span=base+(rem-->0?1:0));
}
function addBox(ri){
 const row=rows[ri];if(!row||row.columns.length>=4)return;
 row.columns.push(boxTemplate());balance(row);markDirty();render();
}
function moveBox(source,target){
 const [a,b]=source,[r,c]=target;const from=rows[a]?.columns,into=rows[r]?.columns;
 if(!from||!into||!from[b]||(a===r&&b===c))return;
 if(a!==r&&into.length>=4){alert('Egy sor legfeljebb négy dobozt tartalmazhat.');return;}
 const node=from.splice(b,1)[0];into.splice(a===r&&b<c?c-1:c,0,node);
 if(from.length===0)from.push(boxTemplate());
 balance(rows[a]);if(a!==r)balance(rows[r]);markDirty();render();
}
function moveBlock(source,target){
 const [a,b,k]=source,[r,c,i]=target;
 const from=getBlocks(a,b),into=getBlocks(r,c);
 if(!from||!into||!from[k])return;
 if(from===into&&k===i)return;
 if(from!==into&&into.length>=15){alert('Egy dobozban legfeljebb 15 blokk lehet.');return;}
 const item=from.splice(k,1)[0];
 into.splice(from===into&&k<i?i-1:i,0,item);markDirty();render();
}
render();
document.querySelector('[data-builder-add]')?.addEventListener('click',()=>{
 const type=document.getElementById('builder-new-type')?.value;
 if(!types[type])return;
 let box=rows[rows.length-1]?.columns[0];
 if(totalBlocks()>=60){alert('Legfeljebb 60 blokk helyezhető el.');return;}
 if(!box||box.blocks.length>=15){if(rows.length>=30){alert('Legfeljebb 30 sor lehet.');return;}rows.push(rowTemplate());box=rows[rows.length-1].columns[0];}
 box.blocks.push(blockTemplate(type));markDirty();render();
 list.lastElementChild?.scrollIntoView({behavior:'smooth',block:'nearest'});
});
document.querySelector('[data-builder-add-row]')?.addEventListener('click',()=>{
 if(rows.length>=30){alert('Legfeljebb 30 sor lehet.');return;}
 rows.push(rowTemplate());markDirty();render();
 list.lastElementChild?.scrollIntoView({behavior:'smooth',block:'nearest'});
});
form.addEventListener('submit',event=>{
 if(totalBlocks()<1){event.preventDefault();alert('Legalább egy tartalmi blokk szükséges.');return;}
 if(event.submitter?.value==='publish'&&!confirm('Publikálod a sablon új verzióját? Ez a publikus oldalon is megjelenik.')){event.preventDefault();return;}
 layoutInput.value=JSON.stringify(rows);dirty=false;
});
// Dedicated desktop workspace mode using the browser Fullscreen API when
// available, with a CSS workspace fallback for browser restrictions.
const workspace=document.querySelector('.ui-builder-page');
const fullButton=document.querySelector('[data-builder-fullscreen]');
const setFull=on=>{
 document.body.classList.toggle('builder-fullscreen',on);
 fullButton?.setAttribute('aria-pressed',String(on));
 if(fullButton)fullButton.textContent=on?'Teljes képernyő bezárása':'Teljes képernyő';
};
fullButton?.addEventListener('click',async()=>{
 if(!document.body.classList.contains('builder-fullscreen')){
  setFull(true);
  try{await workspace.requestFullscreen?.();}catch{} // CSS fallback.
 }else{
  setFull(false);
  if(document.fullscreenElement)try{await document.exitFullscreen();}catch{}
 }
});
document.addEventListener('fullscreenchange',()=>{if(!document.fullscreenElement)setFull(false);});
document.addEventListener('keydown',e=>{
 if(e.key==='Escape'&&document.body.classList.contains('builder-fullscreen')&&!document.fullscreenElement)setFull(false);
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