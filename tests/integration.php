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
require dirname(__DIR__).'/app/ad-external-import.php';
$tests=0;
function ok(bool $condition,string $message):void{global $tests;$tests++;if(!$condition)throw new RuntimeException($message);}
foreach(['schema','editor','content','site-permissions','ads','ads-advanced','ads-external','import','import-media','import-metrics','layouts','pages-readers'] as $schema){
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
// External inventory is inert until a source folder is explicitly mapped and enabled.
$example=[
 'rows'=>[
  '1'=>['node id'=>'1','parent id'=>'0','node name'=>'Root','node type'=>'FOLDER','node path'=>'Root','node active'=>'Y','node slave'=>'N','master code id'=>'','leaf code id'=>'','code'=>''],
  '10'=>['node id'=>'10','parent id'=>'1','node name'=>'Test Publisher','node type'=>'FOLDER','node path'=>'Root/Test Publisher','node active'=>'Y','node slave'=>'N','master code id'=>'','leaf code id'=>'','code'=>''],
  '11'=>['node id'=>'11','parent id'=>'10','node name'=>'Master','node type'=>'MASTER','node path'=>'Root/Test Publisher/Master','node active'=>'Y','node slave'=>'N','master code id'=>'Master.X','leaf code id'=>'','code'=>"ado.master({id:'Master.X',server:'hu.adocean.pl'})"],
  '12'=>['node id'=>'12','parent id'=>'11','node name'=>'Slave','node type'=>'LEAF','node path'=>'Root/Test Publisher/Master/Slave','node active'=>'Y','node slave'=>'Y','master code id'=>'Master.X','leaf code id'=>'slave-x','code'=>"ado.slave('slave-x',{myMaster:'Master.X'})"],
 ],
 'folders'=>['10'=>'Test Publisher'],'folder_for'=>['1'=>0,'10'=>10,'11'=>10,'12'=>10],
 'counts'=>['FOLDER'=>2,'MASTER'=>1,'LEAF'=>1]
];
$external=external_import($pdo,$example,'test-fixture.xlsx',hash('sha256','external-ad-fixture'),$uid['platform_admin']);
ok($external>0,'External import must create a snapshot.');
ok(external_import($pdo,$example,'test-fixture.xlsx',hash('sha256','external-ad-fixture'),$uid['platform_admin'])===$external,'Repeat import must be idempotent.');
$q=$pdo->prepare('SELECT id,enabled,site_id FROM ad_external_sites WHERE import_id=?');
$q->execute([$external]);$mapping=$q->fetch();
ok($mapping&&(int)$mapping['enabled']===0&&$mapping['site_id']===null,'Imported external inventory must be unmapped and disabled.');
$q=$pdo->prepare('SELECT COUNT(*) FROM ad_external_nodes WHERE import_id=?');
$q->execute([$external]);ok((int)$q->fetchColumn()===4,'External inventory must preserve every node.');
// AdOcean page blocks must be inert by default and bound to mapped sites.
require_once dirname(__DIR__).'/app/layouts.php';
require_once dirname(__DIR__).'/app/ad-external-placements.php';
$externalBlock=[
 'type'=>'adocean','title'=>'Címlapi hirdetési hely','external_import_id'=>$external,
 'external_folder_id'=>10,'external_master_id'=>11,'external_leaf_id'=>12
];
$pdo->prepare('UPDATE ad_external_sites SET site_id=? WHERE id=?')->execute([$sites[0],$mapping['id']]);
$options=cms_external_editor_options($pdo,$sites[0]);
ok(count($options)===1&&count($options[0]['masters'])===1&&count($options[0]['leaves'])===1,'Builder must show only site-owned AdOcean inventory.');
ok(cms_external_editor_options($pdo,$sites[1])===[],'Unrelated site must not see external inventory.');
$json=cms_layout_validate(json_encode([$externalBlock],JSON_THROW_ON_ERROR),'home');
$validatedBlock=json_decode($json,true)[0];
ok($validatedBlock['external_leaf_id']===12&&$validatedBlock['external_master_id']===11,'Layout roundtrip must preserve AdOcean IDs.');
cms_external_validate_template($pdo,$sites[0],$json);
$off=cms_external_page_slots($pdo,$sites[0],[$validatedBlock],true);
ok($off['groups']===[],'Disabled AdOcean source may not deliver a public position.');
$pdo->prepare('UPDATE ad_external_sites SET enabled=1 WHERE id=?')->execute([$mapping['id']]);
$on=cms_external_page_slots($pdo,$sites[0],[$validatedBlock],true);
ok(count($on['groups'])===1&&$on['groups'][0]['master_node']===11&&
 count($on['groups'][0]['positions'])===1&&$on['groups'][0]['positions'][0]['nodeId']===12,
 'Enabled site-owned MASTER/SLAVE block must resolve for published frontend.');
ok(cms_external_page_slots($pdo,$sites[1],[$validatedBlock],true)['groups']===[],'Other site must never serve AdOcean blocks.');
ok(cms_external_page_slots($pdo,$sites[0],[$validatedBlock],false)['groups']===[],'Global site ad-disable must suppress external positions.');
$wrong=$externalBlock;$wrong['external_master_id']=9999;
$threw=false;try{cms_external_validate_template($pdo,$sites[0],cms_layout_validate(json_encode([$wrong],JSON_THROW_ON_ERROR),'home'));}
catch(RuntimeException $e){$threw=true;}
ok($threw,'Nonmatching MASTER must be rejected during template save.');
$pdo->prepare('UPDATE ad_external_sites SET enabled=0 WHERE id=?')->execute([$mapping['id']]);
ok(cms_external_page_slots($pdo,$sites[0],[$validatedBlock],true)['groups']===[],
 'Revoking mapping must immediately stop previously published AdOcean placement.');
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
// Extended AdBrain-style placement rules and code creative records.
require_once dirname(__DIR__).'/app/ad-placements.php';
$pdo->prepare('INSERT INTO ad_zone_rules(zone_id,position_type,position_value,device_target,page_types,width,height) VALUES(?,?,?,?,?,?,?)')->execute([$zone,'paragraph',3,'all','article,section',300,250]);
$positions=cms_ad_placements($pdo,$sites[0],'article');
ok(count($positions)===1&&$positions[0]['position']==='paragraph'&&$positions[0]['value']===3,'Enabled article placements must resolve native rule.');
ok(cms_ad_placements($pdo,$sites[0],'home')===[],'Placement page-type targeting should exclude home.');
ok(cms_ad_placements($pdo,$sites[1],'article')===[],'Placements must not leak between websites.');
$pdo->prepare('INSERT INTO ad_code_creatives(creative_id,format,code_html) VALUES(?,?,?)')->execute([$creative,'code','<div id="test-ad"></div><script>void 0;</script>']);
$pdo->prepare('INSERT INTO ad_serves(token,site_id,zone_id,campaign_id,creative_id) VALUES(?,?,?,?,?)')->execute([str_repeat('a',32),$sites[0],$zone,$campaign,$creative]);
$pdo->prepare('UPDATE ad_serves SET rendered_at=UTC_TIMESTAMP(),viewable_at=UTC_TIMESTAMP() WHERE token=?')->execute([str_repeat('a',32)]);
$q=$pdo->prepare('SELECT COUNT(rendered_at) renders,COUNT(viewable_at) viewable FROM ad_serves WHERE site_id=?');
$q->execute([$sites[0]]);$values=$q->fetch();
ok((int)$values['renders']===1&&(int)$values['viewable']===1,'AdBrain delivery tracks render and viewable events distinctly.');
$q=$pdo->prepare('INSERT INTO cms_page_analytics_daily(site_id,event_date,page_path,page_kind,device,pageviews) VALUES(?,UTC_DATE(),?,?,?,1) ON DUPLICATE KEY UPDATE pageviews=pageviews+1');
$q->execute([$sites[0],'/public.php?site=test-0&home=home','home','desktop']);$q->execute([$sites[0],'/public.php?site=test-0&home=home','home','desktop']);
$q=$pdo->prepare('SELECT pageviews FROM cms_page_analytics_daily WHERE site_id=?');$q->execute([$sites[0]]);
ok((int)$q->fetchColumn()===2,'Daily first-party pageview aggregates must increment atomically.');

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
$richArticleId=(int)$pdo->lastInsertId();
$sourcePage='https://source-example.invalid/story/important';
$sourceDoc='<html><head><meta property="og:site_name" content="Forrás Napilap"><style>body{color:#121a34}p{font-size:23px;line-height:1.6}.article-body h2{color:#2a619a}</style></head><body><header><a href="/"><img class="site-logo" src="/logo.png" alt="Forrás Napilap"></a><nav aria-label="primary"><a href="/belfold">Belföld</a><a href="/sport">Sport</a></nav></header><article><p>Mintaforrás</p></article><footer><nav><a href="/impresszum">Impresszum</a></nav><small>© 2026 Forrás Napilap</small></footer></body></html>';
require_once dirname(__DIR__).'/app/import-source-style.php';
$sourceProfile=cms_source_style_collect($sites[0],$sourceDoc,$sourcePage);
ok(str_contains((string)$sourceProfile['css'],'font-size:23px'),'Imported source style snapshot must preserve safe typography.');
ok(count($sourceProfile['site_design']['header_links']??[])===2&&($sourceProfile['site_design']['name']??'')==='Forrás Napilap','Imported site shell includes publisher identity and main menu.');
$insertMap=$pdo->prepare('INSERT INTO import_article_map(site_id,source_url,source_url_hash,article_id,source_hash) VALUES(?,?,?,?,?)');
$insertMap->execute([$sites[0],$sourcePage,hash('sha256',$sourcePage),$richArticleId,hash('sha256',$sourceDoc)]);
ok(cms_source_style_read($sites[0],$sourcePage)!==null,'Saved profile should be discoverable from mapped article origin.');
$reviewUrl='https://curated-source.invalid/story';
$review= cms_source_style_collect($sites[1],$sourceDoc,$reviewUrl);
$review['site_design']['name']='Jóváhagyott forráswebhely';
$review['site_design_reviewed']=true;$review['site_design_reviewed_at']=gmdate('c');
cms_source_style_write($sites[1],$reviewUrl,$review);
$refreshed=cms_source_style_collect($sites[1],$sourceDoc,$reviewUrl,true);
ok(($refreshed['site_design']['name']??'')==='Jóváhagyott forráswebhely','Reimport must not overwrite approved site shell overrides.');
ok(!empty($refreshed['site_design_reviewed']),'A refreshed source profile retains its approval flag.');
// Collected appearances form a reusable, assignable site-level template library.
require_once dirname(__DIR__).'/app/site-design-templates.php';
$templateId=cms_design_template_id($sites[0],'source-example.invalid');
ok(cms_design_template_read($templateId)!==null,'A collected style must be addressable as a reusable appearance template.');
$availableTemplates=cms_design_templates_list();
ok(count(array_filter($availableTemplates,static fn($t)=>$t['id']===$templateId))===1,'Template library must list imported source styles.');
$assignedSettings=cms_validate_site_settings(['source_template_id'=>$templateId],['publisher_name'=>'Kiadó', 'newsletter_enabled'=>true]);
ok($assignedSettings['source_template_id']===$templateId&&$assignedSettings['publisher_name']==='','Site settings must validate a selected template alongside existing fields.');
$otherSiteSettings=['settings_json'=>json_encode(['source_template_id'=>$templateId],JSON_UNESCAPED_UNICODE)];
ok((cms_design_template_for_site($otherSiteSettings)['host']??'')==='source-example.invalid','A template imported for one site must be reusable on another site.');
ok(cms_design_template_validate_selection('')==='','A template assignment must be removable.');
try{cms_design_template_validate_selection(str_repeat('f',64));throw new RuntimeException('Missing template accepted.');}
catch(RuntimeException $e){ok(str_contains($e->getMessage(),'nem található'),'Missing or forged template references must be rejected.');}




// The imported design is only presentation: navigation and search stay native.
require_once dirname(__DIR__).'/app/native-site-chrome.php';
$q=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,`lead`,content_json,status,published_at) VALUES(?,?,?,?,?,?,'published',UTC_TIMESTAMP())");
$q->execute([$sites[0],$uid['editor'],'Natív kereshető cikk','nativ-kereses-publikus','Tesztszöveg találattal','[]']);
$q->execute([$sites[1],$uid['editor'],'Natív másik webhely cikke','nativ-kereses-masik','Tesztszöveg találattal','[]']);
$draft=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,`lead`,content_json,status) VALUES(?,?,?,?,?,?,'draft')");
$draft->execute([$sites[0],$uid['editor'],'Natív piszkozat kereshető','nativ-kereses-piszkozat','Tesztszöveg találattal','[]']);
$results=cms_native_search($pdo,$sites[0],'Natív',false);
ok(count($results)===1&&$results[0]['slug']==='nativ-kereses-publikus','Public site search must never include drafts or other sites.');
$previewResults=cms_native_search($pdo,$sites[0],'Natív',true);
ok(count($previewResults)===2,'Editor preview search may include only its own site drafts.');
ok(cms_native_search($pdo,$sites[0],'%',false)===[],'Percent wildcard must be escaped as literal text.');
$pdo->prepare('INSERT INTO site_menu_items(site_id,label,url,position,active) VALUES(?,?,?,?,?)')->execute([$sites[0],'Belföld','/section/belfold',0,1]);
$pdo->prepare('INSERT INTO site_menu_items(site_id,label,url,position,active) VALUES(?,?,?,?,?)')->execute([$sites[0],'Rejtett','/section/rejtett',1,0]);
$menuNative=cms_native_site_menu($pdo,$sites[0]);
ok(count($menuNative)===1&&$menuNative[0]['label']==='Belföld','The native menu must use only active items from the menu editor.');
ok(str_contains(cms_native_href($menuNative[0]['url'],'test-0',true),'preview=1&section=belfold'),'Native navigation must preserve editor-preview access.');
ok(cms_native_href('javascript:alert(1)','test-0')===cms_native_site_url('test-0'),'Unsafe imported navigation must never become an executable link.');

