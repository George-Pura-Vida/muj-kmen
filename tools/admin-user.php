<?php
// Run on the hosting terminal only. Never accepts a password in URL or arguments.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/auth/bootstrap.php';
fwrite(STDERR, "Heslo jediného správce (alespoň 12 znaků; přes stdin):\n");
$password = rtrim((string)fgets(STDIN), "\r\n");
if (strlen($password)<12 || strlen($password)>72) { fwrite(STDERR,"Heslo musí mít 12–72 bajtů.\n"); exit(1); }
$db=auth_db();$db->beginTransaction();
try {
 $now=time();$hash=auth_hash($password);unset($password);
 auth_query('INSERT INTO users(email,password_hash,first_name,last_name,phone,address,verified,created_at,updated_at,approval_status) VALUES(?,?,?,?,?,?,1,?,?,?) ON CONFLICT(email) DO UPDATE SET password_hash=excluded.password_hash, verified=1, approval_status=excluded.approval_status, session_version=users.session_version+1, updated_at=excluded.updated_at',[AUTH_APPROVER_EMAIL,$hash,'Jiří','Janoušek','','',$now,$now,'approved']);
 auth_query('DELETE FROM tokens WHERE user_id IN (SELECT id FROM users WHERE email=?)',[AUTH_APPROVER_EMAIL]);
 $db->commit();fwrite(STDOUT,"Účet správce nastaven; původní přihlášení byla ukončena.\n");
} catch(Throwable $e){$db->rollBack();fwrite(STDERR,"Nastavení účtu selhalo.\n");exit(1);}
