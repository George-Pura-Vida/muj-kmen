<?php
declare(strict_types=1);
require __DIR__.'/auth/bootstrap.php';
auth_boot();
try {
$db=auth_db();$mfaName='Můj kmen';$mfaOrigin=auth_test()?'http://'.($_SERVER['HTTP_HOST']??'127.0.0.1'):AUTH_ORIGIN;$mfaLogin='/account.php?view=login';
$mfaFind=static function(int $id):?array{$u=auth_query('SELECT * FROM users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);return $u&&auth_approved($u)?$u:null;};
$mfaOpen=static function(array $u):void{auth_login($u,true);};
require __DIR__.'/mfa-page.php';
}catch(Throwable $e){http_response_code(503);error_log('MFA bootstrap failed: '.get_class($e));exit('Ověření je dočasně nedostupné.');}
