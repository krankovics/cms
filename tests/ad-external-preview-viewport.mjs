import {readFileSync} from 'node:fs';
import {createServer} from 'node:http';
import {chromium} from 'playwright';
import assert from 'node:assert/strict';

const client=readFileSync(new URL('../assets/ad-external-preview.js',import.meta.url),'utf8');
function documentHTML(mode){
 return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
 '<style>body{margin:0}.pub-adocean-test-frame{width:100%;height:350px}</style></head><body>'+
 '<div data-ao-preview-control data-ao-preview-device="'+mode+'">'+
 '<input type="checkbox" data-ao-preview-consent>'+
 '<button type="button" data-ao-preview-load data-preview-url="/ad-external-preview.php" disabled>Megjelenítés</button>'+
 '<div data-ao-preview-status></div><div data-ao-preview-frame></div></div>'+
 '<script>'+client+'</script></body></html>';
}
const previewHTML='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><script>'+
 'const channel=new URLSearchParams(location.search).get("channel");'+
 'window.parent.postMessage({type:"comesy-adocean-preview",channel,phase:"layout",details:{height:320,original_width:970,scale:100}},"*");'+
 '</script></body></html>';
const server=createServer((req,res)=>{
 res.setHeader('Content-Type','text/html; charset=utf-8');
 res.end(req.url.startsWith('/ad-external-preview.php')?previewHTML:documentHTML(req.url.includes('device=mobile')?'mobile':'desktop'));
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const browser=await chromium.launch({headless:true});
try{
 for(const mode of ['desktop','mobile']){
  const page=await browser.newPage({viewport:{width:390,height:844}});
  await page.goto('http://127.0.0.1:'+server.address().port+'/?device='+mode);
  await page.locator('[data-ao-preview-consent]').check();
  await page.locator('[data-ao-preview-load]').click();
  const frame=page.frameLocator('iframe.pub-adocean-test-frame');
  await frame.locator('body').waitFor();
  const childWidth=await frame.locator('body').evaluate(()=>window.innerWidth);
  const iframe=page.locator('iframe.pub-adocean-test-frame');
  const transform=await iframe.evaluate(e=>getComputedStyle(e).transform);
  const container=page.locator('[data-ao-preview-control]');
  if(mode==='desktop'){
   assert(childWidth>=960,'Desktop AdOcean MASTER must see a genuine desktop viewport.');
   assert.notEqual(transform,'none','Wide desktop viewport must scale in mobile presentation.');
   assert.equal(await container.getAttribute('data-preview-desktop'),'1');
  }else{
   assert(childWidth<500,'Mobile AdOcean MASTER must preserve the small phone viewport.');
   assert.equal(transform,'none','Mobile ad must not use desktop-emulation transform.');
  }
  await page.close();
 }
 console.log('AdOcean device-aware iframe browsing context on phones: OK');
}finally{
 await browser.close();
 server.close();
}
