<?php
declare(strict_types=1);
require_once __DIR__.'/site-settings.php';
function cms_default_layout(string $kind):array{
 return match($kind){
  'home'=>[
   ['type'=>'hero','title'=>'A nap történetei','text'=>'Friss hírek, érdekes nézőpontok és fontos történetek egy helyen.','columns'=>1],
   ['type'=>'latest','title'=>'Legfrissebb cikkek','limit'=>10,'columns'=>3]
  ],
  'section'=>[['type'=>'hero','title'=>'Rovat','columns'=>1],['type'=>'latest','title'=>'A rovat cikkei','limit'=>10,'columns'=>2]],
  'article'=>[['type'=>'article','title'=>'','columns'=>1],['type'=>'latest','title'=>'További olvasnivaló','limit'=>4,'columns'=>2]],
  default=>throw new RuntimeException('Érvénytelen oldalsablon.')
 };
}
/**
 * Accepts legacy flat components and new row -> box -> block hierarchy.
 * All values are whitelisted and normalized before saving or publishing.
 */
function cms_layout_clean_block(array $b,string $kind):array{
 $type=(string)($b['type']??'');
 if(!in_array($type,['hero','latest','text','image','ad','article'],true))throw new RuntimeException('Ismeretlen oldalkomponens.');
 if($type==='article'&&$kind!=='article')throw new RuntimeException('A cikkkomponens csak cikkoldalon használható.');
 $title=trim((string)($b['title']??''));$text=trim((string)($b['text']??''));
 if(mb_strlen($title)>190||mb_strlen($text)>3000)throw new RuntimeException('A komponens szövege túl hosszú.');
 $cols=(int)($b['columns']??1);$limit=(int)($b['limit']??10);
 if($cols<1||$cols>4||$limit<1||$limit>30)throw new RuntimeException('Érvénytelen komponensbeállítás.');
 $zone=preg_replace('/[^a-z0-9_-]/','',mb_strtolower(trim((string)($b['zone']??''))));
 if(strlen($zone)>100)throw new RuntimeException('Érvénytelen hirdetési zónakód.');
 $url=trim((string)($b['image_url']??''));
 if(strlen($url)>1000||($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true))))throw new RuntimeException('Érvénytelen képhivatkozás.');
 return ['type'=>$type,'title'=>$title,'text'=>$text,'columns'=>$cols,'limit'=>$limit,'zone'=>$zone,'image_url'=>$url];
}
function cms_layout_validate(string $json,string $kind):string{
 if(!in_array($kind,['home','section','article'],true)||strlen($json)>150000)throw new RuntimeException('Érvénytelen oldalsablon.');
 $layout=json_decode($json,true,64,JSON_THROW_ON_ERROR);
 if(!is_array($layout)||!array_is_list($layout)||count($layout)>30||count($layout)===0)throw new RuntimeException('A sablonnak 1–30 sorból vagy komponensből kell állnia.');
 $clean=[];$total=0;
 foreach($layout as $entry){
  if(!is_array($entry))throw new RuntimeException('Érvénytelen sablonelem.');
  if(($entry['type']??'')!=='row'){
   $clean[]=cms_layout_clean_block($entry,$kind);$total++;continue;
  }
  $columns=$entry['columns']??null;
  if(!is_array($columns)||!array_is_list($columns)||count($columns)<1||count($columns)>4)throw new RuntimeException('Egy sorban 1–4 doboz használható.');
  $boxes=[];$usedWidth=0;
  foreach($columns as $column){
   if(!is_array($column)||($column['type']??'')!=='box')throw new RuntimeException('Érvénytelen doboz.');
   $span=(int)($column['span']??0);
   if($span<1||$span>12)throw new RuntimeException('A doboz szélessége 1–12 lehet.');
   $usedWidth+=$span;
   if($usedWidth>12)throw new RuntimeException('A sor dobozainak összes szélessége legfeljebb 12 lehet.');
   $blocks=$column['blocks']??null;
   if(!is_array($blocks)||!array_is_list($blocks)||count($blocks)>15)throw new RuntimeException('Egy dobozban legfeljebb 15 blokk lehet.');
   $cleanBlocks=[];
   foreach($blocks as $block){
    if(!is_array($block))throw new RuntimeException('Érvénytelen blokk.');
    $cleanBlocks[]=cms_layout_clean_block($block,$kind);$total++;
    if($total>60)throw new RuntimeException('Egy sablonban legfeljebb 60 tartalmi blokk lehet.');
   }
   $boxes[]=['type'=>'box','span'=>$span,'blocks'=>$cleanBlocks];
  }
  $clean[]=['type'=>'row','columns'=>$boxes];
 }
 if($total===0)throw new RuntimeException('Legalább egy tartalmi blokk szükséges.');
 return json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function cms_template_save(PDO $pdo,array $user,int $site,string $kind,string $action,string $json,int $expected=0,int $revisionId=0):int{
 if(!in_array($user['role'],['platform_admin','site_admin','editor'],true))throw new RuntimeException('Nincs oldalsablon-szerkesztési jogosultság.');
 require_once __DIR__.'/site-access.php';
 if(!in_array($site,allowed_primary_sites($pdo,$user),true))throw new RuntimeException('Nincs szerkesztési jog a webhelyhez.');
 $q=$pdo->prepare('SELECT settings_json FROM sites WHERE id=?');$q->execute([$site]);$row=$q->fetch();
 if(!$row)throw new RuntimeException('A webhely nem található.');
 if(!cms_site_settings($row)['builder_enabled'])throw new RuntimeException('Az oldalépítő ezen a webhelyen ki van kapcsolva.');
 if(!in_array($action,['save','publish','restore'],true))throw new RuntimeException('Ismeretlen sablonművelet.');
 if(!in_array($kind,['home','section','article'],true))throw new RuntimeException('Ismeretlen oldalsablon.');
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT * FROM site_templates WHERE site_id=? AND template_key=? FOR UPDATE');$q->execute([$site,$kind]);
  $template=$q->fetch();$current=(int)($template['revision_number']??0);
  if($current!==$expected)throw new RuntimeException('A sablont időközben módosították. Frissítsd az oldalt.');
  if($action==='restore'){
   if(!$template||$revisionId<=0)throw new RuntimeException('Nincs visszaállítható verzió.');
   $q=$pdo->prepare('SELECT content_json FROM site_template_revisions WHERE id=? AND template_id=?');$q->execute([$revisionId,$template['id']]);
   $restore=$q->fetchColumn();if($restore===false)throw new RuntimeException('A kiválasztott sablonverzió nem található.');
   $json=(string)$restore;
  }
  $json=cms_layout_validate($json,$kind);
  $next=$current+1;
  if($template){
   $sql=$action==='publish'?'UPDATE site_templates SET draft_json=?,published_json=?,published_at=NOW(),updated_by=?,revision_number=? WHERE id=?':'UPDATE site_templates SET draft_json=?,updated_by=?,revision_number=? WHERE id=?';
   $args=$action==='publish'?[$json,$json,$user['id'],$next,$template['id']]:[$json,$user['id'],$next,$template['id']];
   $pdo->prepare($sql)->execute($args);$id=(int)$template['id'];
  }else{
   $q=$pdo->prepare('INSERT INTO site_templates(site_id,template_key,draft_json,published_json,updated_by,revision_number,published_at) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$site,$kind,$json,$action==='publish'?$json:null,$user['id'],$next,$action==='publish'?date('Y-m-d H:i:s'):null]);$id=(int)$pdo->lastInsertId();
  }
  $q=$pdo->prepare('INSERT INTO site_template_revisions(template_id,revision_number,editor_id,event,content_json) VALUES(?,?,?,?,?)');
  $q->execute([$id,$next,$user['id'],$action,$json]);
  $pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)')->execute([
   $user['id'],'layout.'.$action,'site_template',$id,json_encode(['site_id'=>$site,'template'=>$kind,'revision'=>$next],JSON_UNESCAPED_UNICODE)
  ]);
  $pdo->commit();return $id;
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
function cms_menu_url(string $url):string{
 $url=trim($url);
 if($url==='/'||preg_match('~^/(?!/)[a-zA-Z0-9/_?&=.%-]{1,500}$~D',$url))return $url;
 if(strlen($url)<=1000&&filter_var($url,FILTER_VALIDATE_URL)&&strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https')return $url;
 throw new RuntimeException('A menüpont célja relatív útvonal vagy HTTPS URL lehet.');
}
function cms_menu_replace(PDO $pdo,array $user,int $site,array $items):void{
 require_once __DIR__.'/site-access.php';
 if(!in_array($user['role'],['platform_admin','site_admin','editor'],true)||!in_array($site,allowed_primary_sites($pdo,$user),true))throw new RuntimeException('Nincs jogosultság.');
 $q=$pdo->prepare('SELECT settings_json FROM sites WHERE id=?');
 $q->execute([$site]);$row=$q->fetch();
 if(!$row||!cms_site_settings($row)['builder_enabled'])throw new RuntimeException('A webhely oldalépítője ki van kapcsolva.');
 if(count($items)>40)throw new RuntimeException('Legfeljebb 40 menüpont engedélyezett.');
 $clean=[];$i=0;
 foreach($items as $item){
  if(!is_array($item))continue;
  $label=trim((string)($item['label']??''));if(!$label)continue;
  if(mb_strlen($label)>120)throw new RuntimeException('Túl hosszú menüpontnév.');
  $clean[]=[$site,$label,cms_menu_url((string)($item['url']??'/')),$i++,(int)!empty($item['active'])];
 }
 $pdo->beginTransaction();
 try{
  $pdo->prepare('DELETE FROM site_menu_items WHERE site_id=?')->execute([$site]);
  $q=$pdo->prepare('INSERT INTO site_menu_items(site_id,label,url,position,active) VALUES(?,?,?,?,?)');
  foreach($clean as $item)$q->execute($item);
  $pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)')->execute([$user['id'],'menu.save','site',$site,json_encode(['items'=>count($clean)])]);
  $pdo->commit();
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
