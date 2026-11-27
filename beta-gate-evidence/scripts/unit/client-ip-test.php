<?php
// R164: unit test for the trusted-proxy client IP resolver (bootstrap.php), with config stubbed.
$src = file_get_contents($argv[1]);
preg_match('/const TEGH_CLOUDFLARE_RANGES = \[.*?\];/s', $src, $c);
preg_match('/function ip_in_cidr\(.*?\n}\n/s', $src, $f1);
preg_match('/function client_ip\(\).*?\n}\n/s', $src, $f2);
$CFG = [];
function config($k) { global $CFG; return $CFG[$k] ?? null; }
eval($c[0] . $f1[0] . $f2[0]);
$pass = 0; $fail = 0;
$ok = function ($name, $got, $want) use (&$pass, &$fail) { if ($got === $want) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name got=" . var_export($got, true) . " want=" . var_export($want, true) . "\n"; } };
$ok('cidr v4 in', ip_in_cidr('104.16.5.9', '104.16.0.0/13'), true);
$ok('cidr v4 out', ip_in_cidr('104.24.0.1', '104.16.0.0/13'), false);
$ok('cidr v6 in', ip_in_cidr('2606:4700::1', '2606:4700::/32'), true);
$ok('cidr family mismatch', ip_in_cidr('1.2.3.4', '2606:4700::/32'), false);
$ok('cidr garbage', ip_in_cidr('not-an-ip', '10.0.0.0/8'), false);
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'];
$ok('default: header ignored', client_ip(), '198.51.100.7');
$CFG['security.trusted_proxy'] = 'cloudflare';
$ok('cloudflare on, peer not Cloudflare: header ignored', client_ip(), '198.51.100.7');
$_SERVER['REMOTE_ADDR'] = '172.70.1.2';
$ok('cloudflare on, peer is Cloudflare: header used', client_ip(), '203.0.113.9');
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'evil, 1.1.1.1';
$ok('cloudflare on, malformed header: peer used', client_ip(), '172.70.1.2');
$CFG['security.trusted_proxy_ranges'] = ['10.0.0.0/8'];
$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.9';
$ok('custom ranges replace defaults', client_ip(), '172.70.1.2');
$_SERVER = [];
$ok('no peer', client_ip(), 'unknown');
echo "client-ip: $pass passed, $fail failed\n"; exit($fail ? 1 : 0);
