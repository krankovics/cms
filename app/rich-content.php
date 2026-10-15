<?php
declare(strict_types=1);
/** Pure safe rendering helpers for imported and manually edited Quill content. */
function cms_rich_escape(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function cms_rich_url(string $raw):string{
 if(!filter_var($raw,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($raw,PHP_URL_SCHEME)),['http','https'],true))return '';
 return mb_substr($raw,0,2000);
}
function cms_rich_piece(string $text,array $attrs):string{
 $out=cms_rich_escape($text);
 foreach(['bold'=>'strong','italic'=>'em','underline'=>'u','strike'=>'s'] as $key=>$tag)
  if(!empty($attrs[$key]))$out='<'.$tag.'>'.$out.'</'.$tag.'>';
 if(!empty($attrs['background']))$out='<mark>'.$out.'</mark>';
 if(isset($attrs['link'])){$link=cms_rich_url((string)$attrs['link']);if($link)$out='<a href="'.cms_rich_escape($link).'" rel="noopener noreferrer nofollow" target="_blank">'.$out.'</a>';}
 return $out;
}
function cms_rich_line(string $inner,array $attr):string{
 if($inner==='')return '';
 if(!empty($attr['header'])){$n=max(2,min(4,(int)$attr['header']+1));return '<h'.$n.'>'.$inner.'</h'.$n.'>';}
 if(!empty($attr['blockquote']))return '<blockquote>'.$inner.'</blockquote>';
 if(!empty($attr['list']))return '<p class="pub-rich-list">'.(!empty($attr['list'])&&$attr['list']==='ordered'?'1. ':'• ').$inner.'</p>';
 return '<p>'.$inner.'</p>';
}
function cms_rich_figure(array $v):string{
 $src=cms_rich_url((string)($v['src']??''));if(!$src)return '';
 $alt=cms_rich_escape(mb_substr((string)($v['alt']??''),0,500));
 $caption=cms_rich_escape(mb_substr((string)($v['caption']??''),0,2000));
 $credit=cms_rich_escape(mb_substr((string)($v['credit']??''),0,500));
 return '<figure class="pub-rich-figure"><img loading="lazy" src="'.cms_rich_escape($src).'" alt="'.$alt.'">'.($caption||$credit?'<figcaption>'.$caption.($credit?'<small>'.$credit.'</small>':'').'</figcaption>':'').'</figure>';
}
function cms_rich_table(array $v):string{
 $rows=$v['rows']??null;
 if(!is_array($rows)||count($rows)>60)return '';
 $html='<div class="pub-rich-table-wrap"><table>';
 foreach($rows as $cells){
  if(!is_array($cells)||count($cells)>15)continue;
  $html.='<tr>';
  foreach($cells as $cell){
   if(!is_array($cell))continue;
   $tag=!empty($cell['header'])?'th':'td';$span=max(1,min(10,(int)($cell['colspan']??1)));
   $html.='<'.$tag.' colspan="'.$span.'">'.cms_rich_escape(mb_substr((string)($cell['text']??''),0,2000)).'</'.$tag.'>';
  }
  $html.='</tr>';
 }
 return $html.'</table></div>';
}
function cms_rich_embed(array $v):string{
 $url=cms_rich_url((string)($v['url']??''));$provider=(string)($v['provider']??'');
 if(!$url)return '';
 $host=strtolower((string)parse_url($url,PHP_URL_HOST));
 $allowed=[
  'youtube'=>'www.youtube-nocookie.com','spotify'=>'open.spotify.com',
  'vimeo'=>'player.vimeo.com','instagram'=>'www.instagram.com'
 ];
 if(isset($allowed[$provider])&&$host===$allowed[$provider]){
  if($provider==='instagram'){
   $path=(string)parse_url($url,PHP_URL_PATH);
   if(preg_match('~^/(p|reel|tv)/[a-zA-Z0-9_-]+/?$~',$path))$url='https://www.instagram.com'.rtrim($path,'/').'/embed/';
   else return '';
  }
  return '<div class="pub-rich-embed pub-rich-embed-'.$provider.'"><iframe loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen title="'.cms_rich_escape(ucfirst($provider)).' beágyazás" src="'.cms_rich_escape($url).'"></iframe></div>';
 }
 // Other platforms can refuse third-party iframes. Keep a meaningful source card.
 if(!in_array($provider,['facebook','tiktok','soundcloud','apple-podcasts','x','instagram'],true))return '';
 return '<p class="pub-rich-embed-link"><a rel="noopener noreferrer nofollow" target="_blank" href="'.cms_rich_escape($url).'">'.cms_rich_escape(strtoupper($provider)).' beágyazott tartalom megnyitása ↗</a></p>';
}
function cms_rich_delta(string $text):string{
 $delta=json_decode($text,true);
 if(!is_array($delta)||!is_array($delta['ops']??null))return '';
 $out='';$line='';
 foreach(array_slice($delta['ops'],0,5000) as $op){
  if(!is_array($op))continue;
  $ins=$op['insert']??null;$attrs=is_array($op['attributes']??null)?$op['attributes']:[];
  if(is_array($ins)){
   if($line!==''){$out.=cms_rich_line($line,[]);$line='';}
   if(isset($ins['cmsFigure'])&&is_array($ins['cmsFigure']))$out.=cms_rich_figure($ins['cmsFigure']);
   elseif(isset($ins['cmsTable'])&&is_array($ins['cmsTable']))$out.=cms_rich_table($ins['cmsTable']);
   elseif(isset($ins['cmsEmbed'])&&is_array($ins['cmsEmbed']))$out.=cms_rich_embed($ins['cmsEmbed']);
   elseif(isset($ins['image'])&&is_string($ins['image']))$out.=cms_rich_figure(['src'=>$ins['image']]);
   continue;
  }
  if(!is_string($ins))continue;
  $segments=explode("\n",$ins);
  foreach($segments as $i=>$part){
   if($part!=='')$line.=cms_rich_piece($part,$attrs);
   if($i<count($segments)-1){$out.=cms_rich_line($line,$attrs);$line='';}
  }
 }
 if($line!=='')$out.=cms_rich_line($line,[]);
 return $out;
}
