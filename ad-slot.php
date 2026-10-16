<?php
declare(strict_types=1);
// Public, cookie-free iframe renderer. Requests are counted, not guaranteed viewable impressions.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; img-src https: http:; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors https: http:; base-uri 'none'; form-action 'none'");
$site=max(0,(int)($_GET['site_id']??0));
$zone=trim((string)($_GET['zone']??''));
$section=trim((string)($_GET['section']??''));
if(!$site||!preg_match('/^[a-z0-9_-]{1,100}$/',$zone)||($section!==''&&!preg_match('/^[a-z0-9_-]{1,190}$/',$section))){http_response_code(400);exit('Érvénytelen zóna.');}
try{
 $cfg=require __DIR__.'/app/config.php';
 $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $q=$db->prepare("SELECT cr.id,cr.image_url,cr.alt_text,c.id campaign_id,z.id zone_id,c.priority,cc.format FROM ad_creatives cr LEFT JOIN ad_code_creatives cc ON cc.creative_id=cr.id JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id WHERE z.site_id=? AND z.code=? AND z.enabled=1 AND cr.enabled=1 AND c.site_id=z.site_id AND c.state='active' AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.ends_at IS NULL OR c.ends_at>NOW()) AND (c.section_slug='' OR c.section_slug=?) ORDER BY c.priority DESC,cr.id DESC LIMIT 1");
 $q->execute([$site,$zone,$section]);$ad=$q->fetch();
 if(!$ad){http_response_code(204);exit;}
 $db->prepare('UPDATE ad_creatives SET slot_requests=slot_requests+1 WHERE id=?')->execute([(int)$ad['id']]);
 $token=bin2hex(random_bytes(16));
 $db->prepare('INSERT INTO ad_serves(token,site_id,zone_id,campaign_id,creative_id) VALUES(?,?,?,?,?)')->execute([$token,$site,(int)$ad['zone_id'],(int)$ad['campaign_id'],(int)$ad['id']]);
}catch(Throwable $e){error_log('ad-slot: '.$e->getMessage());http_response_code(503);exit('');}
function esc(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hirdetés</title><style>html,body{margin:0;padding:0;background:transparent;min-height:100%}a{display:block;text-align:center}img{display:block;max-width:100%;height:auto;margin:auto}.ad-code-frame{border:0;display:block;width:100%;height:100%;min-height:230px}small{display:block;font:11px system-ui,sans-serif;color:#646b79;margin:3px 0}</style></head><body>
<?php if(($ad['format']??'image')==='code'):?>
<iframe class="ad-code-frame" sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox" title="Hirdetési tartalom" referrerpolicy="no-referrer" src="/ad-code.php?id=<?=(int)$ad['id']?>"></iframe>
<?php else:?><a rel="sponsored noopener noreferrer" target="_blank" href="/ad-click.php?id=<?=(int)$ad['id']?>&amp;token=<?=esc($token)?>"><img src="<?=esc($ad['image_url'])?>" alt="<?=esc($ad['alt_text'])?>" loading="lazy" referrerpolicy="no-referrer"></a><?php endif;?>
<small>Hirdetés</small><script src="/assets/ad-measure.js?v=1" defer data-token="<?=esc($token)?>"></script></body></html>