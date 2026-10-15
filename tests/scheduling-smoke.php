<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$pdo=db();
$q=$pdo->prepare("SELECT id,status,published_at FROM articles WHERE slug='test-scheduled' LIMIT 1");$q->execute([]);$row=$q->fetch();
if(!$row||$row['status']!=='published'){fwrite(STDERR,"Scheduled article was not published.\n");exit(1);}
$q=$pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE object_type='article' AND object_id=? AND action='article.publish_scheduled'");
$q->execute([$row['id']]);
if((int)$q->fetchColumn()<1){fwrite(STDERR,"Scheduled publication audit event missing.\n");exit(1);}
echo "PASS: due scheduled article published by CLI with audit.\n";
