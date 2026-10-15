<?php
declare(strict_types=1);
/** Destructive integration tests: run ONLY against a disposable comesy_test database. */
if(getenv('COMESY_TEST_DB')!=='comesy_test'){fwrite(STDERR,"COMESY_TEST_DB must equal comesy_test.\n");exit(1);}
$dsn=(string)getenv('COMESY_TEST_DSN');
$pdo=new PDO($dsn,(string)getenv('COMESY_TEST_USER'),(string)getenv('COMESY_TEST_PASSWORD'),[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false
]);
require dirname(__DIR__).'/app/article-sites.php';
require dirname(__DIR__).'/app/site-access.php';
$tests=0;
function ok(bool $condition,string $message):void{global $tests;$tests++;if(!$condition)throw new RuntimeException($message);}
foreach(['schema','editor','content','site-permissions','ads','import','import-metrics','layouts'] as $schema){
 $sql=file_get_contents(dirname(__DIR__).'/database/'.$schema.'.sql');
 foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)$pdo->exec($statement);
}
$sites=[];
foreach(['Teszt portál A','Teszt portál B','Teszt portál C'] as $i=>$name){
 $q=$pdo->prepare('INSERT INTO sites(name,domain,slug) VALUES(?,?,?)');
 $q->execute([$name,'test'.$i.'.invalid','test-'.$i]);$sites[]=(int)$pdo->lastInsertId();
}
$uid=[];
foreach(['editor','author','site_admin','ad_manager','platform_admin'] as $role){
 $q=$pdo->prepare('INSERT INTO users(email,name,password_hash,role) VALUES(?,?,?,?)');
 $q->execute([$role.'@testing.invalid',ucfirst($role),password_hash('StrongTestingPwd!2026',PASSWORD_DEFAULT),$role]);
 $uid[$role]=(int)$pdo->lastInsertId();
}
$q=$pdo->prepare('INSERT INTO user_site_permissions(user_id,site_id,is_primary,can_tags,can_media) VALUES(?,?,?,?,?)');
$q->execute([$uid['editor'],$sites[0],1,1,1]);
$q->execute([$uid['editor'],$sites[1],1,1,1]);
$q->execute([$uid['author'],$sites[2],1,1,1]);
$q->execute([$uid['site_admin'],$sites[1],1,1,1]);
$q->execute([$uid['ad_manager'],$sites[0],1,0,0]);
$editor=['id'=>$uid['editor'],'role'=>'editor'];
$author=['id'=>$uid['author'],'role'=>'author'];
$siteAdmin=['id'=>$uid['site_admin'],'role'=>'site_admin'];
$sitesForEditor=[$sites[0],$sites[1]];
ok(allowed_primary_sites($pdo,$editor)===$sitesForEditor,'Editor must be allowed on two sites.');
ok(allowed_primary_sites($pdo,$author)===[$sites[2]],'Author must have an isolated editable site.');
ok(allowed_primary_sites($pdo,$siteAdmin)===[$sites[1]],'Site admin may edit only assigned sites.');
ok(in_array(0,allowed_resource_sites($pdo,$editor,'tags'),true),'Shared taxonomy must be visible to editor.');
ok(!in_array($sites[2],allowed_primary_sites($pdo,$editor),true),'Editor must not edit a third site.');
$q=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,status,content_json) VALUES(?,?,?,'teszt-cikk','draft','[]')");
$q->execute([$sites[0],$uid['editor'],'Többwebhelyes tesztcikk']);
$articleId=(int)$pdo->lastInsertId();
$article=['id'=>$articleId,'site_id'=>$sites[0]];
ok(article_sites_ready($pdo),'article_sites must exist after migration.');
ok(article_site_ids($pdo,$article)===[$sites[0]],'Legacy article must fall back to primary site.');
$pdo->beginTransaction();
article_site_save($pdo,$articleId,$sites[0],[$sites[0],$sites[1]]);
$pdo->commit();
ok(article_site_ids($pdo,$article)===$sitesForEditor,'Shared article must be linked to two sites.');
$pdo->beginTransaction();
try{article_site_save($pdo,$articleId,$sites[0],[$sites[1]]);throw new RuntimeException('Primary site removal must fail.');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'elsődleges'),'Primary site must be protected.');}
$pdo->rollBack();
ok(article_site_ids($pdo,$article)===$sitesForEditor,'Failed reassignment must not destroy links.');
$q=$pdo->prepare('SELECT COUNT(*) FROM articles a WHERE (a.site_id=? OR EXISTS(SELECT 1 FROM article_sites x WHERE x.article_id=a.id AND x.site_id=?))');
$q->execute([$sites[1],$sites[1]]);
ok((int)$q->fetchColumn()===1,'Secondary site must find shared article.');
$q->execute([$sites[2],$sites[2]]);
ok((int)$q->fetchColumn()===0,'Unrelated site must not see shared article.');
$pdo->exec('INSERT IGNORE INTO article_sites(article_id,site_id) SELECT id,site_id FROM articles');
$pdo->exec('INSERT IGNORE INTO article_sites(article_id,site_id) SELECT id,site_id FROM articles');
ok(count(article_site_ids($pdo,$article))===2,'Idempotent migration must preserve site associations.');
$q=$pdo->prepare('INSERT INTO taxonomy_terms(site_id,type,name,slug) VALUES(0,?,?,?)');
$q->execute(['tag','Közös címke','kozos-cimke']);$global=(int)$pdo->lastInsertId();
$q=$pdo->prepare('INSERT INTO taxonomy_terms(site_id,type,name,slug) VALUES(?,?,?,?)');
$q->execute([$sites[0],'tag','Helyi címke','helyi-cimke']);
ok($global>0,'Shared taxonomy term should be created.');
$q=$pdo->prepare('SELECT COUNT(*) FROM taxonomy_terms WHERE site_id=0 AND type=? AND slug=?');
$q->execute(['tag','kozos-cimke']);
ok((int)$q->fetchColumn()===1,'Shared term should be unique across the installation.');
$q=$pdo->prepare('INSERT INTO article_terms(article_id,term_id) VALUES(?,?)');
$q->execute([$articleId,$global]);
$q=$pdo->prepare('SELECT COUNT(*) FROM article_terms WHERE article_id=? AND term_id=?');
$q->execute([$articleId,$global]);
ok((int)$q->fetchColumn()===1,'Article must link to globally shared term.');
$pdo->prepare('DELETE FROM articles WHERE id=?')->execute([$articleId]);
$q=$pdo->prepare('SELECT COUNT(*) FROM article_sites WHERE article_id=?');$q->execute([$articleId]);
ok((int)$q->fetchColumn()===0,'Deleting article must cascade to its site assignments.');
// Native advertiser, campaign and site-scoped creatives.
$q=$pdo->prepare('INSERT INTO ad_advertisers(site_id,name) VALUES(?,?)');
$q->execute([$sites[0],'Teszt hirdető']);$adv=(int)$pdo->lastInsertId();
$q=$pdo->prepare('INSERT INTO ad_zones(site_id,code,name,placement) VALUES(?,?,?,?)');
$q->execute([$sites[0],'article-top','Cikk teteje','article']);$zone=(int)$pdo->lastInsertId();
$q=$pdo->prepare('INSERT INTO ad_campaigns(site_id,advertiser_id,name,state,priority) VALUES(?,?,?,?,?)');
$q->execute([$sites[0],$adv,'Teszt kampány','active',70]);$campaign=(int)$pdo->lastInsertId();
$q=$pdo->prepare('INSERT INTO ad_creatives(campaign_id,zone_id,name,image_url,destination_url,alt_text) VALUES(?,?,?,?,?,?)');
$q->execute([$campaign,$zone,'Kreatív','https://example.test/a.png','https://example.test/go','Kép']);$creative=(int)$pdo->lastInsertId();
$q=$pdo->prepare('SELECT COUNT(*) FROM ad_creatives cr JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id WHERE cr.id=? AND c.site_id=z.site_id AND z.site_id=?');
$q->execute([$creative,$sites[0]]);ok((int)$q->fetchColumn()===1,'Creative linked to matching site campaign and zone.');
$q->execute([$creative,$sites[1]]);ok((int)$q->fetchColumn()===0,'Creative must not leak to other site.');
$q=$pdo->prepare("INSERT INTO import_jobs(site_id,created_by,mode,source_url,max_items) VALUES(?,?,'url',?,1)");
$q->execute([$sites[0],$uid['editor'],'https://news.example.org/example']);$importJob=(int)$pdo->lastInsertId();
$q=$pdo->prepare("INSERT INTO import_items(job_id,site_id,source_url,source_url_hash,task_type) VALUES(?,?,?,?,'article')");
$url='https://news.example.org/example';$q->execute([$importJob,$sites[0],$url,hash('sha256',$url)]);
$importItemId=(int)$pdo->lastInsertId();
$q=$pdo->prepare('SELECT COUNT(*) FROM import_items WHERE job_id=? AND status=?');$q->execute([$importJob,'pending']);
ok((int)$q->fetchColumn()===1,'Durable import queue must preserve pending URL task.');
$q=$pdo->prepare('SELECT COUNT(*) FROM import_items i JOIN import_jobs j ON j.id=i.job_id WHERE j.site_id=? AND i.site_id=?');
$q->execute([$sites[1],$sites[0]]);
ok((int)$q->fetchColumn()===0,'Import task must not leak across sites.');
require dirname(__DIR__).'/app/import-worker.php';
$discoveryTask=['job_id'=>$importJob,'site_id'=>$sites[0],'depth'=>0,'max_items'=>10,'job_source'=>'https://news.example.org/sitemapindex.xml'];
$added=imp_queue_discovery($pdo,$discoveryTask,[
 'https://news.example.org/',
 'https://news.example.org/home_page_sitemap.xml',
 'https://news.example.org/kultura/2026/10/valodi-cikk'
],false);
ok($added===1,'Queue must only accept actual article candidate URLs, not homepage or XML.');
$q=$pdo->prepare("SELECT COUNT(*) FROM import_items WHERE job_id=? AND task_type='article'");
$q->execute([$importJob]);
ok((int)$q->fetchColumn()===2,'Homepage or sitemap must not enter article queue.');
$task=['id'=>$importItemId,'job_id'=>$importJob,'site_id'=>$sites[0],'source_url'=>$url,'created_by'=>$uid['editor']];
$parsed=['title'=>'Forráscikk import teszt','lead'=>'Próba lead','blocks'=>[['type'=>'paragraph','text'=>'Szerkeszthető cikkrészlet.']]];
$first=imp_save_article($pdo,$task,$parsed,'<article><p>Test</p></article>');
ok($first['status']==='completed'&&$first['article_id']>0,'First import must create draft article.');
$second=imp_save_article($pdo,$task,$parsed,'<article><p>Test</p></article>');
ok($second['status']==='skipped'&&$second['article_id']===$first['article_id'],'Second identical import must skip duplicate.');
require_once dirname(__DIR__).'/app/import-jobs.php';
// Previously skipped incomplete sources may be retried after an extractor fix,
// but completed articles and unrelated skips must remain unchanged.
$q=$pdo->prepare("INSERT INTO import_jobs(site_id,created_by,mode,source_url,max_items,status,completed,skipped) VALUES(?,?,'sitemap',?,10,'finished',1,2)");
$q->execute([$sites[0],$uid['editor'],'https://news.example.org/sitemap.xml']);$rescanJob=(int)$pdo->lastInsertId();
$addItem=$pdo->prepare('INSERT INTO import_items(job_id,site_id,source_url,source_url_hash,task_type,status,last_error) VALUES(?,?,?,?,"article",?,?)');
foreach([
 ['https://news.example.org/missing-story','skipped','Nem található önálló, ellenőrizhető cikktörzs: a forrásoldalon csak lead van.'],
 ['https://news.example.org/already-reviewed','skipped','A publikált cikk nem írható felül.'],
 ['https://news.example.org/good-story','completed',null]
] as $item){$addItem->execute([$rescanJob,$sites[0],$item[0],hash('sha256',$item[0]),$item[1],$item[2]]);}
ok(imp_reset_items($pdo,$rescanJob,'retry_skipped')===1,'Only content-rejected source items should be requeued.');
$q=$pdo->prepare('SELECT source_url,status,last_error FROM import_items WHERE job_id=? ORDER BY id');$q->execute([$rescanJob]);$resetRows=$q->fetchAll();
ok($resetRows[0]['status']==='pending'&&$resetRows[0]['last_error']===null,'Content-rejected item must become pending again.');
ok($resetRows[1]['status']==='skipped'&&$resetRows[2]['status']==='completed','Other skipped and completed articles must remain untouched.');

