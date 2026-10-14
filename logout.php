<?php
require __DIR__.'/app/bootstrap.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!valid_csrf()){http_response_code(403);exit('Érvénytelen kérés.');}
$_SESSION=[];session_regenerate_id(true);session_destroy();header('Location: /login.php');exit;
