<?php
declare(strict_types=1);
require_once __DIR__.'/site-access.php';

function ad_manage_sites(PDO $pdo,array $u):array {
 if($u['role']==='platform_admin')return array_map('intval',$pdo->query('SELECT id FROM sites ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
 if(!in_array($u['role'],['site_admin','ad_manager'],true))return [];
 $q=$pdo->prepare('SELECT site_id FROM user_site_permissions WHERE user_id=? AND is_primary=1');
 $q->execute([$u['id']]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function ad_require_site(PDO $pdo,array $u,int $site):void{
 if($site<=0||!in_array($site,ad_manage_sites($pdo,$u),true))throw new RuntimeException('Nincs hirdetéskezelési jogosultság a kiválasztott webhelyen.');
}
function ad_slug(string $s):string{
 $s=mb_strtolower(trim($s));$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;
 return trim((string)preg_replace('/[^a-z0-9_-]+/','-',strtolower($s)),'-');
}
function ad_url(string $value):string{
 $value=trim($value);
 if(strlen($value)>1000||!filter_var($value,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['https','http'],true))throw new RuntimeException('Érvényes HTTP(S) URL-t adj meg.');
 return $value;
}
function ad_date(string $s):?string{
 if(trim($s)==='')return null;
 $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',trim($s));
 if(!$date||$date->format('Y-m-d\TH:i')!==trim($s))throw new RuntimeException('Érvénytelen kampányidőpont.');
 return $date->format('Y-m-d H:i:s');
}
function ad_record(PDO $pdo,int $userId,string $action,string $entity,int $id,int $site):void{
 $q=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,?,?,?,?)');
 $q->execute([$userId,'ad.'.$action,$entity,$id,json_encode(['site_id'=>$site],JSON_THROW_ON_ERROR)]);
}
function ad_mutate(PDO $pdo,array $u,int $site,array $data):void{
 if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
 ad_require_site($pdo,$u,$site);
 $entity=(string)($data['entity']??'');$action=(string)($data['action']??'save');
 if(!in_array($entity,['advertiser','zone','campaign','creative'],true)||!in_array($action,['save','delete','toggle'],true))throw new RuntimeException('Ismeretlen hirdetési művelet.');
 $id=max(0,(int)($data['id']??0));
 $names=['advertiser'=>'ad_advertisers','zone'=>'ad_zones','campaign'=>'ad_campaigns','creative'=>'ad_creatives'];
 $pdo->beginTransaction();
 try{
  if($id){
   $join=$entity==='creative'?' JOIN ad_campaigns c ON c.id=x.campaign_id':'';
   $cond=$entity==='creative'?'c.site_id':'x.site_id';
   $q=$pdo->prepare('SELECT x.id FROM '.$names[$entity].' x'.$join.' WHERE x.id=? AND '.$cond.'=? FOR UPDATE');
   $q->execute([$id,$site]);if(!$q->fetchColumn())throw new RuntimeException('Nem található vagy nem szerkeszthető rekord.');
  }
  if($action==='delete'){
   if(!$id)throw new RuntimeException('Válassz törlendő rekordot.');
   $pdo->prepare('DELETE FROM '.$names[$entity].' WHERE id=?')->execute([$id]);
  }elseif($action==='toggle'){
   if(!$id)throw new RuntimeException('Hiányzó rekord.');
   if($entity==='advertiser')throw new RuntimeException('A hirdető nem kapcsolható ki.');
   $column=$entity==='campaign'?'state':'enabled';
   $value=$entity==='campaign'?(($data['value']??'')==='active'?'active':'paused'):(($data['value']??'')==='1'?1:0);
   $pdo->prepare('UPDATE '.$names[$entity].' SET '.$column.'=? WHERE id=?')->execute([$value,$id]);
  }elseif($entity==='advertiser'){
   $name=mb_substr(trim((string)($data['name']??'')),0,190);
   if(mb_strlen($name)<2)throw new RuntimeException('A hirdető neve legalább két karakter.');
   $email=trim((string)($data['email']??''));
   if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Érvénytelen kapcsolattartói e-mail.');
   if($id)$pdo->prepare('UPDATE ad_advertisers SET name=?,contact_email=? WHERE id=?')->execute([$name,$email?:null,$id]);
   else{$pdo->prepare('INSERT INTO ad_advertisers(site_id,name,contact_email) VALUES(?,?,?)')->execute([$site,$name,$email?:null]);$id=(int)$pdo->lastInsertId();}
  }elseif($entity==='zone'){
   $name=mb_substr(trim((string)($data['name']??'')),0,190);
   $code=ad_slug((string)($data['code']??'')?:$name);
   $placement=(string)($data['placement']??'article');
   if(mb_strlen($name)<2||!$code||strlen($code)>100||!in_array($placement,['home','section','article','sidebar','video','layer'],true))throw new RuntimeException('Érvénytelen hirdetési zóna.');
   if($id)$pdo->prepare('UPDATE ad_zones SET name=?,code=?,placement=? WHERE id=?')->execute([$name,$code,$placement,$id]);
   else{$pdo->prepare('INSERT INTO ad_zones(site_id,name,code,placement) VALUES(?,?,?,?)')->execute([$site,$name,$code,$placement]);$id=(int)$pdo->lastInsertId();}
  }elseif($entity==='campaign'){
   $name=mb_substr(trim((string)($data['name']??'')),0,190);
   $advertiser=max(0,(int)($data['advertiser_id']??0));
   $q=$pdo->prepare('SELECT id FROM ad_advertisers WHERE id=? AND site_id=?');$q->execute([$advertiser,$site]);
   if(!$q->fetchColumn()||mb_strlen($name)<2)throw new RuntimeException('Érvénytelen hirdető vagy kampánynév.');
   $state=(string)($data['state']??'draft');
   if(!in_array($state,['draft','active','paused','finished'],true))throw new RuntimeException('Érvénytelen állapot.');
   $priority=(int)($data['priority']??50);if($priority<0||$priority>100)throw new RuntimeException('A prioritás 0–100 lehet.');
   $section=ad_slug((string)($data['section_slug']??''));if(mb_strlen($section)>190)throw new RuntimeException('Túl hosszú rovat.');
   $start=ad_date((string)($data['starts_at']??''));$end=ad_date((string)($data['ends_at']??''));
   if($start&&$end&&strcmp($start,$end)>=0)throw new RuntimeException('A kampány vége később legyen, mint a kezdete.');
   $args=[$name,$advertiser,$state,$priority,$section,$start,$end];
   if($id){$pdo->prepare('UPDATE ad_campaigns SET name=?,advertiser_id=?,state=?,priority=?,section_slug=?,starts_at=?,ends_at=? WHERE id=?')->execute([...$args,$id]);}
   else{$pdo->prepare('INSERT INTO ad_campaigns(name,advertiser_id,state,priority,section_slug,starts_at,ends_at,site_id) VALUES(?,?,?,?,?,?,?,?)')->execute([...$args,$site]);$id=(int)$pdo->lastInsertId();}
  }elseif($entity==='creative'){
   $name=mb_substr(trim((string)($data['name']??'')),0,190);
   $campaign=max(0,(int)($data['campaign_id']??0));$zone=max(0,(int)($data['zone_id']??0));
   $q=$pdo->prepare('SELECT id FROM ad_campaigns WHERE id=? AND site_id=?');$q->execute([$campaign,$site]);if(!$q->fetchColumn())throw new RuntimeException('Érvénytelen kampány.');
   $q=$pdo->prepare('SELECT id FROM ad_zones WHERE id=? AND site_id=?');$q->execute([$zone,$site]);if(!$q->fetchColumn())throw new RuntimeException('Érvénytelen zóna.');
   $image=ad_url((string)($data['image_url']??''));$dest=ad_url((string)($data['destination_url']??''));
   $alt=mb_substr(trim((string)($data['alt_text']??'')),0,500);
   if(mb_strlen($name)<2||$alt==='')throw new RuntimeException('A kreatív neve és alternatív szövege kötelező.');
   $enabled=isset($data['enabled'])?1:0;
   $args=[$campaign,$zone,$name,$image,$dest,$alt,$enabled];
   if($id)$pdo->prepare('UPDATE ad_creatives SET campaign_id=?,zone_id=?,name=?,image_url=?,destination_url=?,alt_text=?,enabled=? WHERE id=?')->execute([...$args,$id]);
   else{$pdo->prepare('INSERT INTO ad_creatives(campaign_id,zone_id,name,image_url,destination_url,alt_text,enabled) VALUES(?,?,?,?,?,?,?)')->execute($args);$id=(int)$pdo->lastInsertId();}
  }
  ad_record($pdo,(int)$u['id'],$action,$entity,$id,$site);
  $pdo->commit();
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