// Source-host article reuse is opt-in and must preserve original ownership.
require_once dirname(__DIR__).'/app/site-import-assignment.php';
$orphanImport=$pdo->prepare("INSERT INTO articles(site_id,author_id,title,slug,status,content_json) VALUES(?,?,?,'forrasbol-importalt-cikk','draft','[]')");
$orphanImport->execute([$sites[0],$uid['editor'],'Forrásdomainről importált próbacikk']);
$orphanId=(int)$pdo->lastInsertId();
$sourceLink=$pdo->prepare('INSERT INTO import_article_map(site_id,source_url,source_url_hash,article_id,source_hash) VALUES(?,?,?,?,?)');
$originalUrl='https://test1.invalid/belfold/importalt-proba';
$sourceLink->execute([$sites[0],$originalUrl,hash('sha256',$originalUrl),$orphanId,hash('sha256','demo')]);
$candidates=cms_site_import_candidates($pdo,$sites[1],'test1.invalid');
ok(count($candidates)===1&&(int)$candidates[0]['id']===$orphanId,'Site admin should discover previously imported articles from an exact matching hostname.');
ok(cms_site_import_candidates($pdo,$sites[2],'test2.invalid')===[],'Other source hostnames cannot be suggested for unrelated websites.');
ok(!in_array($sites[1],article_site_ids($pdo,['id'=>$orphanId,'site_id'=>$sites[0]]),true),'Discovering eligible imports must not silently associate them.');
$connected=cms_site_link_import_candidates($pdo,['id'=>$sites[1],'domain'=>'test1.invalid'],$uid['platform_admin']);
ok($connected===1&&in_array($sites[1],article_site_ids($pdo,['id'=>$orphanId,'site_id'=>$sites[0]]),true),'Explicit assignment should add a second site without moving the original article.');
ok(cms_site_link_import_candidates($pdo,['id'=>$sites[1],'domain'=>'test1.invalid'],$uid['platform_admin'])===0,'Repeated association must be idempotent.');


