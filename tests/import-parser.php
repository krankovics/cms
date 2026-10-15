<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-engine.php';
function ensure(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$paragraph1=str_repeat('Ez az első valódi híranyag jól olvasható részleteket tartalmaz. ',3);
$paragraph2=str_repeat('Ez a második bekezdés tényszerű kiegészítésekkel folytatja a történetet. ',3);
$html='<!doctype html><html lang="hu"><head><title>Másodlagos cím</title><meta property="og:title" content="Valódi mintacikk"><meta name="description" content="Rövid lead."></head><body><nav>Menü</nav><article><h1>Valódi mintacikk</h1><p>'.$paragraph1.'</p><h2>Alcím</h2><p>'.$paragraph2.'</p><blockquote>Fontos idézet</blockquote><script>alert(1)</script></article></body></html>';
$result=imp_extract($html,'https://example.org/cikk');
ensure($result['title']==='Valódi mintacikk','OG title should override doc title.');
ensure($result['lead']==='Rövid lead.','Meta description should be imported.');
$ogFixture=str_replace('<meta name="description" content="Rövid lead.">','<meta name="description" content="Rövid lead."><meta property="og:image" content="/assets/cover.jpg">',$html);
$ogResult=imp_extract($ogFixture,'https://example.org/story');
ensure($ogResult['og_image_url']==='https://example.org/assets/cover.jpg','OG image must be resolved as a featured image.');

ensure(count($result['blocks'])===4,'Expected four editable blocks.');
ensure($result['blocks'][1]['type']==='heading','Section header should be a heading block.');
ensure(!str_contains(json_encode($result['blocks']),'alert(1)'),'Scripts must not be imported.');

// Related-news card before the real story must never win over actual article content.
$fullPage='<!doctype html><html><head><meta property="og:title" content="Árverési hír"></head><body><main>'
 .'<article class="related"><h2>További Kultúra híreink</h2><ul><li>Másik hír</li></ul></article>'
 .'<article><h1>Árverési hír</h1><div class="article-body"><p>'.$paragraph1.'</p><p>'.$paragraph2.'</p>'
 .'<section class="related-news"><h2>További Kultúra híreink</h2><ul><li>Kapcsolódó ajánlat</li></ul></section>'
 .'</div></article></main></body></html>';
$clean=imp_extract($fullPage,'https://example.org/story');
ensure(count($clean['blocks'])===2,'Only the two narrative paragraphs should be retained.');
ensure(!str_contains(json_encode($clean['blocks']),'Kapcsolódó ajánlat'),'Related articles must never contaminate the body.');
$falsePositive='<!doctype html><html><head><meta property="og:title" content="Közérdekű esemény"></head><body>'
 .'<article><h2>További Kultúra híreink</h2><ul><li>Másik tartalom</li></ul></article></body></html>';
try{imp_extract($falsePositive,'https://example.org/empty');throw new RuntimeException('Related-only stub was saved as a valid article.');}
catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'cikktörzs'),'Thin/related-only pages must fail visibly.');}

