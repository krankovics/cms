<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-engine.php';
function ensure(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$html='<!doctype html><html lang="hu"><head><title>Másodlagos cím</title><meta property="og:title" content="Valódi mintacikk"><meta name="description" content="Rövid lead."></head><body><nav>Menü</nav><article><h1>Valódi mintacikk</h1><p>Első bekezdés tiszta szöveggel.</p><h2>Alcím</h2><p>Második bekezdés.</p><blockquote>Fontos idézet</blockquote><script>alert(1)</script></article></body></html>';
$result=imp_extract($html,'https://example.org/cikk');
ensure($result['title']==='Valódi mintacikk','OG title should override doc title.');
ensure($result['lead']==='Rövid lead.','Meta description should be imported.');
ensure(count($result['blocks'])===4,'Expected four editable blocks.');
ensure($result['blocks'][1]['type']==='heading','Section header should be a heading block.');
ensure(!str_contains(json_encode($result['blocks']),'alert(1)'),'Scripts must not be imported.');
$rss='<?xml version="1.0"?><rss version="2.0"><channel><item><link>https://example.org/a</link></item><item><link>https://example.org/b</link></item></channel></rss>';
$feed=imp_discover($rss,'https://example.org/rss.xml');
ensure(count($feed['urls'])===2,'RSS links not discovered.');
$xml='<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.org/a</loc></url></urlset>';
$sm=imp_discover($xml,'https://example.org/sitemap.xml');
ensure($sm['urls']===['https://example.org/a'],'Sitemap URLs must be discovered.');
try{imp_validate_url('http://127.0.0.1/private');throw new RuntimeException('SSRF localhost was accepted.');}
catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'Privát')||str_contains($e->getMessage(),'Nem publikus'),'Reject local IP targets.');}
$robots="User-agent: *\nDisallow: /private/\nAllow: /private/help\nDisallow: /*.pdf$\n\nUser-agent: comesyImporter\nDisallow: /archive/\nAllow: /archive/public/\n";
ensure(imp_robots_permits($robots,'/private/page'),'Named crawler group must override generic group.');
ensure(!imp_robots_permits($robots,'/archive/members'),'Dedicated crawler exclusion must apply.');
ensure(imp_robots_permits($robots,'/archive/public/article'),'More-specific Allow must win over Disallow.');
ensure(!imp_robots_permits("User-agent: *\nDisallow: /*.pdf$\n",'/story.pdf'),'Robots wildcard and trailing anchor must match.');
ensure(imp_robots_permits("User-agent: *\nDisallow: /draft/\nAllow: /draft/public/\n",'/draft/public/a'),'Longest Allow rule must win.');
ensure(imp_robots_permits("User-agent: *\nDisallow:\n",'/anything'),'Empty disallow must allow crawling.');
echo "PASS: article HTML, RSS/Atom/sitemap parser, script removal and localhost rejection.\n";
