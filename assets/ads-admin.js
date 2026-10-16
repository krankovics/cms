(()=>{
const format=document.getElementById('creative-format');
if(!format)return;
const groups=[...document.querySelectorAll('[data-ad-format]')];
function update(){
 const code=format.value==='code';
 for(const group of groups){
  const active=group.dataset.adFormat===(code?'code':'image');
  group.hidden=!active;
  for(const input of group.querySelectorAll('input,textarea'))input.disabled=!active;
 }
 const image=document.querySelector('[name=image_url]');
 const dest=document.querySelector('[name=destination_url]');
 const alt=document.querySelector('[name=alt_text]');
 if(image)image.required=!code;if(dest)dest.required=!code;if(alt)alt.required=!code;
 const snippet=document.querySelector('[name=code_html]');
 if(snippet)snippet.required=code;
}
format.addEventListener('change',update);update();
})();