(()=>{
'use strict';
const form=document.getElementById('cs-site-menu-form');
if(!form)return;
const hidden=form.querySelector('textarea[name="menu_json"]');
const rows=document.getElementById('cs-site-menu-rows');
const add=document.getElementById('cs-site-menu-add');
const readonly=form.dataset.readOnly==='1';
let items=[];
try{const parsed=JSON.parse(hidden.value);if(Array.isArray(parsed))items=parsed.map(m=>({label:String(m.label||''),url:String(m.url||'/'),active:Number(m.active)?1:0}));}catch(e){console.error('A menüadatok nem olvashatók.');}
let dirty=false;
const field=(labelText,value,maxLength,change)=>{
 const label=document.createElement('label');
 const caption=document.createElement('span');caption.textContent=labelText;
 const input=document.createElement('input');input.type='text';input.value=value;input.required=true;input.maxLength=maxLength;input.disabled=readonly;
 input.addEventListener('input',()=>{change(input.value);dirty=true;});
 label.append(caption,input);return label;
};
function render(){
 rows.replaceChildren();
 items.forEach((item,index)=>{
  const row=document.createElement('div');row.className='cs-site-menu-row';row.dataset.index=String(index);
  row.append(field('Menüpont neve',item.label,120,v=>item.label=v));
  row.append(field('Cél URL',item.url,1000,v=>item.url=v));
  const toggle=document.createElement('label');toggle.className='cs-site-menu-active';
  const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.checked=!!item.active;checkbox.disabled=readonly;
  checkbox.addEventListener('change',()=>{item.active=checkbox.checked?1:0;dirty=true;});
  toggle.append(checkbox,' Aktív');row.append(toggle);
  const ctrls=document.createElement('div');ctrls.className='cs-site-menu-controls';
  for(const [label,symbol,offset] of [['Feljebb','↑',-1],['Lejjebb','↓',1]]){
   const button=document.createElement('button');button.type='button';button.textContent=symbol;button.title=label;button.setAttribute('aria-label',item.label+': '+label);
   button.disabled=readonly||index+offset<0||index+offset>=items.length;
   button.addEventListener('click',()=>{[items[index],items[index+offset]]=[items[index+offset],items[index]];dirty=true;render();});
   ctrls.append(button);
  }
  const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.title='Menüpont eltávolítása';remove.setAttribute('aria-label',item.label+': eltávolítás');remove.disabled=readonly;
  remove.addEventListener('click',()=>{if(confirm('Eltávolítod ezt a menüpontot?')){items.splice(index,1);dirty=true;render();}});
  ctrls.append(remove);row.append(ctrls);rows.append(row);
 });
 if(!items.length){const p=document.createElement('p');p.className='cs-site-empty';p.textContent='Még nincs menüpont. Adj hozzá egyet a lenti gombbal.';rows.append(p);}
}
render();
add?.addEventListener('click',()=>{
 if(items.length>=40){alert('Legfeljebb 40 menüpont lehet.');return;}
 items.push({label:'',url:'/',active:1});dirty=true;render();
 const last=rows.querySelector('.cs-site-menu-row:last-child input[type=text]');last?.focus();
});
form.addEventListener('submit',event=>{
 if(readonly){event.preventDefault();return;}
 if(items.some(x=>!x.label.trim()||!x.url.trim())){event.preventDefault();alert('Minden menüpontnál add meg a nevet és a cél URL-t.');return;}
 hidden.value=JSON.stringify(items);dirty=false;
});
window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
})();