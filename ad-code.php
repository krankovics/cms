<?php
declare(strict_types=1);
/** Untrusted third-party ad markup is ONLY rendered inside an opaque-origin
 * sandboxed iframe. No allow-same-origin, top navigation or forms. */
$id=max(0,(int)($_GET['id']??0));
if(!$id){http_response_code(404);exit;}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline' https:; img-src https: data:; style-src 'unsafe-inline' https:; frame-src https:; connect-src https:; font-src https: data:; media-src https:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
try{
 $cfg=require __DIR__.'/app/config.php';
 $db=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $q=$db->prepare("SELECT cc.code_html FROM ad_code_creatives cc JOIN ad_creatives cr ON cr.id=cc.creative_id JOIN ad_campaigns c ON c.id=cr.campaign_id JOIN ad_zones z ON z.id=cr.zone_id WHERE cc.creative_id=? AND cc.format='code' AND cr.enabled=1 AND z.enabled=1 AND c.state='active' AND c.site_id=z.site_id AND (c.starts_at IS NULL OR c.starts_at<=NOW()) AND (c.ends_at IS NULL OR c.ends_at>NOW()) LIMIT 1");
 $q->execute([$id]);$html=$q->fetchColumn();
 if(!is_string($html)||$html===''){http_response_code(404);exit;}
 echo '<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body{margin:0;padding:0;min-height:100%;overflow:hidden;background:transparent}</style></head><body>'.$html.'</body></html>';
}catch(Throwable $e){error_log('ad-code: '.$e->getMessage());http_response_code(503);}
