<?php
// TEST ONLY: give a pending invitation an old-style (pre-R149) link token, so the link path can be exercised.
$cfg=require '/srv/gate/sr-accountax-private/config.php';$secret=(string)($cfg['app']['secret']??'');
[$_, $email]=$argv;$pdo=new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=tegh_gate','root','');
$id=$pdo->query("SELECT id FROM account_invitations WHERE email=".$pdo->quote($email)." AND status='pending' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
if(!$id){fwrite(STDERR,"no pending invitation\n");exit(1);}
$token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$pdo->prepare("INSERT INTO account_invitation_tokens (id,invitation_id,token_hash,expires_at) VALUES (?,?,?,UTC_TIMESTAMP()+INTERVAL 72 HOUR)")->execute(['invtoken_legacy'.bin2hex(random_bytes(6)),$id,hash_hmac('sha256',$token,$secret)]);
echo $token;