$metrics=['result'=>'imported','response_bytes'=>1040,'block_count'=>3,'image_count'=>1,'link_count'=>2,'quality_flags'=>['short_lead']];
imp_store_metrics($pdo,$task,$metrics,175);
$q=$pdo->prepare('SELECT response_bytes,duration_ms,quality_flags FROM import_item_metrics WHERE item_id=?');
$q->execute([$importItemId]);$measured=$q->fetch();
ok((int)$measured['response_bytes']===1040&&(int)$measured['duration_ms']===175,'Worker metrics must persist response bytes and duration.');
ok(in_array('short_lead',json_decode($measured['quality_flags'],true),true),'Quality signals must be saved as JSON.');
imp_store_metrics($pdo,$task,['result'=>'unchanged','quality_flags'=>[]],22);
$q=$pdo->prepare('SELECT COUNT(*),MAX(duration_ms) FROM import_item_metrics WHERE item_id=?');$q->execute([$importItemId]);$metricRow=$q->fetch(PDO::FETCH_NUM);
ok((int)$metricRow[0]===1&&(int)$metricRow[1]===22,'Retry must upsert one metrics row instead of duplicating it.');
$q=$pdo->prepare('SELECT status FROM articles WHERE id=?');
$q->execute([$first['article_id']]);ok($q->fetchColumn()==='draft','Imported article must remain draft.');
require dirname(__DIR__).'/app/layouts.php';
$pdo->prepare('UPDATE sites SET settings_json=? WHERE id=?')->execute([json_encode(['builder_enabled'=>true,'accent_color'=>'#325de6']),$sites[0]]);

