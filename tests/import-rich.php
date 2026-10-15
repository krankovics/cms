<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/import-engine.php';
require dirname(__DIR__).'/app/rich-content.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$first=str_repeat('Ez az első bekezdés a valódi cikk tartalmát és részleteit mutatja be. ',4);
$second=str_repeat('Ez a második bekezdés is teljes értékű szerkesztett tartalom további tényekkel. ',4);
$url='https://example.org/kultura/2026/10/gazdag-tartalmu-cikk';
$hydrated='<p><strong>Vastag kiemelés</strong> <em>dőlt rész</em> <mark>kiemelés</mark> '
 .'<a href="https://example.com/forras">'.$first.'</a></p>'
 .'<figure><img src="/media/belso.jpg" alt="Galériakép"><figcaption>Fotó: A helyszín, szerző: fotós</figcaption></figure>'
 .'<h2>Második szakasz</h2><p>'.$second.'</p>'
 .'<table><thead><tr><th>Év</th><th>Érték</th></tr></thead><tbody><tr><td>2026</td><td>100</td></tr></tbody></table>'
 .'<iframe src="https://www.youtube.com/watch?v=dQw4w9WgXcQ"></iframe>'
 .'<iframe src="https://open.spotify.com/episode/4XNPWueDhZLv2CJK1OrteA"></iframe>'
 .'<blockquote class="instagram-media"><a href="https://www.instagram.com/p/CG0UU3sFj_g/">Instagram</a></blockquote>';
$state=['222'=>['b'=>['data'=>['title'=>'Gazdag tartalmú cikk','slug'=>'gazdag-tartalmu-cikk','body'=>[
 ['type'=>'text','details'=>[['type'=>'html','value'=>$hydrated]]]
]]]]];
$encoded=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_THROW_ON_ERROR);
$page='<!doctype html><html><head><meta property="og:title" content="Gazdag tartalmú cikk">'
 .'<meta property="og:image" content="/covers/hero.webp"></head><body><main><h1>Gazdag tartalmú cikk</h1>'
 .'<script id="ng-state" type="application/json">'.$encoded.'</script></main></body></html>';
$d=imp_extract($page,$url);
check($d['featured_image']==='https://example.org/covers/hero.webp','OG featured image missing.');
check(imp_blocks_are_substantive($d['blocks']),'Narrative quality check must still pass.');
$ops=$d['richtext']['ops'];
check(count($ops)>=12,'Rich blocks were collapsed.');
$figures=array_values(array_filter($ops,fn($op)=>isset($op['insert']['cmsFigure'])));
check(count($figures)===1,'Figure was not imported exactly once.');
check($figures[0]['insert']['cmsFigure']['caption']==='Fotó: A helyszín, szerző: fotós','Figure caption missing.');
check($figures[0]['insert']['cmsFigure']['alt']==='Galériakép','Image alt missing.');
$tables=array_values(array_filter($ops,fn($op)=>isset($op['insert']['cmsTable'])));
check(count($tables)===1&&count($tables[0]['insert']['cmsTable']['rows'])===2,'Table cells lost.');
$embeds=array_values(array_filter($ops,fn($op)=>isset($op['insert']['cmsEmbed'])));
check(count($embeds)===3,'Video, podcast and Instagram embeds should be retained.');
$providers=array_column(array_column($embeds,'insert'),'cmsEmbed');
check(in_array('youtube',array_column($providers,'provider'),true),'YouTube missing.');
check(in_array('spotify',array_column($providers,'provider'),true),'Spotify missing.');
check(in_array('instagram',array_column($providers,'provider'),true),'Instagram missing.');
check((bool)array_filter($ops,fn($op)=>!empty($op['attributes']['bold'])),'Bold style missing.');
check((bool)array_filter($ops,fn($op)=>!empty($op['attributes']['italic'])),'Italic style missing.');
check((bool)array_filter($ops,fn($op)=>!empty($op['attributes']['link'])),'Hyperlink missing.');
check((bool)array_filter($ops,fn($op)=>!empty($op['attributes']['background'])),'Highlight missing.');
$html=cms_rich_delta(json_encode($d['richtext'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
foreach(['<strong>','<em>','<mark>','<table>','<figcaption>','Galériakép','youtube-nocookie.com/embed/dQw4w9WgXcQ','open.spotify.com/embed/episode/','instagram.com/p/CG0UU3sFj_g/embed/'] as $expected)
 check(str_contains($html,$expected),'Public renderer lost: '.$expected);
check(!str_contains($html,'<script'),'Raw scripts may not be rendered.');
$malicious=cms_rich_delta(json_encode(['ops'=>[['insert'=>['cmsTable'=>['rows'=>[[['text'=>'<script>alert(1)</script>','header'=>false]]]]]]]],JSON_THROW_ON_ERROR));
check(!str_contains($malicious,'<script>'),'Table contents must be escaped.');
check(!str_contains($malicious,'alert(1)</script>'),'Script markup must never be produced.');
check(imp_rich_embed_url('https://evil.example/video/4',$url)===null,'Unapproved media hosts must be rejected.');
check(imp_rich_media_urls($d['richtext'],$d['featured_image'])===['https://example.org/covers/hero.webp','https://example.org/media/belso.jpg'],'Featured and inline image sources must be identified.');
echo "PASS: rich article, caption, OG image, social embeds, tables, inline formatting and safe frontend output.\n";