// Source appearance must initialize six independently editable page families.
require_once dirname(__DIR__).'/app/template-family.php';
$familySource=['host'=>'magyarnemzet.hu','source_url'=>'https://magyarnemzet.hu/belfold/test'];
$familyMade=cms_template_family_seed($pdo,$sites[2],$uid['platform_admin'],$familySource,true);
ok(count($familyMade)===6,'A selected source appearance must create six unique page families.');
$familyQuery=$pdo->prepare('SELECT template_key,draft_json FROM site_templates WHERE site_id=?');
$familyQuery->execute([$sites[2]]);$families=$familyQuery->fetchAll();
ok(count($families)===6,'All homepage/category/tag/article/page/login layouts should have their own record.');
$homeDef=json_decode((string)array_values(array_filter($families,static fn($f)=>$f['template_key']==='home'))[0]['draft_json'],true);
ok(count($homeDef)===3&&($homeDef[1]['offset']??-1)===5,'Home blocks must render distinct article slices.');
$pageCheck=$pdo->prepare('SELECT title,status FROM site_pages WHERE site_id=? AND slug=?');
$pageCheck->execute([$sites[2],'impresszum']);$pageSeed=$pageCheck->fetch();
ok($pageSeed&&$pageSeed['status']==='draft','Impresszum must be an editable, non-public draft until approved.');
ok(cms_template_family_seed($pdo,$sites[2],$uid['platform_admin'],$familySource,true)===[],'Running template setup again must not destroy edits.');
$termCheck=$pdo->prepare('SELECT type FROM taxonomy_terms WHERE site_id=? AND slug=?');
$termCheck->execute([$sites[2],'magyar-peter']);
ok($termCheck->fetchColumn()==='tag','Source category/tag samples must become native taxonomy records.');
$readerHash=password_hash('VeryStrongPassword123!',PASSWORD_DEFAULT);
$readerInsert=$pdo->prepare('INSERT INTO site_readers(site_id,email,display_name,password_hash) VALUES(?,?,?,?)');
$readerInsert->execute([$sites[2],'test-reader@example.invalid','Olvasó teszt',$readerHash]);
ok(password_verify('VeryStrongPassword123!',$readerHash),'Reader login stores a verifiable, non-plaintext password.');
$pdo->prepare('UPDATE sites SET settings_json=? WHERE id=?')->execute([json_encode(['reader_accounts_enabled'=>true]),$sites[2]]);