// Nested page-builder structures must validate without breaking legacy templates.
$nested=[
 ['type'=>'row','columns'=>[
  ['type'=>'box','span'=>6,'blocks'=>[['type'=>'hero','title'=>'Bal oldali hír','text'=>'Sor első doboza']]],
  ['type'=>'box','span'=>6,'blocks'=>[['type'=>'text','title'=>'Jobb oldali rovat','text'=>'Sor második doboza']]]
 ]],
 ['type'=>'row','columns'=>[['type'=>'box','span'=>12,'blocks'=>[['type'=>'latest','title'=>'Friss cikkek','limit'=>5,'columns'=>2]]]]]
];
$nestedClean=json_decode(cms_layout_validate(json_encode($nested,JSON_UNESCAPED_UNICODE),'home'),true);
ok(count($nestedClean)===2&&count($nestedClean[0]['columns'])===2&&$nestedClean[0]['columns'][0]['span']===6,'Nested row and two boxes should be stored intact.');
$invalid=$nested;$invalid[0]['columns'][1]['span']=13;
try{cms_layout_validate(json_encode($invalid),'home');throw new RuntimeException('Oversized box was accepted.');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'szélessége'),'Builder must reject invalid box spans.');}

$overfull=$nested;$overfull[0]['columns'][1]['span']=8;
try{cms_layout_validate(json_encode($overfull),'home');throw new RuntimeException('Overfull row was accepted.');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'összes szélessége'),'Combined row width must never exceed 12 columns.');}