// Some publishers expose the actual article text only in schema.org JSON-LD.
// Accept it when it contains a complete story; never manufacture missing text.
$fullText=str_repeat('Ez egy tényszerű, teljes cikkbekezdés több ellenőrizhető állítással. A következő mondat folytatja az ismertetést. ',7);
$jsonLd=json_encode(['@context'=>'https://schema.org','@type'=>'NewsArticle','headline'=>'Részletes szerkesztőségi hír','articleBody'=>$fullText],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$structuredPage='<!doctype html><html><head><title>Részletes szerkesztőségi hír</title><script type="application/ld+json">'.$jsonLd.'</script></head><body><main><h1>Részletes szerkesztőségi hír</h1><aside>Kapcsolódó cikkek</aside></main></body></html>';
$fromSchema=imp_extract($structuredPage,'https://example.org/structured-story');
ensure(count($fromSchema['blocks'])===1&&mb_strlen($fromSchema['blocks'][0]['text'])>450,'Full articleBody may rescue a source without narrative DOM paragraphs.');
ensure($fromSchema['blocks'][0]['type']==='paragraph','JSON-LD fallback must produce editable plain paragraphs.');
$briefSchema=json_encode(['@type'=>'NewsArticle','headline'=>'Csak lead van','articleBody'=>'Rövid összefoglaló, de nem teljes cikk.'],JSON_THROW_ON_ERROR);
$briefPage='<!doctype html><html><head><title>Csak lead van</title><script type="application/ld+json">'.$briefSchema.'</script></head><body><main><h1>Csak lead van</h1></main></body></html>';
try{imp_extract($briefPage,'https://example.org/brief');throw new RuntimeException('Summary-only metadata was incorrectly imported.');}
catch(ImportContentRejected $e){ensure(str_contains($e->getMessage(),'cikktörzs'),'Summary-only pages must be classified as source rejections.');}
$wrongSchema=json_encode(['@type'=>'NewsArticle','headline'=>'Idegen cikk címe','articleBody'=>$fullText],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$wrongPage='<!doctype html><html><head><title>Másik cikk</title><script type="application/ld+json">'.$wrongSchema.'</script></head><body></body></html>';
try{imp_extract($wrongPage,'https://example.org/other');throw new RuntimeException('Unrelated articleBody was wrongly selected.');}
catch(ImportContentRejected $e){ensure(true,'Wrong headline must not become an imported story.');}

// Publisher-specific Angular state embeds the *real* article while DOM has only related cards.
$storyTitle='Gyerekeket nyomott agyon a leszakadt tetőzet';
$hydratedValue='<p>'.$paragraph1.'</p><h2>A kutatás újabb részletei</h2><p>'.$paragraph2.'</p>';
$articleState=['9234'=>['b'=>['data'=>[
 'title'=>$storyTitle,'slug'=>'gyerekeket-nyomott-agyon-a-leszakadt-tetozet',
 'canonicalUrl'=>'https://example.org/kulfold/2026/10/gyerekeket-nyomott-agyon-a-leszakadt-tetozet',
 'isPaywalled'=>'0',
 'body'=>[['type'=>'text','details'=>[['type'=>'html','key'=>'value','value'=>$hydratedValue]]]]
]]]];
$encodedState=json_encode($articleState,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_THROW_ON_ERROR);
$hydrationPage='<!doctype html><html><head><title>'.$storyTitle.'</title></head><body><main>'
 .'<h1>'.$storyTitle.'</h1><article class="related">Kapcsolódó hírek</article>'
 .'<script id="ng-state" type="application/json">'.$encodedState.'</script></main></body></html>';
$hydrated=imp_extract($hydrationPage,'https://example.org/kulfold/2026/10/gyerekeket-nyomott-agyon-a-leszakadt-tetozet');
ensure(count($hydrated['blocks'])===3,'Hydrated article must yield actual paragraphs and heading.');
ensure($hydrated['blocks'][1]['type']==='heading','Hydrated headings must stay editable.');
ensure($hydrated['blocks'][2]['text']===trim($paragraph2),'Hydrated article must not be replaced by a related card.');
$misidentified=$articleState;
$misidentified['9234']['b']['data']['slug']='not-the-story';
$badPage=str_replace($encodedState,json_encode($misidentified,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_THROW_ON_ERROR),$hydrationPage);
try{imp_extract($badPage,'https://example.org/kulfold/2026/10/gyerekeket-nyomott-agyon-a-leszakadt-tetozet');throw new RuntimeException('Wrong slug was trusted.');}
catch(ImportContentRejected $e){ensure(true,'Hydration records for other articles must be ignored.');}
$paywall=$articleState;
$paywall['9234']['b']['data']['isPaywalled']='1';
$payPage=str_replace($encodedState,json_encode($paywall,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_THROW_ON_ERROR),$hydrationPage);
try{imp_extract($payPage,'https://example.org/kulfold/2026/10/gyerekeket-nyomott-agyon-a-leszakadt-tetozet');throw new RuntimeException('Protected article was read.');}
catch(ImportContentRejected $e){ensure(true,'Restricted hydrated content must not be processed.');}

$rss='<?xml version="1.0"?><rss version="2.0"><channel><item><link>https://example.org/a</link></item><item><link>https://example.org/b</link></item></channel></rss>';
$feed=imp_discover($rss,'https://example.org/rss.xml');
ensure(count($feed['urls'])===2,'RSS links not discovered.');
$xml='<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.org/a</loc></url></urlset>';
$sm=imp_discover($xml,'https://example.org/sitemap.xml');
ensure($sm['urls']===['https://example.org/a'],'Sitemap URLs must be discovered.');

ensure(!imp_is_article_candidate_url('https://magyarnemzet.hu/'),'Home page must not be a draft article.');
ensure(!imp_is_article_candidate_url('https://magyarnemzet.hu/home_page_sitemap.xml'),'Sitemap XML must not be an article.');
ensure(!imp_is_article_candidate_url('https://example.org/feed'),'Feeds must not be articles.');
ensure(imp_is_article_candidate_url('https://magyarnemzet.hu/kultura/2023/11/durvan-megemelkedett-a-zsolnay-keramiak-erteke-video'),'Nested story slug is a valid article URL.');
ensure(imp_is_article_candidate_url('https://example.org/egyszavas-cikk'),'Single-segment story slugs remain allowed.');
$datedIndex='<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
 .'<sitemap><loc>https://example.org/199805_sitemap.xml</loc></sitemap>'
 .'<sitemap><loc>https://example.org/202410_sitemap.xml</loc></sitemap>'
 .'<sitemap><loc>https://example.org/202601_sitemap.xml</loc></sitemap>'
 .'</sitemapindex>';
$recent=imp_discover($datedIndex,'https://example.org/sitemapindex.xml',100);
ensure($recent['urls']===['https://example.org/202601_sitemap.xml','https://example.org/202410_sitemap.xml','https://example.org/199805_sitemap.xml'],'Newest month-dated sitemap should be discovered first.');
$lastmodIndex='<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
 .'<sitemap><loc>https://example.org/old.xml</loc><lastmod>2024-01-01</lastmod></sitemap>'
 .'<sitemap><loc>https://example.org/new.xml</loc><lastmod>2026-10-15</lastmod></sitemap>'
 .'</sitemapindex>';
ensure(imp_discover($lastmodIndex,'https://example.org/sitemapindex.xml')['urls'][0]==='https://example.org/new.xml','lastmod should prioritize latest sitemap.');


$withImages='<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"><url><loc>https://example.org/article</loc><image:image><image:loc>https://example.org/image.jpg</image:loc></image:image></url></urlset>';
$links=imp_discover($withImages,'https://example.org/sitemap.xml');
ensure($links['urls']===['https://example.org/article'],'Nested media locations are not article links.');
$largeSitemap='<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
for($i=0;$i<50000;$i++)$largeSitemap.='<url><loc>https://example.org/news/'.str_pad((string)$i,7,'0',STR_PAD_LEFT).'</loc><lastmod>2026-10-15</lastmod></url>';
$largeSitemap.='</urlset>';
ensure(strlen($largeSitemap)>3145728,'Streaming XML fixture should resemble a large sitemap.');
$parsed=imp_discover($largeSitemap,'https://example.org/sitemap.xml',100);
ensure(count($parsed['urls'])===100,'Streaming sitemap reader must obey per-pass URL limit.');
unset($largeSitemap);

try{imp_validate_url('http://127.0.0.1/private');throw new RuntimeException('SSRF localhost was accepted.');}
catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'Privát')||str_contains($e->getMessage(),'Nem publikus'),'Reject local IP targets.');}
$robots="User-agent: *\nDisallow: /private/\nAllow: /private/help\nDisallow: /*.pdf$\n\nUser-agent: comesyImporter\nDisallow: /archive/\nAllow: /archive/public/\n";
ensure(imp_robots_permits($robots,'/private/page'),'Named crawler group must override generic group.');
ensure(!imp_robots_permits($robots,'/archive/members'),'Dedicated crawler exclusion must apply.');
ensure(imp_robots_permits($robots,'/archive/public/article'),'More-specific Allow must win over Disallow.');
ensure(!imp_robots_permits("User-agent: *\nDisallow: /*.pdf$\n",'/story.pdf'),'Robots wildcard and trailing anchor must match.');
ensure(imp_robots_permits("User-agent: *\nDisallow: /draft/\nAllow: /draft/public/\n",'/draft/public/a'),'Longest Allow rule must win.');
ensure(imp_robots_permits("User-agent: *\nDisallow:\n",'/anything'),'Empty disallow must allow crawling.');
echo "PASS: narrative-only extraction, related-card rejection, streaming large XML discovery and localhost rejection.\n";
