import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const css=readFileSync('assets/admin-v2.css','utf8');
const root=css.match(/:root\s*\{([^}]+)\}/)?.[1]||'';
const dark=css.match(/html\[data-theme="dark"\]\s*\{([^}]+)\}/)?.[1]||'';
function tokens(block){
 return Object.fromEntries([...block.matchAll(/(--ui-[a-z-]+):\s*(#[0-9a-f]{6})/gi)].map(([,k,v])=>[k,v]));
}
const rgb=h=>[1,3,5].map(i=>parseInt(h.slice(i,i+2),16)/255);
function L(h){return rgb(h).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);}
function contrast(a,b){const [l1,l2]=[L(a),L(b)].sort((x,y)=>y-x);return (l1+.05)/(l2+.05);}
for(const [mode,style] of [['light',tokens(root)],['dark',tokens(dark)]]){
 for(const textKey of ['--ui-text','--ui-text-secondary','--ui-primary','--ui-warning']){
  for(const surface of ['--ui-surface','--ui-surface-subtle']){
   const ratio=contrast(style[textKey],style[surface]);
   assert(ratio>=4.5,mode+': '+textKey+' on '+surface+' contrast '+ratio.toFixed(2)+' fails WCAG AA');
  }
 }
 assert(contrast(style['--ui-primary'],style['--ui-primary-on'])>=4.5,mode+': primary button contrast');
}
console.log('PASS: light/dark foreground token pairs meet WCAG AA contrast ratio >= 4.5.');
