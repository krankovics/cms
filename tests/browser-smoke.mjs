import assert from 'node:assert/strict';
import {mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright-core';

const chrome = process.env.CHROME_PATH ||
  ['/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser']
    .find(path => {try{execFileSync('test',['-x',path]);return true;}catch{return false;}});
if(!chrome)throw new Error('No Chrome/Chromium executable on runner.');
const browser=await chromium.launch({
 executablePath:chrome,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']
});
mkdirSync('ui-preview',{recursive:true});
const base='http://127.0.0.1:8765';
async function login(page,role='editor'){
 await page.goto(base+'/login.php',{waitUntil:'networkidle'});
 await page.getByLabel('E-mail cím').fill(role+'@testing.invalid');
 await page.getByLabel('Jelszó').fill('StrongTestingPwd!2026');
 await page.getByRole('button',{name:/Bejelentkezés/}).click();
 await page.waitForURL(base+'/');
 await page.waitForLoadState('networkidle');
}
async function overflow(page,label){
 const result=await page.evaluate(()=>({
  screen:innerWidth,
  scroll:document.documentElement.scrollWidth,
  app:document.body.classList.contains('comesy-admin'),
  sidebar:document.querySelector('.comesy-sidebar')?.getBoundingClientRect().toJSON(),
  header:document.querySelector('.comesy-global-header')?.getBoundingClientRect().toJSON()
 }));
 assert(result.app,label+': Admin v2 shell not activated');
 assert(result.scroll<=result.screen+2,label+': horizontal overflow '+JSON.stringify(result));
 return result;
}
try{
 const desktop=await browser.newPage({viewport:{width:1366,height:768},deviceScaleFactor:1});
 await login(desktop);
 let layout=await overflow(desktop,'1366 desktop');
 assert(Math.abs(layout.sidebar.x)<=1,'Desktop sidebar must start at left edge');
 assert(Math.abs(layout.sidebar.width-248)<=2,'Desktop sidebar must have full width');
 assert((await desktop.locator('.ui-kpi-card').count())===4,'Missing dashboard metrics');
 await desktop.locator('[data-sidebar-collapse]').click();
 assert(await desktop.locator('body').evaluate(el=>el.classList.contains('ui-sidebar-compact')),'Sidebar did not collapse');
 await desktop.reload({waitUntil:'domcontentloaded'});
 assert(await desktop.locator('body').evaluate(el=>el.classList.contains('ui-sidebar-compact')),'Sidebar preference not persisted');
 await desktop.locator('[data-sidebar-collapse]').click();
 await desktop.screenshot({path:'ui-preview/desktop-dashboard.png',fullPage:true});
 await desktop.setViewportSize({width:1100,height:850});
 layout=await overflow(desktop,'1100 compact desktop');
 assert(Math.abs(layout.sidebar.width-76)<=2,'Compact sidebar must be 76px');
 await desktop.close();

 const mobile=await browser.newPage({viewport:{width:390,height:844},deviceScaleFactor:1,isMobile:true,hasTouch:true});
 await login(mobile);
 layout=await overflow(mobile,'390 mobile');
 assert(Math.abs(layout.sidebar.x+layout.sidebar.width)>20,'Mobile drawer should be closed initially');
 assert((await mobile.locator('.ui-kpi-card').count())===4,'Mobile metric cards missing');
 await mobile.screenshot({path:'ui-preview/mobile-dashboard.png',fullPage:true});
 const menu=mobile.locator('[data-menu-toggle]');
 assert(await menu.isVisible(),'Mobile hamburger missing');
 await menu.click();
 assert((await menu.getAttribute('aria-expanded'))==='true','Drawer did not open');
 await mobile.waitForTimeout(350);
 assert((await mobile.locator('.comesy-sidebar').boundingBox()).x>=-2,'Drawer not visible after opening');
 await mobile.keyboard.press('Escape');
 assert((await menu.getAttribute('aria-expanded'))==='false','Escape must close menu');
 await menu.click();
 await mobile.locator('.comesy-sidebar a[href="/articles.php"]').click();
 await mobile.waitForURL(base+'/articles.php');
 await overflow(mobile,'390 article list');
 await mobile.goto(base+'/',{waitUntil:'networkidle'});
 await mobile.locator('[data-theme-toggle]').click();
 assert(await mobile.locator('html').getAttribute('data-theme')==='dark','Dark theme toggle failed');
 await mobile.screenshot({path:'ui-preview/mobile-dark.png',fullPage:true});
 await mobile.setViewportSize({width:320,height:740});
 await overflow(mobile,'320 mobile');
 await mobile.close();
 const admin=await browser.newPage({viewport:{width:1366,height:850},deviceScaleFactor:1});
 await login(admin,'platform_admin');
 // Full rich-editor WYSIWYG retention and requested layout semantics.
 await admin.goto(base+'/articles.php',{waitUntil:'networkidle'});
 const richLink=admin.locator('a.ui-table-title',{hasText:'Szerkesztői gazdag tartalom próba'});
 assert.equal(await richLink.count(),1,'Rich seeded article should appear in article list.');
 await richLink.click();
 await admin.waitForURL(/\/editor\.php\?id=/);
 await admin.locator('.ed-rich-canvas').waitFor({state:'visible'});
 const previewId=new URL(admin.url()).searchParams.get('id');
 const sourcePreview=await admin.request.get(base+'/source-preview.php?id='+previewId);
 assert.equal(sourcePreview.status(),200,'Authorized imported draft must have a private preview.');
 const previewBody=await sourcePreview.text();
 assert(previewBody.includes('font-size:23px'),'Source CSS profile should be applied to authenticated saved draft.');
 assert(previewBody.includes('Fotó: Tesztszerkesztőség'),'Imported photo credit must survive source preview.');
 assert(previewBody.includes('class="cms-source-header"')&&previewBody.includes('class="cms-source-footer"'),'Imported draft should render publisher header and footer.');
 assert(previewBody.includes('Forrás Napilap')&&previewBody.includes('Belföld')&&previewBody.includes('Impresszum'),'Source logo identity, navigation and legal footer must be present.');
 assert(previewBody.includes('cms-source-breadcrumb'),'Saved preview must include source-page breadcrumb.');
 if(previewBody.includes('class="cms-cover"'))assert(previewBody.indexOf('<h1>')<previewBody.indexOf('class="cms-cover"'),'Headline must precede featured cover image in source-page preview.');
 assert(previewBody.includes('cms-source-menu-toggle'),'The category menu must be collapsed instead of occupying the whole mobile screen.');

 // Editor's "Előnézet új lapon" must share the canonical mobile source
 // rendering without saving temporary content or opening about:blank.
 const previewCsrf=await admin.locator('#editor-form input[name="csrf"]').inputValue();
 const liveDraft=await admin.request.post(base+'/source-preview.php?id='+previewId,{form:{
  csrf:previewCsrf,
  preview_title:'Nem mentett előnézeti cím',
  preview_lead:'Csak az előnézetben látható lead',
  preview_image:'',
  preview_content:JSON.stringify([{type:'richhtml',text:'<p>Friss előnézeti szöveg</p><em>Rendben</em>'}])
 }});
 assert.equal(liveDraft.status(),200,'CSRF-protected live draft preview should be available to its editor.');
 const liveBody=await liveDraft.text();
 assert(liveBody.includes('Nem mentett előnézeti cím')&&liveBody.includes('Friss előnézeti szöveg'),'Preview POST should render the current unsaved editor content.');
 assert(liveBody.includes('Friss előnézeti szöveg'),'Live preview must preserve editorial HTML content.');
 assert(liveBody.includes('cms-source-header')&&liveBody.includes('cms-source-footer')&&liveBody.includes('name="viewport"'),'Live preview should share the responsive publisher shell.');
 const storedAgain=await (await admin.request.get(base+'/source-preview.php?id='+previewId)).text();
 assert(!storedAgain.includes('Nem mentett előnézeti cím'),'Preview POST must not modify the persisted article.');
 const openPreview=admin.waitForEvent('popup');
 await admin.locator('#preview-button').click();
 const previewTab=await openPreview;
 await previewTab.waitForURL('**/source-preview.php?id=*');
 assert(previewTab.url().includes('/source-preview.php?id='+previewId),'New-tab editor preview should use canonical URL instead of about:blank.');
 assert(await previewTab.locator('header.cms-source-header').count()===1,'New-tab preview should include the branded publisher header.');
 await previewTab.close();

 assert(!previewBody.includes('<script>'),'Saved preview should not emit executable scripts.');
 assert((await admin.locator('#source-style-label').innerText()).includes('source-example.invalid'),'Editor should recognize the original source design profile.');
 const siteDesignContext=await browser.newContext({viewport:{width:1366,height:850}});
 const siteDesignEditor=await siteDesignContext.newPage();
 await login(siteDesignEditor,'platform_admin');
 await siteDesignEditor.goto(base+'/source-site-design.php?id='+previewId,{waitUntil:'networkidle'});
 assert.equal(await siteDesignEditor.locator('input[name="name"]').inputValue(),'Forrás Napilap','Source site shell should be editable centrally.');
 assert((await siteDesignEditor.locator('textarea[name="header_links"]').inputValue()).includes('Belföld'),'Global source main menu should be editable.');
 await siteDesignEditor.locator('input[name="name"]').fill('Kézzel jóváhagyott kiadó');
 await Promise.all([
  siteDesignEditor.waitForURL(/saved=1/),
  siteDesignEditor.getByRole('button',{name:'Arculat jóváhagyása és mentése'}).click()
 ]);
 const updatedPreview=await admin.request.get(base+'/source-preview.php?id='+previewId);
 assert((await updatedPreview.text()).includes('Kézzel jóváhagyott kiadó'),'Approved publisher shell must persist on the saved draft.');
 await siteDesignEditor.goto(base+'/sites.php?edit=1');
 assert((await siteDesignEditor.locator('#source-template-id option').count())>=3,'Collected appearance templates must be selectable in Webhelyek.');
 await siteDesignContext.close();


 assert(await admin.locator('.ui-editor-main .ed-seo').count()===1,'SEO belongs below article body inside main editor column.');
 assert(await admin.locator('.ui-editor-aside .ed-seo').count()===0,'SEO cannot remain in narrow right-hand inspector.');
 assert(await admin.locator('.ui-editor-aside a[href="/taxonomy.php"]').count()===0,'Redundant taxonomy shortcut must be removed.');
 assert(await admin.locator('.ed-import-rich-preview').count()===0,'Inline full-height preview must not be mounted.');
 assert(await admin.locator('.ed-rich-toolbar [aria-label="Félkövér"]').isVisible(),'Rich article must expose an actual formatting toolbar.');
 const beforeHtml=await admin.locator('.ed-rich-canvas').innerHTML();
 for(const item of ['<figure>','<figcaption>','<table>','<iframe'])assert(beforeHtml.includes(item),'Missing rich node before editing: '+item);
 const popPromise=admin.waitForEvent('popup');
 await admin.locator('#preview-button').click();
 const popup=await popPromise;
 await popup.waitForURL('**/source-preview.php?id=*');
 assert(popup.url().includes('/source-preview.php?id='+previewId),'Editor popup must render on canonical same-origin preview page rather than an about:blank iframe.');
 assert(await popup.locator('meta[name="viewport"]').count()===1,'New-tab preview must be mobile responsive.');
 assert((await popup.locator('h1').innerText()).includes('Szerkesztői gazdag tartalom próba'),'Preview must use unsaved article title.');
 assert(await popup.locator('.cms-source-header').count()===1&&await popup.locator('.cms-source-footer').count()===1,'Unsaved article preview includes publisher global site shell.');
 assert(await popup.locator('.cms-source-menu-toggle').count()===1,'Unsaved preview must use compact source menu drawer.');
 await popup.close();
 await admin.locator('.ed-rich-canvas').evaluate(el=>{
  const node=[...el.querySelectorAll('p')][0].firstChild;
  const range=document.createRange();range.setStart(node,0);range.setEnd(node,7);
  const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);
 });
 await admin.locator('.ed-rich-toolbar [aria-label="Félkövér"]').click();
 assert((await admin.locator('.ed-rich-canvas').innerHTML()).includes('strong'),'Toolbar should edit formatted narrative inline.');
 await Promise.all([admin.waitForURL(/saved=1/),admin.locator('.editor-save-button').click()]);
 const afterHtml=await admin.locator('.ed-rich-canvas').innerHTML();
 for(const item of ['<figcaption>','<table>','<iframe','<strong>'])assert(afterHtml.includes(item),'Rich content lost in editor save/reload: '+item);
 assert(await admin.locator('.ed-rich-canvas').isVisible(),'Rich WYSIWYG must reopen after a save.');
 for(const [name,path] of [['articles','/articles.php'],['taxonomy','/taxonomy.php?type=tag&site_id=1'],['media','/media.php'],['users','/users.php']]){
  await admin.goto(base+path,{waitUntil:'networkidle'});
  await overflow(admin,'desktop '+name);
  assert(await admin.locator('.ui-page-title').isVisible(),name+': missing SaaS page header');
  await admin.screenshot({path:'ui-preview/desktop-'+name+'.png',fullPage:true});
 }
 await admin.setViewportSize({width:390,height:844});
 for(const [name,path] of [['articles','/articles.php'],['taxonomy','/taxonomy.php?type=tag&site_id=1'],['media','/media.php'],['users','/users.php']]){
  await admin.goto(base+path,{waitUntil:'networkidle'});
  await overflow(admin,'mobile '+name);
  await admin.screenshot({path:'ui-preview/mobile-'+name+'.png',fullPage:true});
 }
 await admin.goto(base+'/taxonomy.php?type=tag&site_id=1',{waitUntil:'networkidle'});
 assert(await admin.locator('.ui-taxonomy-row').count()>0,'Taxonomy data not shown');
 await admin.locator('[data-panel-open="taxonomy-editor"]').first().click();
 assert(await admin.locator('#taxonomy-editor').evaluate(el=>el.classList.contains('is-open')),'Taxonomy edit drawer did not open');
 await admin.locator('[data-panel-close]').first().click();
 assert(!(await admin.locator('#taxonomy-editor').evaluate(el=>el.classList.contains('is-open'))),'Taxonomy drawer not closed');
 await admin.locator('[data-panel-open="taxonomy-editor"]').first().click();
 await admin.locator('#taxonomy-edit-form [name="name"]').fill('UI automatikus címke');
 await Promise.all([admin.waitForURL(/saved=1/),admin.locator('#taxonomy-edit-form button[type="submit"]').click()]);
 assert((await admin.locator('.ui-taxonomy-row').count())>=2,'Saved tag missing after redirect');
 await admin.goto(base+'/media.php',{waitUntil:'networkidle'});
 await admin.locator('[data-ui-dialog-open="media-upload-dialog"]').first().click();
 assert(await admin.locator('#media-upload-dialog').evaluate(el=>el.open),'Upload dialog did not open');
 await admin.locator('#media-upload-dialog [data-ui-dialog-close]').first().click();
 assert(!(await admin.locator('#media-upload-dialog').evaluate(el=>el.open)),'Upload dialog did not close');
 await admin.locator('[data-ui-dialog-open="media-upload-dialog"]').first().click();
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRucAAAAASUVORK5CYII=','base64');
 await admin.locator('#media-upload-file').setInputFiles({name:'comesy-test.png',mimeType:'image/png',buffer:png});
 await admin.locator('#media-upload-form [name="alt"]').fill('Tesztkép');
 await Promise.all([admin.waitForURL(/saved=1/),admin.locator('#media-upload-form button[type="submit"]').click()]);
 assert((await admin.locator('.ui-asset').count())===1,'Uploaded image missing');
 await admin.locator('.ui-asset-preview').first().click();
 assert(await admin.locator('#media-inspector').evaluate(el=>el.classList.contains('is-open')),'Media detail drawer missing');
 await admin.locator('[data-asset-alt-form] [name="alt"]').fill('Megújított képleírás');
 await Promise.all([admin.waitForURL(/saved=alt/),admin.locator('[data-asset-alt-form] button[type="submit"]').click()]);
 await admin.locator('.ui-asset-preview').first().click();
 assert((await admin.locator('[data-asset-detail-alt]').textContent()).includes('Megújított képleírás'),'Alt text update not persisted');
 await admin.locator('#media-inspector [data-panel-close]').first().click();
 await admin.goto(base+'/media.php');
 const mediaArea=await admin.evaluate(()=>({
  grid:document.querySelector('.ui-gallery').getBoundingClientRect().width,
  wrap:document.querySelector('.ui-media-workbench').getBoundingClientRect().width
 }));
 assert(mediaArea.grid>=mediaArea.wrap-2,'Media gallery must use full canvas.');
 await admin.locator('.ui-asset-preview').first().click();
 assert(await admin.locator('#media-inspector').evaluate(el=>el.classList.contains('is-open')),'Desktop image details should open on demand.');
 await admin.keyboard.press('Escape');
 assert(await admin.locator('#media-inspector').evaluate(el=>!el.classList.contains('is-open')),'Escape should close media details.');
 await admin.goto(base+'/articles.php');
 await admin.locator('a.ui-table-title',{hasText:'Szerkesztői gazdag tartalom próba'}).click();
 await admin.locator('#featured-choose').click();
 await admin.locator('#featured-media-picker').waitFor({state:'visible'});
 await admin.locator('#featured-picker-search').fill('comesy-test');
 await admin.locator('.ui-picker-asset:visible').first().waitFor({state:'visible'});
 await admin.locator('.ui-picker-asset:visible').first().click();
 const imageUrl=await admin.locator('#og-image-url').inputValue();
 assert(imageUrl.startsWith(base)&&imageUrl.includes('/media-file.php?id='),'Picked image must have a saveable URL.');
 await Promise.all([admin.waitForURL(/saved=1/),admin.locator('.editor-save-button').click()]);
 assert.equal(await admin.locator('#og-image-url').inputValue(),imageUrl,'Featured image must persist.');
 await admin.locator('#featured-clear').click();
 await Promise.all([admin.waitForURL(/saved=1/),admin.locator('.editor-save-button').click()]);
 assert.equal(await admin.locator('#og-image-url').inputValue(),'','Cleared image must stay cleared.');


 await admin.goto(base+'/users.php',{waitUntil:'networkidle'});
 await admin.locator('[data-ui-search="user-list"]').fill('editor');
 assert((await admin.locator('.ui-person-row:not([hidden])').count())===1,'User search filtering incorrect');
 await admin.locator('[data-ui-search="user-list"]').fill('');
 await admin.locator('[data-panel-open="user-editor"]').first().click();
 assert(await admin.locator('#user-editor').evaluate(el=>el.classList.contains('is-open')),'User drawer did not open');
 await admin.locator('[data-panel-close]').first().click();
 await admin.locator('[data-panel-open="user-editor"]').first().click();
 await admin.locator('#user-edit-form [name="name"]').fill('Teszt UI felhasználó');
 await admin.locator('#user-edit-form [name="email"]').fill('ui.smoke@testing.invalid');
 await admin.locator('#user-edit-form [name="new_password"]').fill('UniqueStrongTest2026!');
 await Promise.all([admin.waitForURL(/saved=1/),admin.locator('#user-edit-form button[type="submit"]').click()]);
 assert((await admin.locator('.ui-person-row').count())===6,'Created user missing');
 await admin.setViewportSize({width:320,height:740});
 await overflow(admin,'320 users');
 await admin.goto(base+'/settings.php',{waitUntil:'networkidle'});
 await overflow(admin,'settings mobile');
 await admin.close();
 console.log('PASS: desktop 1366/1100, mobile 390/320, menu/escape, dark mode, KPI and overflow checks.');
}finally{await browser.close();}
