<?php
declare(strict_types=1);
/** Native actions behind imported appearance chrome. No source scripts or auth flows. */
function cms_native_site_url(string $slug,bool $preview=false):string{
 return '/public.php?site='.rawurlencode($slug).($preview?'&preview=1':'');
}
function cms_native_href(string $href,string $slug,bool $preview=false):string{
 $base=cms_native_site_url($slug,$preview);
 if($href==='/')return $base;
 if(preg_match('~^/section/([a-z0-9_-]+)$~iD',$href,$m))return $base.'&section='.rawurlencode($m[1]);
 if(preg_match('~^/article/([a-z0-9_-]+)$~iD',$href,$m))return $base.'&article='.rawurlencode($m[1]);
 if(preg_match('~^/tag/([a-z0-9_-]+)$~iD',$href,$m))return $base.'&tag='.rawurlencode($m[1]);
 if(preg_match('~^/page/([a-z0-9_-]+)$~iD',$href,$m))return $base.'&page='.rawurlencode($m[1]);
 if($href==='/bejelentkezes'||$href==='/login')return $base.'&login=1';
 if(preg_match('~^/(?!/)[a-zA-Z0-9/_?&=.%-]{1,500}$~D',$href))return $href;
 if(strlen($href)<=1000&&filter_var($href,FILTER_VALIDATE_URL)&&parse_url($href,PHP_URL_SCHEME)==='https')return $href;
 return $base;
}
function cms_native_site_menu(PDO $pdo,int $site):array{
 $q=$pdo->prepare('SELECT label,url FROM site_menu_items WHERE site_id=? AND active=1 ORDER BY position,id LIMIT 40');
 $q->execute([$site]);
 return array_values(array_filter($q->fetchAll(),static fn($e)=>is_string($e['label']??null)&&is_string($e['url']??null)));
}
function cms_native_search(PDO $pdo,int $site,string $needle,bool $preview=false):array{
 $needle=trim($needle);
 if($site<1||$needle===''||mb_strlen($needle)>100)return [];
 // Escape LIKE metacharacters so even a query such as '%' means a literal search.
 $pattern='%'.addcslashes($needle,'\\%_').'%';
 $published=$preview?'':" AND a.status='published' AND (a.published_at IS NULL OR a.published_at<=UTC_TIMESTAMP())";
 $sql='SELECT a.slug,a.title,a.`lead`,a.published_at,m.og_image_url
   FROM articles a LEFT JOIN article_meta m ON m.article_id=a.id
   WHERE (a.site_id=? OR EXISTS (SELECT 1 FROM article_sites sa WHERE sa.article_id=a.id AND sa.site_id=?))
   '.$published.' AND (a.title LIKE ? OR a.`lead` LIKE ? OR a.content_json LIKE ?)
   ORDER BY a.published_at DESC,a.id DESC LIMIT 24';
 $q=$pdo->prepare($sql);
 $q->execute([$site,$site,$pattern,$pattern,$pattern]);
 return $q->fetchAll();
}
