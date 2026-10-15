<?php
declare(strict_types=1);
// Per-site feature flags are additive; turning a flag off must never delete stored content.
function cms_site_defaults():array {
 return [
  'builder_enabled'=>false,
  'reader_accounts_enabled'=>false,
  'comments_enabled'=>false,
  'newsletter_enabled'=>false,
  'ai_enabled'=>false,
  'publisher_name'=>'',
  'site_description'=>'',
  'accent_color'=>'#325de6',
  'logo_url'=>'',
  'ad_slots_enabled'=>false
 ];
}
function cms_site_settings(array $site):array {
 $raw=json_decode((string)($site['settings_json']??'{}'),true);
 return array_replace(cms_site_defaults(),is_array($raw)?$raw:[]);
}
function cms_validate_site_settings(array $input,array $existing=[]):array {
 $result=array_replace(cms_site_defaults(),$existing);
 foreach(['builder_enabled','reader_accounts_enabled','comments_enabled','newsletter_enabled','ai_enabled','ad_slots_enabled'] as $flag)$result[$flag]=isset($input[$flag])&&in_array((string)$input[$flag],['1','on','true'],true);
 foreach(['publisher_name'=>190,'site_description'=>500,'logo_url'=>1000] as $key=>$max){
  $text=trim((string)($input[$key]??''));if(mb_strlen($text)>$max)throw new RuntimeException('Túl hosszú webhelybeállítás: '.$key);
  $result[$key]=$text;
 }
 if($result['logo_url']!==''&&(!filter_var($result['logo_url'],FILTER_VALIDATE_URL)||!in_array(parse_url($result['logo_url'],PHP_URL_SCHEME),['http','https'],true)))throw new RuntimeException('Érvénytelen logó URL.');
 $accent=strtolower(trim((string)($input['accent_color']??'#325de6')));
 if(!preg_match('/^#[0-9a-f]{6}$/D',$accent))throw new RuntimeException('A kiemelőszín formátuma érvénytelen.');
 $result['accent_color']=$accent;
 return $result;
}
