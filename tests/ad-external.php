<?php
declare(strict_types=1);
require __DIR__.'/../app/ad-external-import.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
if(!class_exists(ZipArchive::class)||!class_exists(XMLReader::class)){fwrite(STDERR,"ZipArchive and XMLReader required for XLSX import.\n");exit(1);}
$columns=['node id','parent id','node name','node type','node active','master/slave','node slave','master name','node depth','master code id','leaf code id','node path','code'];
$rows=[
 ['1','938','Network','FOLDER','Y','N','N','','1','','','Network',''],
 ['10','1','Publication','FOLDER','Y','N','N','','2','','','Network/Publication',''],
 ['20','10','Home_MASTER','MASTER','Y','Y','N','','3','Master.abC-5','','Network/Publication/Home_MASTER',"<script>ado.master({id:'Master.abC-5',server:'hu.adocean.pl'});</script>"],
 ['21','20','Banner','LEAF','Y','Y','Y','Network || Publication || Home_MASTER','4','Master.abC-5','adoceanslave1','Network/Publication/Home_MASTER/Banner',"<div id='adoceanslave1'></div><script>ado.slave('adoceanslave1',{myMaster:'Master.abC-5'});</script>"],
 ['22','10','Standalone','LEAF','Y','N','N','','3','','ado-Stand.alone','Network/Publication/Standalone',"<script>ado.placement({id:'ado-Stand.alone',server:'hu.adocean.pl'});</script>"]
];
function escapeXml(string $text):string{return htmlspecialchars($text,ENT_XML1|ENT_QUOTES,'UTF-8');}
function xmlRow(array $cells,int $number):string{
 $xml='<row r="'.$number.'">';
 foreach($cells as $i=>$value){
  $column=chr(65+$i);
  $xml.='<c r="'.$column.$number.'" t="inlineStr"><is><t xml:space="preserve">'.escapeXml((string)$value).'</t></is></c>';
 }
 return $xml.'</row>';
}
$xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
$xml.=xmlRow($columns,1);
foreach($rows as $i=>$r)$xml.=xmlRow($r,$i+2);
$xml.='</sheetData></worksheet>';
$tmp=tempnam(sys_get_temp_dir(),'ao-test-');
$zip=new ZipArchive();check($zip->open($tmp,(ZipArchive::CREATE|ZipArchive::OVERWRITE))===true,'Fixture ZIP creation failed.');
$zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();
try{
 $parsed=external_xlsx_rows($tmp);
 check(count($parsed)===5,'Expected five rows from inline-string XLSX.');
 check($parsed[3]['leaf code id']==='adoceanslave1','Leaf code lost in spreadsheet parser.');
 $validated=external_validate_nodes($parsed);
 check($validated['counts']===['FOLDER'=>2,'MASTER'=>1,'LEAF'=>2],'Incorrect node type counts.');
 check(count($validated['folders'])===1,'Expected one publication folder.');
 check($validated['folder_for']['21']===10&&$validated['folder_for']['22']===10,'Incorrect publication lineage.');
 $bad=$parsed;$bad[3]['master code id']='not-the-master';
 $threw=false;try{external_validate_nodes($bad);}catch(RuntimeException $e){$threw=true;}
 check($threw,'Cross-master SLAVE must fail validation.');
 $bad=$parsed;$bad[4]['node id']='21';$threw=false;
 try{external_validate_nodes($bad);}catch(RuntimeException $e){$threw=true;}
 check($threw,'Duplicate source ID must fail validation.');
 $bad=$parsed;$bad[4]['leaf code id']='"><script>'; $threw=false;
 try{external_validate_nodes($bad);}catch(RuntimeException $e){$threw=true;}
 check($threw,'Unsafe AdOcean identifiers must fail validation.');
 echo "AdOcean XLSX import parser and validations: OK\n";
}finally{@unlink($tmp);}
