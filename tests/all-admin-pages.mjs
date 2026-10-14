import {chromium} from 'playwright-core';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,executablePath:'/usr/bin/google-chrome',args:['--no-sandbox']});
const base='http://127.0.0.1:8765';
const routes=['/','/articles.php','/editor.php','/taxonomy.php','/taxonomy.php?type=tag','/media.php','/sites.php','/users.php','/site-permissions.php','/profile.php','/settings.php','/migrate.php'];
try{
 const page=await browser.newPage({viewport:{width:390,height:844}});
 await page.goto(base+'/login.php');
 await page.locator('[name=email]').fill('platform_admin@testing.invalid');
 await page.locator('[name=password]').fill('StrongTestingPwd!2026');
 await page.locator('button[type=submit]').click();
 await page.waitForURL(base+'/');
 for(const width of [320,390,768,1366]){
  await page.setViewportSize({width,height:850});
  for(const route of routes){
   const response=await page.goto(base+route,{waitUntil:'domcontentloaded'});
   assert.equal(response.status(),200,route+' status');
   assert.equal(await page.locator('main h1').count()>0,true,route+' title');
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
   assert(overflow<=2,route+' horizontal overflow '+overflow+'px at '+width);
   if(width===390||width===1366)await page.screenshot({path:'ui-preview/audit-'+width+'-'+route.replace(/[^a-z]/g,'')+'.png',fullPage:true});
  }
 }
 // Verify that an author cannot access platform administration.
 const author=await browser.newPage({viewport:{width:390,height:844}});
 await author.goto(base+'/login.php');
 await author.locator('[name=email]').fill('author@testing.invalid');
 await author.locator('[name=password]').fill('StrongTestingPwd!2026');
 await author.locator('button[type=submit]').click();
 await author.waitForURL(base+'/');
 for(const route of ['/users.php','/sites.php','/site-permissions.php','/migrate.php']){
  const response=await author.goto(base+route);
  assert.equal(response.status(),403,'Author must not access '+route);
 }
 for(const route of ['/editor.php','/articles.php','/media.php']){
  const response=await author.goto(base+route);
  assert.equal(response.status(),200,'Author access regression '+route);
 }
 await author.close();
 console.log('PASS: all 12 admin routes at 320, 390, 768, 1366 widths.');
}finally{await browser.close();}