// Media import queue, provenance and article-specific image captions.
require_once dirname(__DIR__).'/app/import-media.php';
$pdo->prepare('INSERT INTO media_assets(site_id,original_name,storage_path,mime_type,size_bytes,alt_text) VALUES(?,?,?,?,?,?)')
 ->execute([$sites[0],'source-image.webp','fixture.webp','image/webp',1024,'Kép alternatív szövege']);
$importMediaId=(int)$pdo->lastInsertId();
$fixtureImageUrl='https://example.org/media/fixture.webp';
$pdo->prepare('INSERT INTO media_import_sources(media_id,site_id,source_url,source_url_hash,source_host,source_article_url,width_px,height_px,sha256) VALUES(?,?,?,?,?,?,?,?,?)')
 ->execute([$importMediaId,$sites[0],$fixtureImageUrl,hash('sha256',$fixtureImageUrl),'example.org','https://example.org/a/123',800,600,str_repeat('a',64)]);
$pdo->prepare('INSERT INTO article_media_uses(site_id,article_id,media_id,role,original_url,alt_text,caption,photo_credit,position_index) VALUES(?,?,?,?,?,?,?,?,?)')
 ->execute([$sites[0],$orphanId,$importMediaId,'inline',$fixtureImageUrl,'Képleírás','Az esemény helyszíne','Fotós neve',1]);
