<?php
declare(strict_types=1);
/**
 * Authenticated, opt-in REAL AdOcean preview. Never embed provider tags into the
 * editor's origin: the calling iframe MUST use sandbox="allow-scripts" without
 * allow-same-origin. Imported tag source is data only; no raw tags are executed.
 */
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/site-access.php';
require_once __DIR__.'/app/ad-external-placements.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline' 'unsafe-eval' https:; style-src 'unsafe-inline' https:; img-src https: data: blob:; connect-src https:; font-src https: data:; frame-src https:; media-src https:; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
$u=require_user();
$pdo=db();
$siteId=filter_input(INPUT_GET,'site_id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if(!$siteId||!in_array((int)$siteId,allowed_primary_sites($pdo,$u),true)||
 !in_array($u['role'],['platform_admin','site_admin','editor'],true)){http_response_code(403);exit('Nincs tesztelőnézeti hozzáférés.');}
$ref=[];
foreach(['external_import_id','external_folder_id','external_master_id','external_leaf_id'] as $key){
 $raw=(string)($_GET[$key]??'');
 if(!preg_match('/^(?:0|[1-9][0-9]{0,17})$/D',$raw)){http_response_code(400);exit('Érvénytelen AdOcean azonosító.');}
 $ref[$key]=(int)$raw;
}
try{
 $valid=cms_external_resolve_reference($pdo,$siteId,$ref,true);
 if(!$valid){http_response_code(404);exit('Az AdOcean pozíció nincs aktívan ehhez a webhelyhez rendelve.');}
 $masterCode='';$server='';
 if($valid['master_node_id']>0){
  $q=$pdo->prepare("SELECT master_code_id,source_code FROM ad_external_nodes WHERE import_id=? AND folder_source_id=? AND source_id=? AND node_type='MASTER' AND is_active=1");
  $q->execute([$valid['import_id'],$valid['folder_id'],$valid['master_node_id']]);
  $master=$q->fetch();
  if(!$master)throw new RuntimeException('Nem található az aktív MASTER.');
  $masterCode=(string)$master['master_code_id'];
  $source=(string)$master['source_code'];
 }else{
  $q=$pdo->prepare("SELECT source_code FROM ad_external_nodes WHERE import_id=? AND folder_source_id=? AND source_id=? AND node_type='LEAF' AND is_active=1");
  $q->execute([$valid['import_id'],$valid['folder_id'],$valid['leaf_node_id']]);
  $source=(string)$q->fetchColumn();
 }
 if(!preg_match('~\bserver\s*:\s*["\x27]([a-zA-Z0-9.-]+)["\x27]~',$source,$match))throw new RuntimeException('Hiányzó AdOcean-kiszolgáló.');
 $server=strtolower($match[1]);
 if(!preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.adocean\.pl$/D',$server))throw new RuntimeException('Nem engedélyezett AdOcean-kiszolgáló.');
 $leafCode=(string)$valid['code_id'];
 foreach([$leafCode,$masterCode] as $value)if($value!==''&&!preg_match('/^[A-Za-z0-9_.-]{1,190}$/D',$value))throw new RuntimeException('Érvénytelen hirdetési azonosító.');
}catch(Throwable $e){
 error_log('AdOcean real preview: '.$e->getMessage());
 http_response_code(422);exit('Ez az AdOcean pozíció jelenleg nem tesztelhető.');
}
$flags=JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES;
$channel=(string)($_GET['channel']??'');
if(!preg_match('/^[a-zA-Z0-9_-]{20,80}$/D',$channel))$channel='';
$config=json_encode(['server'=>$server,'master'=>$masterCode,'leaf'=>$leafCode,'channel'=>$channel],$flags|JSON_THROW_ON_ERROR);
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AdOcean próba · LEAF #<?=(int)$valid['leaf_node_id']?></title>
<style>*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#334155;font:12px/1.5 system-ui,sans-serif}
.preview-ad{max-width:100%;min-height:90px;display:flex;justify-content:center;align-items:flex-start;overflow:hidden}
.preview-ad>div{max-width:100%}#preview-status{padding:8px 10px;text-align:center;background:#f1f5f9;color:#475569}
</style>
<script>
// An opaque-origin sandbox is required to isolate advertising creatives from
// the authenticated comesy admin. AdOcean 4.x reads document.cookie and
// localStorage at MASTER initialization and would otherwise throw SecurityError.
// These preview-only, ephemeral shims return no real user identifiers.
(function(){
 var store=Object.create(null);
 try{void document.cookie;}catch(e){
  if(e.name==='SecurityError')Object.defineProperty(document,'cookie',{
   configurable:true,get:function(){return '';},set:function(){}
  });
 }
 try{void window.localStorage;}catch(e){
  if(e.name==='SecurityError')Object.defineProperty(window,'localStorage',{
   configurable:true,get:function(){return {
    getItem:function(k){return Object.prototype.hasOwnProperty.call(store,k)?store[k]:null;},
    setItem:function(k,v){store[String(k)]=String(v);},
    removeItem:function(k){delete store[String(k)];},
    clear:function(){store=Object.create(null);},
    key:function(n){return Object.keys(store)[n]||null;},
    get length(){return Object.keys(store).length;}
   };}
  });
 }
})();
</script>
<script src="https://<?=htmlspecialchars($server,ENT_QUOTES,'UTF-8')?>/files/js/ado.js"></script>
<script>
(function(){
 var config=<?=$config?>;
 window.comesyPreviewConfig=config;
 window.comesyPreviewReport=function(phase){
  if(config.channel)parent.postMessage({type:'comesy-adocean-preview',channel:config.channel,phase:phase},'*');
 };
 if(!window.ado||typeof window.ado.config!=='function'){
  window.comesyPreviewError='Az AdOcean könyvtár nem töltődött be.';
  window.comesyPreviewReport('sdk_error');return;
 }
 window.comesyPreviewReport('sdk_ready');
 try{
  // Official Live Preview mode. This alone cannot guarantee an eligible creative.
  window.ado.config({mode:'new',xml:false,consent:true,characterEncoding:true});
  if(typeof window.ado.preview==='function')window.ado.preview({enabled:true});
  if(config.master)window.ado.master({id:config.master,server:config.server});
  window.comesyPreviewReport('master_ready');
 }catch(e){window.comesyPreviewError='MASTER indítási hiba: '+(e.message||String(e));window.comesyPreviewReport('master_error');}
})();
</script></head><body>
<div id="preview-ad" class="preview-ad"><div id="<?=htmlspecialchars($leafCode,ENT_QUOTES,'UTF-8')?>"></div></div>
<div id="preview-status" role="status" aria-live="polite">Valódi AdOcean-teszt. A hirdetés megjelenése a kampánybeállításoktól is függ.</div>
<script>
(function(){
 var status=document.getElementById('preview-status');
 if(window.comesyPreviewError){status.textContent=window.comesyPreviewError;window.comesyPreviewReport('master_error');return;}
 try{
  var cfg=window.comesyPreviewConfig;
  if(cfg.master)window.ado.slave(cfg.leaf,{myMaster:cfg.master});
  else window.ado.placement({id:cfg.leaf,server:cfg.server});
  status.textContent='Az AdOcean kérés elindult. Kreatívválasz ellenőrzése…';
  window.comesyPreviewReport('request_started');
  var observed=document.getElementById('preview-ad');
  var delivered=false;
  var hasCreative=function(){
   if(!observed)return false;
   if(observed.querySelector('img,iframe,object,video,canvas,svg'))return true;
   if(observed.textContent.trim()!=='')return true;
   return !!document.querySelector('img[src*="adocean.pl/"],iframe[src*="adocean.pl/"]');
  };
  var confirmCreative=function(){
   if(delivered||!hasCreative())return;
   delivered=true;
   status.textContent='Hirdetési kreatív került a tesztkeretbe.';
   window.comesyPreviewReport('creative_detected');
  };
  var observer=new MutationObserver(confirmCreative);
  observer.observe(document.documentElement,{childList:true,subtree:true,characterData:true});
  confirmCreative();
  setTimeout(function(){
   observer.disconnect();
   if(delivered)return;
   status.textContent='Az AdOcean kérés elindult, de 12 másodpercen belül nem jelent meg kreatív. Lehet, hogy nincs megfelelő kampány, vagy a böngésző blokkolja.';
   window.comesyPreviewReport('no_creative');
  },12000);
 }catch(e){status.textContent='AdOcean megjelenítési hiba: '+(e.message||String(e));window.comesyPreviewReport('render_error');}
})();
</script></body></html>
