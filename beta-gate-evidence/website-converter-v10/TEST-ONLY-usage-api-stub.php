<?php
// TEST-ONLY authorization stand-in: always authorizes, no row limit.
header('Content-Type: application/json');
$in=json_decode(file_get_contents('php://input'),true)?:[];
echo json_encode(['ok'=>true,'token'=>'test-token','row_limit'=>($in['requested_mode']??'')==='free'?25:null,'access_type'=>($in['requested_mode']??'')==='free'?'free':'pro']);
