import {chromium} from 'playwright-core';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']});
const base='http://127.0.0.1:8765';
const routes=['/','/articles.php','/editor.php','/taxonomy.php','/taxonomy.php?type=tag','/media.php','/sites.php','/users.php','/site-permissions.php','/profile.php','/settings.php','/migrate.php','/ads.php','/imports.php','/builder.php','/audit.php','/import-report.php?site_id=1&job_id=1'];
try{
 const page=await browser.newPage({viewport:{width:390,height:844}});
 await page.goto(base+'/login.php');
 await page.locator('[name=email]').fill('platform_admin@testing.invalid');
 await page.locator('[name=password]').fill('StrongTestingPwd!2026');
 await page.locator('button[type=submit]').click();
 await page.waitForURL(base+'/');
 const slot=await page.request.get(base+'/ad-slot.php?site_id=1&zone=article-top');
 assert.equal(slot.status(),200,'Active native advertisement should render');
 assert((await slot.text()).includes('Hirdetés'),'Ad disclosure should be visible');
 const adClick=await page.request.get(base+'/ad-click.php?id=1',{maxRedirects:0});
 assert.equal(adClick.status(),302,'Ad click redirect');
 assert.equal(adClick.headers()['location'],'https://example.test/go','Safe campaign destination');
 for(const width of [320,390,768,1366,1920]){
  await page.setViewportSize({width,height:850});
  for(const route of routes){
   const response=await page.goto(base+route,{waitUntil:'domcontentloaded'});
   assert.equal(response.status(),200,route+' status');
   assert.equal(await page.locator('main h1').count()>0,true,route+' title');
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
   assert(overflow<=2,route+' horizontal overflow '+overflow+'px at '+width);
   // Shared native dropdown presentation must work on every module.
   const selects=page.locator('main select:not([multiple]):not([size]):not(.ql-header)');
   if(await selects.count()){
    const css=await selects.first().evaluate(el=>{
     const s=getComputedStyle(el);return {appearance:s.appearance,arrow:s.backgroundImage,height:el.getBoundingClientRect().height};
    });
    assert.equal(css.appearance,'none',route+' missing unified select appearance at '+width);
    assert(css.arrow.includes('svg'),route+' missing dropdown caret at '+width);
    assert(css.height>=43,route+' select too small at '+width);
   }
   if(route==='/ads.php'){
    const tabs=page.locator('.ui-ads-navigation .ui-tabbar a');
    assert.equal(await tabs.count(),4,'Ads should expose all four tabs');
    const rows=await tabs.evaluateAll(links=>links.map(el=>{
     const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,height:r.height,display:getComputedStyle(el).display};
    }));
    assert(rows.every(r=>r.display==='inline-flex'&&r.height>=44),'Ad tabs must be styled as accessible click targets');
    assert(rows.every((r,i)=>!i||r.left>=rows[i-1].right-1),'Ad tabs must not overlap');
    const nav=page.locator('.ui-ads-navigation').first();
    assert(await nav.isVisible(),'Ads site switcher and tabs must be visible together');
   }
   if(['/builder.php','/audit.php','/editor.php'].includes(route)){
    const metrics=await page.evaluate(()=>{
     const main=document.querySelector('main');
     const r=main.getBoundingClientRect();
     const grid=document.querySelector('.ui-builder-grid,.editor-form,.ui-audit-filters');
     return {left:r.left,right:r.right,width:r.width,
      columns:grid?getComputedStyle(grid).gridTemplateColumns.split(' ').length:0,
      viewport:innerWidth};
    });
    // Desktop workbenches must occupy the available shell, without a giant unused strip.
    if(width===1366||width===1920){
     assert(metrics.right>=width-3,route+' wasted desktop content width '+JSON.stringify(metrics));
     if(route!=='/audit.php')assert.equal(metrics.columns,2,route+' should show editor and inspector side by side at '+width);
     if(route==='/audit.php')assert.equal(metrics.columns,width===1920?7:3,'Audit filters should responsively wrap');
    }
    if(width<=390)assert(metrics.width>=width-3,route+' mobile canvas is undersized');
    if(width===1920)await page.screenshot({path:'ui-preview/layout-'+route.replace(/[^a-z]/g,'')+'-1920.png',fullPage:true});
   }
   if(width===390||width===1366)await page.screenshot({path:'ui-preview/audit-'+width+'-'+route.replace(/[^a-z]/g,'')+'.png',fullPage:true});
  }
 }
 await page.evaluate(()=>localStorage.setItem('comesy-theme','dark'));
 for(const route of ['/','/articles.php','/editor.php','/taxonomy.php','/media.php','/sites.php','/users.php','/site-permissions.php','/profile.php','/settings.php']){
  await page.goto(base+route,{waitUntil:'domcontentloaded'});
  await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
  assert(overflow<=2,'Dark theme overflow on '+route);
 }
 // Navigation changes must retain the active module, selected site and correct tab highlight.
 await page.goto(base+'/ads.php?site_id=1&kind=campaign',{waitUntil:'domcontentloaded'});
 await page.getByRole('navigation',{name:'Hirdetéskezelési modulok'}).getByRole('link',{name:'Kreatívok'}).click();
 assert(new URL(page.url()).searchParams.get('kind')==='creative','Ad tab navigation should select creatives');
 assert.equal(await page.locator('.ui-ads-navigation .ui-tabbar [aria-current="page"]').count(),1,'Exactly one ad tab should be active');
 await page.locator('.ui-ads-site-filter select[name="site_id"]').selectOption('2');
 await page.waitForURL(/site_id=2/);
 const selectedUrl=new URL(page.url());
 assert.equal(selectedUrl.searchParams.get('kind'),'creative','Switching site must retain the selected tab');
 assert.equal(selectedUrl.searchParams.get('site_id'),'2','Site filter must preserve selected site');
 assert.equal(await page.locator('.ui-ads-site-filter select[name="site_id"]').inputValue(),'2','Site picker must retain selection');
 await page.evaluate(()=>localStorage.setItem('comesy-theme','dark'));
 await page.reload({waitUntil:'domcontentloaded'});
 const darkSelector=await page.locator('.ui-ads-site-filter select').evaluate(el=>getComputedStyle(el).backgroundImage);
 assert(darkSelector.includes('%23A8B9D2'),'Dark theme dropdown arrow should remain visible');
 await page.evaluate(()=>localStorage.setItem('comesy-theme','light'));
 await page.goto(base+'/sites.php');
 await page.locator('input[name=name]').fill('Teszt webhely D');
 await page.locator('input[name=domain]').fill('site-d.example');
 await page.locator('input[name=slug]').fill('test-site-d');
 await Promise.all([page.waitForURL(/saved=1/),page.locator('form.ui-admin-form button[type=submit]').click()]);
 assert((await page.locator('.ui-admin-row').count())>=4,'Site creation failed');
 await page.goto(base+'/profile.php');
 const nameForm=page.locator('form:has(input[name="action"][value="name"])');
 await nameForm.locator('input[name="name"]').fill('Tesztelt admin');
 await nameForm.locator('button[type="submit"]').click();
 assert((await page.locator('main').textContent()).includes('A nevet mentettük.'),'Profile save failed');
 // Exercise actual builder draft/save/publish and the anonymous publisher output.
 await page.goto(base+'/builder.php?site_id=1&template=home',{waitUntil:'domcontentloaded'});
 assert(await page.locator('[data-builder-add]').isEnabled(),'Builder should be enabled for fixture site');
 const frame=page.frameLocator('#builder-preview-frame');
 assert(await frame.locator('.pub-header').isVisible(),'Builder same-origin live preview frame should render');
 await page.locator('#builder-new-type').selectOption('text');
 await page.locator('[data-builder-add]').click();
 await page.locator('.ui-builder-block').last().locator('input').first().fill('Éles előnézet tesztblokk');
 await Promise.all([page.waitForURL(/saved=save/),page.locator('button[name=action][value=save]').click()]);
 assert((await page.locator('.ui-builder-block').count())>=2,'Builder block not saved');
 page.once('dialog',dialog=>dialog.accept());
 await Promise.all([page.waitForURL(/saved=publish/),page.locator('button[name=action][value=publish]').click()]);
 const frontend=await page.request.get(base+'/public.php?site=test-0');
 assert.equal(frontend.status(),200,'Public site should load without login');
 assert((await frontend.text()).includes('Éles előnézet tesztblokk'),'Published template missing from public frontend');
 const importReport=await page.request.get(base+'/import-report.php?site_id=1&job_id=1');
 assert.equal(importReport.status(),200,'Import quality dashboard should render');
 assert((await importReport.text()).includes('Importminőség és erőforrások'),'Quality summary missing');
 const csv=await page.request.get(base+'/import-report.php?site_id=1&job_id=1&format=csv');
 assert.equal(csv.status(),200,'Quality CSV must download');
 assert((await csv.text()).includes('source_url'),'Quality CSV missing header');
 const urlMap=await page.request.get(base+'/import-report.php?site_id=1&job_id=1&format=url-map');
 assert.equal(urlMap.status(),200,'URL mapping proposals CSV must export');
 assert((await urlMap.text()).includes('redirect_ready'),'URL mapping must include review-only state');
 const crossSite=await page.request.get(base+'/import-report.php?site_id=2&job_id=1');
 assert.equal(crossSite.status(),404,'Wrong-site quality report must be absent');
 // Verify that an author cannot access platform administration.
 const author=await browser.newPage({viewport:{width:390,height:844}});
 await author.goto(base+'/login.php');
 await author.locator('[name=email]').fill('author@testing.invalid');
 await author.locator('[name=password]').fill('StrongTestingPwd!2026');
 await author.locator('button[type=submit]').click();
 await author.waitForURL(base+'/');
 for(const route of ['/users.php','/sites.php','/site-permissions.php','/migrate.php','/ads.php','/imports.php','/builder.php','/audit.php','/import-report.php?site_id=1&job_id=1']){
  const response=await author.goto(base+route);
  assert.equal(response.status(),403,'Author must not access '+route);
 }
 for(const route of ['/editor.php','/articles.php','/media.php']){
  const response=await author.goto(base+route);
  assert.equal(response.status(),200,'Author access regression '+route);
 }
 await author.close();
 for(const role of ['site_admin','ad_manager']){
  const rolePage=await browser.newPage();
  await rolePage.goto(base+'/login.php');
  await rolePage.locator('[name=email]').fill(role+'@testing.invalid');
  await rolePage.locator('[name=password]').fill('StrongTestingPwd!2026');
  await rolePage.locator('button[type=submit]').click();
  await rolePage.waitForURL(base+'/');
  for(const restricted of ['/users.php','/sites.php','/site-permissions.php','/migrate.php']){
   const response=await rolePage.goto(base+restricted);
   assert.equal(response.status(),403,role+' cannot open '+restricted);
  }
  const adsResponse=await rolePage.goto(base+'/ads.php');
  assert.equal(adsResponse.status(),200,role+' ad admin access check');
  const editorResponse=await rolePage.goto(base+'/editor.php');
  assert.equal(editorResponse.status(),role==='site_admin'?200:403,role+' editor access');
  await rolePage.close();
 }
 console.log('PASS: all 17 admin routes at 320, 390, 768, 1366, 1920 widths.');
}finally{await browser.close();}
