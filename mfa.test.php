<?php
declare(strict_types=1);
require __DIR__.'/totp.php';
function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$key=mfa_base32('12345678901234567890');
foreach([59=>'287082',1111111109=>'081804',1111111111=>'050471',1234567890=>'005924',2000000000=>'279037',20000000000=>'353130'] as $time=>$code){
check(mfa_code($key,intdiv($time,30))===$code,'RFC6238 SHA1 vector '.$time);
check(mfa_counter($key,$code,$time)!==null,'verify RFC vector');
}
check(mfa_decode($key)==='12345678901234567890','base32 roundtrip');
check(mfa_counter($key,'12345',59)===null,'short code');
check(mfa_counter($key,'287082x',59)===null,'strict code');
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
mfa_schema($db);$now=intdiv(time(),30);
$codes=mfa_enrol($db,1,$key,mfa_code($key,$now));
check(count($codes)===10,'recovery count');
check(!mfa_verify($db,1,mfa_code($key,$now)),'enrolment code replay blocked');
check(mfa_verify($db,1,mfa_code($key,$now+1)),'valid next counter');
check(!mfa_verify($db,1,mfa_code($key,$now+1)),'TOTP replay blocked');
check(mfa_verify($db,1,$codes[0]),'recovery accepted');
check(!mfa_verify($db,1,$codes[0]),'recovery single use');
check(!str_contains(mfa_record($db,1)['recovery'],$codes[1]),'only hashed recovery stored');
check(!mfa_verify($db,2,$codes[1]),'no cross-account recovery');
try{mfa_enrol($db,1,$key,mfa_code($key,$now));throw new RuntimeException('enrolment overwrite');}catch(DomainException $expected){}
for($i=0;$i<10;$i++)mfa_limit($db,7,'test');
try{mfa_limit($db,7,'test');throw new RuntimeException('no rate limit');}catch(DomainException $expected){}
echo "PASS: RFC vectors, enrolment, replay, one-use recovery, rate limiting.\n";