$q=$pdo->prepare('SELECT COUNT(*) FROM article_media_uses WHERE article_id=? AND caption LIKE ?');
$q->execute([$orphanId,'%esemény%']);
ok((int)$q->fetchColumn()===1,'Imported image captions must be linked to the exact article.');
$fixtureBlocks=json_encode([['type'=>'richhtml','text'=>'<figure><img src="'.htmlspecialchars($fixtureImageUrl,ENT_QUOTES).'" alt="A tesztfotó"><figcaption>Képaláírás, Fotó: Stúdió</figcaption></figure>']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE articles SET content_json=? WHERE id=?')->execute([$fixtureBlocks,$orphanId]);
cms_import_media_enqueue($pdo,$sites[0],$orphanId);
cms_import_media_enqueue($pdo,$sites[0],$orphanId);
$q=$pdo->prepare('SELECT COUNT(*),MAX(next_offset) FROM media_import_queue WHERE site_id=? AND article_id=?');
$q->execute([$sites[0],$orphanId]);$queueInfo=$q->fetch(PDO::FETCH_NUM);
ok((int)$queueInfo[0]===1&&(int)$queueInfo[1]===0,'Media queue should be repeatable and resumable without duplicate jobs.');
ok(cms_import_media_work_once($pdo),'Media worker should consume pending tasks.');
$q=$pdo->prepare('SELECT status,found_count,imported_count,failed_count FROM media_import_queue WHERE site_id=? AND article_id=?');
$q->execute([$sites[0],$orphanId]);$mediaDone=$q->fetch();
ok($mediaDone['status']==='done'&&(int)$mediaDone['found_count']===1&&(int)$mediaDone['imported_count']===1&&(int)$mediaDone['failed_count']===0,'Image worker should reuse an already-downloaded file without fetching it again.');
$q=$pdo->prepare('SELECT caption,alt_text FROM article_media_uses WHERE article_id=? AND media_id=? LIMIT 1');
$q->execute([$orphanId,$importMediaId]);$mediaUse=$q->fetch();
ok(str_contains((string)$mediaUse['caption'],'Képaláírás')&&$mediaUse['alt_text']==='A tesztfotó','Image worker must update per-article caption and alt independently of the source asset.');

$q=$pdo->prepare('SELECT rights_status FROM media_import_sources WHERE media_id=?');$q->execute([$importMediaId]);
ok($q->fetchColumn()==='unknown','Imported copyrighted images must default to rights unverified.');
$pdo->prepare('DELETE FROM media_import_queue WHERE site_id=? AND article_id=?')->execute([$sites[0],$orphanId]);
$pdo->prepare('DELETE FROM media_assets WHERE id=?')->execute([$importMediaId]); // Do not pollute browser upload tests with fixture-only media.

echo "PASS: $tests database integration assertions.\n";
