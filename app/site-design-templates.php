<?php
declare(strict_types=1);
/**
 * Imported publisher appearances can be reused as a webhely template.
 * IDs refer only to previously validated, locally stored source profiles.
 * No user-supplied paths, remote stylesheets, or source scripts are rendered.
 */
require_once __DIR__.'/import-source-style.php';

function cms_design_template_dir():string{
 return dirname(__DIR__).'/storage/import/source-styles';
}
function cms_design_template_id(int $site,string $host):string{
 return hash('sha256',$site.'|'.strtolower($host));
}
function cms_design_template_read(string $id):?array{
 if(!preg_match('/^[a-f0-9]{64}$/D',$id))return null;
 $path=cms_design_template_dir().'/'.$id.'.json';
 if(!is_file($path)||filesize($path)>250000)return null;
 $profile=json_decode((string)@file_get_contents($path),true);
 if(!is_array($profile)||!is_array($profile['site_design']??null)||!is_string($profile['css']??null))return null;
 $site=(int)($profile['site_id']??0);$host=strtolower((string)($profile['host']??''));
 if($site<1||!preg_match('/^[a-z0-9.-]{3,253}$/D',$host)||!hash_equals($id,cms_design_template_id($site,$host)))return null;
 if(strlen($profile['css'])>40000)return null;
 return $profile;
}
/** All collected sources are available for assignment by platform admins. */
function cms_design_templates_list():array{
 $dir=cms_design_template_dir();
 if(!is_dir($dir))return [];
 $files=glob($dir.'/*.json')?:[];
 $items=[];
 foreach(array_slice($files,0,2000) as $file){
  $id=basename($file,'.json');
  $p=cms_design_template_read($id);
  if(!$p)continue;
  $title=trim((string)($p['site_design']['name']??''))?:$p['host'];
  $items[]=['id'=>$id,'title'=>$title,'host'=>$p['host'],'site_id'=>(int)$p['site_id'],
   'saved_at'=>(int)($p['saved_at']??0),'reviewed'=>!empty($p['site_design_reviewed'])];
 }
 usort($items,static fn($a,$b)=>($b['saved_at']<=>$a['saved_at'])?:strcmp($a['title'],$b['title']));
 return $items;
}
/** An empty identifier means "no imported appearance assigned". */
function cms_design_template_validate_selection(string $id):string{
 if($id==='')return '';
 if(!cms_design_template_read($id))throw new RuntimeException('A kiválasztott arculati sablon nem található. Frissítsd a sablonlistát.');
 return $id;
}
function cms_design_template_for_site(array $site):?array{
 require_once __DIR__.'/site-settings.php';
 $settings=cms_site_settings($site);
 $id=(string)($settings['source_template_id']??'');
 return $id!==''?cms_design_template_read($id):null;
}
