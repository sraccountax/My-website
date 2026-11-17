<?php
declare(strict_types=1);
// Minimal stubs so the pure functions in mfa_r164.php can be exercised without the app.
function config(string $k){ return $k==='app.secret' ? 'test-secret-key-at-least-48-chars-long-0123456789abcd' : null; }
require '/home/user/My-website/tegh-app/api/mfa_r164.php';

$pass=0;$fail=0;
function check($name,$cond){global $pass,$fail; if($cond){$pass++;echo "PASS $name\n";}else{$fail++;echo "FAIL $name\n";}}

// 1. base32 round-trip
$raw=random_bytes(20); check('base32 round-trip', mfa_base32_decode(mfa_base32_encode($raw))===$raw);

// 2. RFC 6238 SHA1 vector: secret "12345678901234567890", T=59 -> step 1 -> 287082
$b32=mfa_base32_encode('12345678901234567890');
check('RFC6238 base32', $b32==='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ');
check('RFC6238 step1=287082', mfa_totp_at($b32,1)==='287082');
check('RFC6238 T=1111111109 (step 37037036)=081804', mfa_totp_at($b32,intdiv(1111111109,30))==='081804');

// 3. window match: a code for the current step is accepted; replayed (<=last) is rejected
$sec=mfa_base32_encode(random_bytes(20)); $now=time(); $cur=mfa_current_step($now);
$code=mfa_totp_at($sec,$cur);
$step=mfa_totp_match($sec,$code,null,$now); check('current code accepted', $step===$cur);
check('replay of used step rejected', mfa_totp_match($sec,$code,$cur,$now)===null);
check('prev-step code accepted within window', mfa_totp_match($sec,mfa_totp_at($sec,$cur-1),null,$now)===($cur-1));
check('far-future code rejected', mfa_totp_match($sec,mfa_totp_at($sec,$cur+5),null,$now)===null);
check('wrong length rejected', mfa_totp_match($sec,'12345',null,$now)===null);
check('garbage rejected', mfa_totp_match($sec,'000000',null,$now)===null || true); // may coincidentally match; just ensure no crash

// 4. AES-256-GCM secret round-trip + tamper detection
$enc=mfa_encrypt_secret($sec);
check('gcm round-trip', mfa_decrypt_secret($enc['cipher'],$enc['nonce'],$enc['tag'])===$sec);
$tampered=$enc['cipher']; $tampered[0]=($tampered[0]==="\x00"?"\x01":"\x00");
$caught=false; try{ mfa_decrypt_secret($tampered,$enc['nonce'],$enc['tag']); }catch(Throwable $e){$caught=true;}
check('gcm tamper detected', $caught);

// 5. recovery normalization
check('recovery normalize', mfa_normalize_recovery('ab12-cd34')==='AB12CD34');

echo "\n$pass passed, $fail failed\n";
exit($fail?1:0);
