<?php
declare(strict_types=1);
function tx_slug(string $s):string{
 $s=mb_strtolower(trim($s));$s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;
 return trim((string)preg_replace('/[^a-z0-9]+/','-',$s),'-');
}
function tx_action(PDO $pdo,array $u,array $allowed,int $site,string $type):void{
 if(!valid_csrf())throw new RuntimeException('Érvénytelen munkamenet.');
 if(!in_array($u['role'],['platform_admin','site_admin','editor'],true))throw new RuntimeException('Nincs jogosultság.');
 require_resource_site($pdo,$u,'tags',$site);
 $action=(string)($_POST['action']??'create');
 $id=(int)($_POST['term_id']??0);
 $pdo->beginTransaction();
 try{
  if($action!=='create'){
   $q=$pdo->prepare('SELECT id,site_id FROM taxonomy_terms WHERE id=? AND type=?');
   $q->execute([$id,$type]);$existing=$q->fetch();
   if(!$existing||!in_array((int)$existing['site_id'],$allowed,true))throw new RuntimeException('Nem elérhető fogalom.');
  }
  if($action==='create'||$action==='update'){
   $name=trim((string)($_POST['name']??''));$slug=tx_slug((string)($_POST['slug']??'')?:$name);
   if(mb_strlen($name)<2||mb_strlen($name)>190||!$slug)throw new RuntimeException('Érvénytelen név vagy slug.');
   if($action==='create'){
    $q=$pdo->prepare('INSERT INTO taxonomy_terms(site_id,type,name,slug) VALUES(0,?,?,?)');
    $q->execute([$type,$name,$slug]);$id=(int)$pdo->lastInsertId();
   }else{
    $q=$pdo->prepare('UPDATE taxonomy_terms SET name=?,slug=? WHERE id=?');
    $q->execute([$name,$slug,$id]);
   }
   $parent=$type==='category'?(int)($_POST['parent_id']??0):0;
   if($parent){
    $q=$pdo->prepare("SELECT id FROM taxonomy_terms WHERE id=? AND type='category'");
    $q->execute([$parent]);if(!$q->fetchColumn())throw new RuntimeException('Érvénytelen szülőkategória.');
    $visited=[$id];$walk=$parent;
    while($walk){if(in_array($walk,$visited,true))throw new RuntimeException('Körkörös kategóriahierarchia.');$visited[]=$walk;
     $q=$pdo->prepare('SELECT parent_id FROM taxonomy_term_meta WHERE term_id=?');$q->execute([$walk]);$walk=(int)$q->fetchColumn();}
   }
   $desc=mb_substr(trim((string)($_POST['description']??'')),0,5000);
   $q=$pdo->prepare('INSERT INTO taxonomy_term_meta(term_id,description,parent_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE description=VALUES(description),parent_id=VALUES(parent_id)');
   $q->execute([$id,$desc,$parent?:null]);
  }elseif($action==='merge'){
   $target=(int)($_POST['target_id']??0);
   $q=$pdo->prepare('SELECT site_id FROM taxonomy_terms WHERE id=? AND type=?');$q->execute([$target,$type]);$targetSite=$q->fetchColumn();
   if($target===$id||$targetSite===false||!in_array((int)$targetSite,$allowed,true))throw new RuntimeException('Érvénytelen összevonási cél.');
   $q=$pdo->prepare('INSERT IGNORE INTO article_terms(article_id,term_id) SELECT article_id,? FROM article_terms WHERE term_id=?');$q->execute([$target,$id]);
   if($type==='category'){$q=$pdo->prepare('UPDATE taxonomy_term_meta SET parent_id=? WHERE parent_id=?');$q->execute([$target,$id]);}
   $pdo->prepare('DELETE FROM taxonomy_terms WHERE id=?')->execute([$id]);
  }elseif($action==='delete'){
   $pdo->prepare('DELETE FROM taxonomy_terms WHERE id=?')->execute([$id]);
  }else throw new RuntimeException('Ismeretlen művelet.');
  $pdo->commit();
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
}