$invalid=$nested;$invalid[1]['columns'][0]['blocks'][0]['type']='article';
try{cms_layout_validate(json_encode($invalid),'home');throw new RuntimeException('Article slot was accepted on homepage.');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'cikkoldalon'),'Nested blocks must retain template content restrictions.');}
ok(count(json_decode(cms_layout_validate(json_encode(cms_default_layout('home')),'home'),true))===2,'Old flat layouts remain valid.');

$initialLayout=cms_default_layout('home');
$initialJson=json_encode($initialLayout,JSON_UNESCAPED_UNICODE);
$templateId=cms_template_save($pdo,$editor,$sites[0],'home','save',$initialJson,0);
ok($templateId>0,'Builder should create a draft template.');
$q=$pdo->prepare('SELECT published_json,revision_number FROM site_templates WHERE id=?');$q->execute([$templateId]);$t=$q->fetch();
ok($t['published_json']===null&&(int)$t['revision_number']===1,'Draft save must not publish.');
$newJson=json_encode([['type'=>'text','title'=>'Változott címlap','text'=>'Saját blokkok','columns'=>1,'limit'=>10]],JSON_UNESCAPED_UNICODE);
cms_template_save($pdo,$editor,$sites[0],'home','publish',$newJson,1);
$q->execute([$templateId]);$t=$q->fetch();
ok((int)$t['revision_number']===2&&str_contains($t['published_json'],'Változott'),'Publish creates versioned live template.');
$r=$pdo->prepare('SELECT id FROM site_template_revisions WHERE template_id=? AND revision_number=1');
$r->execute([$templateId]);$firstRev=(int)$r->fetchColumn();
cms_template_save($pdo,$editor,$sites[0],'home','restore','[]',2,$firstRev);
$q=$pdo->prepare('SELECT draft_json,published_json,revision_number FROM site_templates WHERE id=?');$q->execute([$templateId]);$t=$q->fetch();
ok((int)$t['revision_number']===3&&str_contains($t['draft_json'],'Legfrissebb')&&str_contains($t['published_json'],'Változott'),'Restore creates a new draft version without overwriting published output.');
try{cms_template_save($pdo,$editor,$sites[0],'home','save',$newJson,2);throw new RuntimeException('Expected version conflict');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'időközben'),'Concurrent layout edits must reject outdated versions.');}
try{cms_template_save($pdo,$editor,$sites[1],'home','save',$initialJson,0);throw new RuntimeException('Expected disabled builder');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'kapcsolva'),'Site toggle must disable authoring without deleting templates.');}
cms_menu_replace($pdo,$editor,$sites[0],[['label'=>'Főoldal','url'=>'/'],['label'=>'Rovat','url'=>'/section/hirek']]);
$q=$pdo->prepare('SELECT COUNT(*) FROM site_menu_items WHERE site_id=?');$q->execute([$sites[0]]);
ok((int)$q->fetchColumn()===2,'Site navigation must persist ordered links.');
try{cms_menu_url('javascript:alert(1)');throw new RuntimeException('Expected unsafe URL rejection');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'menüpont'),'Javascript URLs must be forbidden.');}
$q=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,status,published_at) VALUES(?,?,?,'test-scheduled','scheduled',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))");
$q->execute([$sites[0],$uid['editor'],'Automatikusan időzített cikk']);
$scheduledId=(int)$pdo->lastInsertId();
$q=$pdo->prepare('SELECT status FROM articles WHERE id=?');$q->execute([$scheduledId]);
ok($q->fetchColumn()==='scheduled','Due scheduled article must stay invisible until worker runs.');
// Rich imported sample for the end-to-end visual-editor round-trip smoke test.
$fixture='<p>Ez a gazdag szerkesztő automatikus próbája: <strong>félkövér szöveg</strong> és egy <em>kiemelt rész</em>.</p>'
 .'<figure><img src="https://example.com/test.jpg" alt="Tesztfotó"><figcaption>Fotó: Tesztszerkesztőség</figcaption></figure>'
 .'<table><tbody><tr><td>Első cella</td><td>Második cella</td></tr></tbody></table>'
 .'<iframe src="https://www.youtube.com/embed/abcdefghij" title="Tesztvideó"></iframe>';
$fixtureJson=json_encode([['type'=>'richhtml','text'=>$fixture]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$q=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,status,content_json) VALUES(?,?,?,'gazdag-editor-ci','draft',?)");
$q->execute([$sites[0],$uid['editor'],'Szerkesztői gazdag tartalom próba',$fixtureJson]);
ok((int)$pdo->lastInsertId()>0,'Rich HTML sample article should be inserted for browser smoke tests.');
echo "PASS: $tests database integration assertions.\n";
