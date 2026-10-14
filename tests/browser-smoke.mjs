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
async function login(page){
 await page.goto(base+'/login.php',{waitUntil:'networkidle'});
 await page.getByLabel('E-mail cím').fill('editor@testing.invalid');
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
 console.log('PASS: desktop 1366/1100, mobile 390/320, menu/escape, dark mode, KPI and overflow checks.');
}finally{await browser.close();}
