<?php
declare(strict_types=1);
require_once __DIR__.'/layouts.php';
require_once __DIR__.'/site-design-templates.php';

/** Editorial layouts that reflect the structure of a source site, not its
 * copyrighted article content or its remote scripts. All cards are native. */
function cms_template_family_definitions(array $profile):array{
 $host=strtolower((string)($profile['host']??''));
 $mno=in_array($host,['magyarnemzet.hu','www.magyarnemzet.hu'],true);
 if(!$mno){
  $generic=[
  'home'=>[['type'=>'hero','title'=>'Kiemelt történetek','text'=>'','columns'=>1],['type'=>'latest','title'=>'Friss cikkek','limit'=>18,'columns'=>3]],
  'section'=>[['type'=>'hero','title'=>'Rovat','columns'=>1],['type'=>'latest','title'=>'A rovat hírei','limit'=>18,'columns'=>2]],
  'tag'=>[['type'=>'hero','title'=>'Címke','columns'=>1],['type'=>'latest','title'=>'Kapcsolódó cikkek','limit'=>18,'columns'=>1]],
  'article'=>cms_default_layout('article'),
  'page'=>[['type'=>'page_body','title'=>'Oldaltartalom','columns'=>1]],
  'login'=>cms_default_layout('login')
 ];
  if(empty($profile['site_design']['has_login']))unset($generic['login']);
  return $generic;
 }
 return [
  'home'=>[['type'=>'latest','title'=>'Vezető hírek','limit'=>5,'columns'=>2],['type'=>'latest','title'=>'Friss híreink','limit'=>15,'offset'=>5,'columns'=>3],['type'=>'latest','title'=>'Még több olvasnivaló','limit'=>12,'offset'=>20,'columns'=>4]],
  'section'=>[['type'=>'hero','title'=>'Belföld','columns'=>1],['type'=>'latest','title'=>'Friss hírek','limit'=>10,'columns'=>2],['type'=>'latest','title'=>'A rovat további cikkei','limit'=>18,'offset'=>10,'columns'=>1]],
  'tag'=>[['type'=>'hero','title'=>'Magyar Péter','columns'=>1],['type'=>'latest','title'=>'Kapcsolódó hírek','limit'=>18,'columns'=>1]],
  'article'=>[['type'=>'article','title'=>'','columns'=>1],['type'=>'latest','title'=>'Kapcsolódó cikkek','limit'=>5,'columns'=>2]],
  'page'=>[['type'=>'page_body','title'=>'Statikus oldal','columns'=>1]],
  'login'=>[['type'=>'reader_form','title'=>'Bejelentkezés','columns'=>1]]
 ];
}
function cms_template_family_sources(array $profile):array{
 $host=strtolower((string)($profile['host']??''));
 if(in_array($host,['magyarnemzet.hu','www.magyarnemzet.hu'],true))return [
  'home'=>'https://magyarnemzet.hu/',
  'section'=>'https://magyarnemzet.hu/rovat/belfold',
  'tag'=>'https://magyarnemzet.hu/cimke/magyar-peter',
  'article'=>(string)($profile['source_url']??'https://magyarnemzet.hu/'),
  'page'=>'https://magyarnemzet.hu/impresszum',
  'login'=>'https://magyarnemzet.hu/bejelentkezes'
 ];
 $url='https://'.$host.'/';return array_fill_keys(['home','section','tag','article','page','login'],$url);
}
/** Creates only missing drafts. Existing edited or published templates stay intact. */
function cms_template_family_seed(PDO $pdo,int $siteId,int $actor,array $profile,bool $readerEnabled=false):array{
 if($siteId<1||$actor<1)throw new RuntimeException('Hiányzó webhely vagy szerkesztő.');
 $definitions=cms_template_family_definitions($profile);
 $created=[];
 $pdo->beginTransaction();
 try{
  $insert=$pdo->prepare('INSERT IGNORE INTO site_templates(site_id,template_key,draft_json,published_json,updated_by,revision_number) VALUES(?,?,?,?,?,1)');
  $row=$pdo->prepare('SELECT id FROM site_templates WHERE site_id=? AND template_key=?');
  $revision=$pdo->prepare("INSERT INTO site_template_revisions(template_id,revision_number,editor_id,event,content_json) VALUES(?,1,?,'save',?)");
  foreach($definitions as $key=>$blocks){
   $json=cms_layout_validate(json_encode($blocks,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key);
   $insert->execute([$siteId,$key,$json,null,$actor]);
   if(!$insert->rowCount())continue;
   $row->execute([$siteId,$key]);$id=(int)$row->fetchColumn();
   $revision->execute([$id,$actor,$json]);$created[]=$key;
  }
  // An actual Impresszum page is editable, not scraped/impersonated metadata.
  $page=$pdo->prepare("INSERT IGNORE INTO site_pages(site_id,slug,title,body_html,status,updated_by) VALUES(?,'impresszum','Impresszum','<p>Az impresszum adatait a szerkesztőség töltheti ki az Oldalak menüben.</p>','draft',?)");
  $page->execute([$siteId,$actor]);
  // Seed example section and tag only for verified source patterns.
  $host=strtolower((string)($profile['host']??''));
  if(in_array($host,['magyarnemzet.hu','www.magyarnemzet.hu'],true)){
   $tx=$pdo->prepare('INSERT IGNORE INTO taxonomy_terms(site_id,type,name,slug) VALUES(?,?,?,?)');
   $tx->execute([$siteId,'category','Belföld','belfold']);
   $tx->execute([$siteId,'tag','Magyar Péter','magyar-peter']);
  }
  $count=$pdo->prepare('SELECT COUNT(*) FROM site_menu_items WHERE site_id=?');
  $count->execute([$siteId]);
  if((int)$count->fetchColumn()===0){
   $menus=[['Nyitóoldal','/'],['Belföld','/section/belfold'],['Magyar Péter','/tag/magyar-peter'],['Impresszum','/page/impresszum']];
   if($readerEnabled)$menus[]=['Bejelentkezés','/bejelentkezes'];
   $ins=$pdo->prepare('INSERT INTO site_menu_items(site_id,label,url,position,active) VALUES(?,?,?,?,1)');
   foreach($menus as $n=>$m)$ins->execute([$siteId,$m[0],$m[1],$n]);
  }
  $audit=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
  $audit->execute([$actor,'site.template_family_seed','site',$siteId,json_encode(['kinds'=>$created,'source_host'=>$host],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
  $pdo->commit();return $created;
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
