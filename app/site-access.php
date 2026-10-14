<?php
function allowed_resource_sites(PDO $pdo,array $user,string $resource):array {
 if($user['role']==='platform_admin')return array_merge([0],array_map('intval',$pdo->query('SELECT id FROM sites')->fetchAll(PDO::FETCH_COLUMN)));
 $column=$resource==='tags'?'can_tags':'can_media';
 $q=$pdo->prepare("SELECT site_id FROM user_site_permissions WHERE user_id=? AND (is_primary=1 OR $column=1)");
 $q->execute([$user['id']]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function allowed_primary_sites(PDO $pdo,array $user):array {
 if($user['role']==='platform_admin')return array_map('intval',$pdo->query('SELECT id FROM sites')->fetchAll(PDO::FETCH_COLUMN));
 $q=$pdo->prepare('SELECT site_id FROM user_site_permissions WHERE user_id=? AND is_primary=1');
 $q->execute([$user['id']]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
}
function require_resource_site(PDO $pdo,array $user,string $resource,int $siteId):void {
 if(!in_array($siteId,allowed_resource_sites($pdo,$user,$resource),true))throw new RuntimeException('Ehhez a webhelyhez nincs jogosultságod.');
}
