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

$rss='<?xml version="1.0"?><rss version="2.0"><channel><item><link>https://example.org/a</link></item><item><link>https://example.org/b</link></item></channel></rss>';
$feed=imp_discover($rss,'https://example.org/rss.xml');
ensure(count($feed['urls'])===2,'RSS links not discovered.');
$xml='<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.org/a</loc></url></urlset>';
$sm=imp_discover($xml,'https://example.org/sitemap.xml');
ensure($sm['urls']===['https://example.org/a'],'Sitemap URLs must be discovered.');

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
