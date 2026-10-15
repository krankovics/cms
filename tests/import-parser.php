<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-engine.php';
function ensure(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

require_once dirname(__DIR__).'/app/import-source-style.php';

require_once dirname(__DIR__).'/app/import-site-design.php';
$siteShell='<html><head><meta property="og:site_name" content="Hírportál Próba"></head><body>'
 .'<header><a href="/"><img class="site-logo" src="/assets/logo.svg" alt="Hírportál Próba logo"></a>'
 .'<nav aria-label="primary"><a href="/belfold">Belföld</a><a href="/kulfold">Külföld</a><a href="/gazdasag">Gazdaság</a>'
 .'<a href="javascript:alert(1)">Rossz</a><a href="https://tracker.invalid/click">Követő</a></nav></header>'
 .'<article><img src="/cover.jpg" alt="Borító"><p>Tartalom</p></article>'
 .'<footer><nav><a href="/impresszum">Impresszum</a><a href="/adatvedelem">Adatvédelem</a></nav><small>© 2026 Hírportál Próba</small></footer>'
 .'<script>alert(1)</script></body></html>';
$shell=cms_source_design_extract($siteShell,'https://example.org/story');
ensure($shell['name']==='Hírportál Próba','Site identity must come from og:site_name.');
ensure($shell['logo_url']==='https://example.org/assets/logo.svg','Header logo must be extracted, not article cover.');
ensure(count($shell['header_links'])===3&&$shell['header_links'][0]['label']==='Belföld','Main navigation should retain safe titles and order.');
ensure($shell['header_links'][0]['url']==='https://example.org/belfold','Menu links must be resolved against original host.');
ensure(count($shell['footer_links'])===2&&str_contains($shell['copyright'],'© 2026'),'Footer legal/navigation metadata should be retained.');
ensure(!str_contains(json_encode($shell),'tracker.invalid')&&!str_contains(json_encode($shell),'javascript:'),'Untrusted navigation URLs must be dropped.');

$styleSource='<html><head><style>body{font-family:Georgia,serif;color:#18243a;background-color:#ffffff}'
 .'.article-body p{font-size:18px;line-height:1.7;color:#123456}.article-body h2{font-weight:700}'
 .'.malicious{background-image:url(https://tracker.example/t)}'
 .'p{background-image:url(https://tracker.example/t);font-size:19px}'
 .'script{display:block}</style>'
 .'<link rel="stylesheet" href="/assets/article.css"><link rel="stylesheet" href="https://other.example/style.css"></head></html>';
$styleCss=cms_source_style_inline($styleSource);
ensure(str_contains($styleCss,'.cms-source-article p')&&str_contains($styleCss,'font-size:18px'),'Source paragraph typography should map to isolated preview class.');
ensure(str_contains($styleCss,'font-family:Georgia,serif'),'Source font family should be retained.');
ensure(!str_contains($styleCss,'tracker.example')&&!str_contains($styleCss,'background-image')&&!str_contains($styleCss,'display:block'),'Unsafe or irrelevant CSS declarations must be excluded.');
$styleLinks=cms_source_style_links($styleSource,'https://example.org/articles/post');
ensure(count($styleLinks)===2&&$styleLinks[0]==='https://example.org/assets/article.css'&&$styleLinks[1]==='https://other.example/style.css','Source CSS and explicit HTTPS CDN styles should be discovered.');
ensure(cms_source_style_path(1,'https://example.org/story')!==cms_source_style_path(2,'https://example.org/story'),'Source designs must be scoped to destination site.');
ensure(cms_source_style_path(1,'https://example.org/story')===cms_source_style_path(1,'https://example.org/another'),'Source designs must be shared centrally by origin.');
$danger=cms_source_style_extract_css('p{color:red;background-image:url("https://tracker.invalid/x")}@import url(https://bad.invalid/x);body{color:#343434}');
ensure(!str_contains($danger,'bad.invalid')&&!str_contains($danger,'tracker.invalid'),'External stylesheet imports and tracking images must be removed.');

$paragraph1=str_repeat('Ez az első valódi híranyag jól olvasható részleteket tartalmaz. ',3);
$paragraph2=str_repeat('Ez a második bekezdés tényszerű kiegészítésekkel folytatja a történetet. ',3);
$html='<!doctype html><html lang="hu"><head><title>Másodlagos cím</title><meta property="og:title" content="Valódi mintacikk"><meta name="description" content="Rövid lead."></head><body><nav>Menü</nav><article><h1>Valódi mintacikk</h1><p>'.$paragraph1.'</p><h2>Alcím</h2><p>'.$paragraph2.'</p><blockquote>Fontos idézet</blockquote><script>alert(1)</script></article></body></html>';
$result=imp_extract($html,'https://example.org/cikk');
ensure($result['title']==='Valódi mintacikk','OG title should override doc title.');
ensure($result['lead']==='Rövid lead.','Meta description should be imported.');
$ogFixture=str_replace('<meta name="description" content="Rövid lead.">','<meta name="description" content="Rövid lead."><meta property="og:image" content="/assets/cover.jpg">',$html);
$ogResult=imp_extract($ogFixture,'https://example.org/story');
ensure($ogResult['og_image_url']==='https://example.org/assets/cover.jpg','OG image must be resolved as a featured image.');

ensure(count($result['blocks'])===1&&$result['blocks'][0]['type']==='richhtml','Narrative with formatting should stay in one round-trip-safe HTML block.');
ensure(str_contains($result['blocks'][0]['text'],'<h2>Alcím</h2>'),'Section heading must keep semantic structure.');
ensure(!str_contains(json_encode($result['blocks']),'alert(1)'),'Scripts must not be imported.');

// Full fidelity fixture: inline emphasis, figure credits, allowed players and tables.
$mediaHtml='<!doctype html><html><head><title>Gazdag cikk</title></head><body><article class="article-body">'
 .'<p>'.$paragraph1.' <strong>Félkövér</strong> és <em>dőlt</em>, <mark>kiemelt</mark> szöveg <a href="/reszlet">hivatkozással</a>.</p>'
 .'<figure><img data-src="/photos/report.jpg" alt="Képleírás" onerror="alert(1)"><figcaption>Fotó: Szerkesztőség</figcaption></figure>'
 .'<p>'.$paragraph2.'</p>'
 .'<table><caption>Összesítés</caption><thead><tr><th scope="col" colspan="2">Megnevezés</th></tr></thead><tbody><tr><td>A</td><td>123</td></tr></tbody></table>'
 .'<iframe src="https://www.youtube.com/embed/abcdefghij" title="YouTube videó"></iframe>'
 .'<iframe src="https://evil.example.net/embed/99"></iframe>'
 .'<iframe src="javascript:alert(1)"></iframe><script>alert(2)</script>'
 .'</article></body></html>';
$media=imp_extract($mediaHtml,'https://example.org/articles/story');
$rich=(string)$media['blocks'][0]['text'];
ensure(($media['blocks'][0]['type']??'')==='richhtml','Formatted source must remain a rich HTML body.');
foreach(['<strong>Félkövér</strong>','<em>dőlt</em>','<mark>kiemelt</mark>','<figcaption>Fotó: Szerkesztőség</figcaption>',
 '<table>','<caption>Összesítés</caption>','<th colspan="2" scope="col">Megnevezés</th>',
 'https://www.youtube.com/embed/abcdefghij','https://example.org/photos/report.jpg',
 'https://example.org/reszlet'] as $required){
 ensure(str_contains($rich,$required),'Required rich element lost: '.$required);
}
ensure(!str_contains($rich,'onerror')&&!str_contains($rich,'<script')&&!str_contains($rich,'evil.example.net')&&!str_contains($rich,'javascript:'),'Dangerous markup must not survive source import.');
ensure(strpos($rich,'Félkövér')<strpos($rich,'Fotó:')&&strpos($rich,'Fotó:')<strpos($rich,'Összesítés')&&strpos($rich,'Összesítés')<strpos($rich,'youtube.com'),'Media and text must retain editorial order.');
ensure(cms_rich_sanitize_html($rich)===$rich,'Editor/server sanitizing must be idempotent (lossless roundtrip).');
$assetRefs=cms_rich_references($rich);
ensure($assetRefs['images']===['https://example.org/photos/report.jpg'],'Inline source images must be included in media provenance.');
ensure(in_array('https://www.youtube.com/embed/abcdefghij',$assetRefs['links'],true),'Embedded video references must be recorded.');
ensure(in_array('https://example.org/reszlet',$assetRefs['links'],true),'Inline editorial source links must be recorded.');
$embedFixture='<iframe src="https://open.spotify.com/embed/episode/7QRXyU8DtYP8Fd1Xo3A0Yz"></iframe><iframe src="https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2Fwatch"></iframe>';
$safeEmbeds=cms_rich_sanitize_html($embedFixture);
ensure(substr_count($safeEmbeds,'<iframe')===2,'Approved podcast/social embeds should be preserved.');
ensure(cms_rich_sanitize_html('<img src="http://127.0.0.1/admin">')==='','Private IP resources must not render.');


// Related-news card before the real story must never win over actual article content.
$fullPage='<!doctype html><html><head><meta property="og:title" content="Árverési hír"></head><body><main>'
 .'<article class="related"><h2>További Kultúra híreink</h2><ul><li>Másik hír</li></ul></article>'
 .'<article><h1>Árverési hír</h1><div class="article-body"><p>'.$paragraph1.'</p><p>'.$paragraph2.'</p>'
 .'<section class="related-news"><h2>További Kultúra híreink</h2><ul><li>Kapcsolódó ajánlat</li></ul></section>'
 .'</div></article></main></body></html>';
$clean=imp_extract($fullPage,'https://example.org/story');
ensure(count($clean['blocks'])===1&&$clean['blocks'][0]['type']==='richhtml','Article body should stay an editable rich HTML block.');
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
ensure(count($hydrated['blocks'])===1&&$hydrated['blocks'][0]['type']==='richhtml','Hydrated source must preserve all blocks as safe rich HTML.');
ensure(str_contains($hydrated['blocks'][0]['text'],'<h2>A kutatás újabb részletei</h2>'),'Hydrated headings must stay editable.');
ensure(str_contains($hydrated['blocks'][0]['text'],trim($paragraph2)),'Hydrated body must not be replaced by a related card.');
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
