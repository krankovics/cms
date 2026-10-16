<?php
declare(strict_types=1);
/** Read saved zone placement rules. Only enabled zones assigned to this site
 * and enabled public page types are emitted to the browser. */
function cms_ad_placements(PDO $pdo,int $siteId,string $kind):array{
 if($siteId<1||!in_array($kind,['home','section','tag','article','page','login'],true))return [];
 $q=$pdo->prepare("SELECT z.code,r.position_type,r.position_value,r.css_selector,r.device_target,r.width,r.height,r.page_types FROM ad_zones z JOIN ad_zone_rules r ON r.zone_id=z.id WHERE z.site_id=? AND z.enabled=1 AND r.position_type<>'manual' ORDER BY z.id LIMIT 25");
 $q->execute([$siteId]);
 $items=[];
 foreach($q->fetchAll() as $row){
  if(!in_array($kind,explode(',',(string)$row['page_types']),true))continue;
  $items[]=['zone'=>$row['code'],'position'=>$row['position_type'],'value'=>(int)$row['position_value'],'selector'=>$row['css_selector'],'device'=>$row['device_target'],'width'=>(int)$row['width'],'height'=>(int)$row['height']];
 }
 return $items;
}
