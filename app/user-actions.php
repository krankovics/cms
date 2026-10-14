<?php
declare(strict_types=1);
function save_admin_user(PDO $pdo,array $admin):void {
 if(!valid_csrf())throw new RuntimeException('Lejárt munkamenet.');
 $roles=['platform_admin','site_admin','editor','author','ad_manager'];
 $id=max(0,(int)($_POST['user_id']??0));
 $name=trim((string)($_POST['name']??''));
 $email=mb_strtolower(trim((string)($_POST['email']??'')));
 $role=(string)($_POST['role']??'author');
 $active=isset($_POST['active'])?1:0;
 if(mb_strlen($name)<2||mb_strlen($name)>190||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,$roles,true))throw new RuntimeException('Érvénytelen felhasználói adatok.');
 $password=(string)($_POST['new_password']??'');
 if($password!==''&&strlen($password)<14)throw new RuntimeException('A jelszó legalább 14 karakter legyen.');
 if($id){
  $q=$pdo->prepare('SELECT role,active FROM users WHERE id=?');$q->execute([$id]);$previous=$q->fetch();
  if(!$previous)throw new RuntimeException('Nem található felhasználó.');
  if($id===(int)$admin['id']&&(!$active||$role!=='platform_admin'))throw new RuntimeException('A saját adminfiók nem tiltható le.');
  if($previous['role']==='platform_admin'&&($role!=='platform_admin'||!$active)&&$previous['active']){
   if((int)$pdo->query("SELECT COUNT(*) FROM users WHERE active=1 AND role='platform_admin'")->fetchColumn()<2)throw new RuntimeException('Az utolsó szuperadmin nem tiltható le.');
  }
  if($password!==''){
   $q=$pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=?,password_hash=? WHERE id=?');
   $q->execute([$name,$email,$role,$active,password_hash($password,PASSWORD_DEFAULT),$id]);
  }else{
   $q=$pdo->prepare('UPDATE users SET name=?,email=?,role=?,active=? WHERE id=?');
   $q->execute([$name,$email,$role,$active,$id]);
  }
 }else{
  if($password==='')throw new RuntimeException('Új felhasználóhoz jelszó szükséges.');
  $q=$pdo->prepare('INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,?,?)');
  $q->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role,1]);$active=1;$id=(int)$pdo->lastInsertId();
 }
 $q=$pdo->prepare("INSERT INTO audit_log(user_id,action,object_type,object_id,meta_json) VALUES(?,'user.save','user',?,?)");
 $q->execute([$admin['id'],$id,json_encode(['role'=>$role,'active'=>(int)$active])]);
}