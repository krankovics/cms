<?php
declare(strict_types=1);
// Public versioned read-only content API. Never publishes draft or scheduled content.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
function api_fail(int $status,string $message):never{
 http_response_code($status);echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE);exit;
}
$siteInput=trim((string)($_GET['site']??''));
if($siteInput===''||strlen($siteInput)>190)api_fail(400,'Hiányzó vagy érvénytelen webhelyazonosító.');
$resource=(string)($_GET['resource']??'articles');
if(!in_array($resource,['articles','article','taxonomy'],true))api_fail(400,'Nem támogatott tartalomtípus.');
try{
 $cfg=require __DIR__.'/app/config.php';
 $pdo=new PDO('mysql:host='.($cfg['db_host']??'localhost').';dbname='.$cfg['db_name'].';charset=utf8mb4',$cfg['db_user'],$cfg['db_pass']??'',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
 ]);
 $q=$pdo->prepare('SELECT id,name,slug,domain FROM sites WHERE slug=? OR domain=? LIMIT 1');
 $q->execute([$siteInput,$siteInput]);$site=$q->fetch();
 if(!$site&&ctype_digit($siteInput)){
  $q=$pdo->prepare('SELECT id,name,slug,domain FROM sites WHERE id=?');$q->execute([(int)$siteInput]);$site=$q->fetch();
 }
 if(!$site)api_fail(404,'A webhely nem található.');
 $domain=preg_replace('~^https?://~','',(string)$site['domain']);$domain=rtrim($domain,'/');
 $origin=(string)($_SERVER['HTTP_ORIGIN']??'');
 if($origin&&in_array(strtolower($origin),['https://'.strtolower($domain),'http://'.strtolower($domain)],true)){
  header('Access-Control-Allow-Origin: '.$origin);header('Vary: Origin');
 }
 $sid=(int)$site['id'];
 $scope='(a.site_id=:site_primary OR EXISTS (SELECT 1 FROM article_sites a_sites WHERE a_sites.article_id=a.id AND a_sites.site_id=:site_shared))';
 $pub=" AND a.status='published' AND (a.published_at IS NULL OR a.published_at<=NOW())";
 $payload=['ok'=>true,'site'=>['id'=>$sid,'name'=>$site['name'],'slug'=>$site['slug']]];
 if($resource==='article'){
  $articleInput=trim((string)($_GET['article']??''));if($articleInput===''||strlen($articleInput)>255)api_fail(400,'Hiányzó cikkazonosító.');
  $q=$pdo->prepare('SELECT a.id,a.title,a.slug,a.\u0060lead\u0060,a.content_json,a.published_at,a.updated_at,u.name author_name,m.seo_title,m.seo_description,m.canonical_url,m.og_image_url FROM articles a LEFT JOIN users u ON u.id=a.author_id LEFT JOIN article_meta m ON m.article_id=a.id WHERE '.$scope.$pub.' AND (a.slug=:slug OR a.id=:article_id) LIMIT 1');
  $q->execute(['site_primary'=>$sid,'site_shared'=>$sid,'slug'=>$articleInput,'article_id'=>ctype_digit($articleInput)?(int)$articleInput:0]);
  $article=$q->fetch();if(!$article)api_fail(404,'A cikk nem érhető el.');
  $article['content']=json_decode((string)$article['content_json'],true)?:[];unset($article['content_json']);
  $q=$pdo->prepare('SELECT t.name,t.slug,t.type FROM article_terms at JOIN taxonomy_terms t ON t.id=at.term_id WHERE at.article_id=? AND (t.site_id=0 OR t.site_id=?) ORDER BY t.type,t.name');
  $q->execute([$article['id'],$sid]);$article['terms']=$q->fetchAll();
  $payload['article']=$article;
 }elseif($resource==='taxonomy'){
  $kind=(string)($_GET['kind']??'category');if(!in_array($kind,['category','tag'],true))api_fail(400,'Érvénytelen taxonómiatípus.');
  $q=$pdo->prepare('SELECT t.id,t.name,t.slug,t.type,m.description,m.parent_id FROM taxonomy_terms t LEFT JOIN taxonomy_term_meta m ON m.term_id=t.id WHERE (t.site_id=0 OR t.site_id=?) AND t.type=? ORDER BY t.name LIMIT 1000');
  $q->execute([$sid,$kind]);$payload['terms']=$q->fetchAll();
 }else{
  $page=max(1,min(10000,(int)($_GET['page']??1)));$limit=max(1,min(50,(int)($_GET['limit']??20)));
  $where=' FROM articles a LEFT JOIN users u ON u.id=a.author_id LEFT JOIN article_meta m ON m.article_id=a.id WHERE '.$scope.$pub;
  $q=$pdo->prepare('SELECT COUNT(*)'.$where);
  $q->execute(['site_primary'=>$sid,'site_shared'=>$sid]);$total=(int)$q->fetchColumn();
  $offset=($page-1)*$limit;
  $q=$pdo->prepare('SELECT a.id,a.title,a.slug,a.\u0060lead\u0060,a.published_at,a.updated_at,u.name author_name,m.og_image_url'.$where.' ORDER BY a.published_at DESC,a.id DESC LIMIT '.$limit.' OFFSET '.$offset);
  $q->execute(['site_primary'=>$sid,'site_shared'=>$sid]);
  $payload['items']=$q->fetchAll();$payload['pagination']=['page'=>$page,'limit'=>$limit,'total'=>$total,'pages'=>(int)ceil($total/$limit)];
 }
 $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $etag='"'.hash('sha256',$json).'"';header('ETag: '.$etag);
 if(trim((string)($_SERVER['HTTP_IF_NONE_MATCH']??''))===$etag){http_response_code(304);exit;}
 echo $json;
}catch(PDOException $e){error_log('comesy public API: '.$e->getMessage());api_fail(503,'A publikus tartalom API átmenetileg nem érhető el.');}
catch(Throwable $e){error_log('comesy public API: '.$e->getMessage());api_fail(500,'Feldolgozási hiba.');}
