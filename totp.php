<?php
declare(strict_types=1);
// RFC 4226/6238. Private database; no secrets leave this server except during enrolment.
function mfa_schema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS mfa_accounts (user_id INTEGER PRIMARY KEY, secret TEXT NOT NULL, last_counter INTEGER NOT NULL DEFAULT -1, recovery TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS mfa_attempts (identity TEXT PRIMARY KEY, attempts INTEGER NOT NULL, expires INTEGER NOT NULL)');
}
function mfa_base32(string $raw): string {
    $bits=''; foreach(str_split($raw) as $b) $bits.=str_pad(decbin(ord($b)),8,'0',STR_PAD_LEFT);
    $out=''; foreach(str_split($bits,5) as $part) $out.='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[bindec(str_pad($part,5,'0'))];
    return $out;
}
function mfa_decode(string $secret): string {
    if(!preg_match('/^[A-Z2-7]{32}$/D',$secret)) throw new RuntimeException('Invalid MFA key');
    $bits=''; foreach(str_split($secret) as $c) $bits.=str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c)),5,'0',STR_PAD_LEFT);
    $out=''; foreach(str_split($bits,8) as $b) if(strlen($b)===8) $out.=chr(bindec($b));
    return $out;
}
function mfa_code(string $secret,int $counter): string {
    $mac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),mfa_decode($secret),true);
    $offset=ord($mac[19])&15;
    $value=unpack('N',substr($mac,$offset,4))[1]&0x7fffffff;
    return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
}
function mfa_counter(string $secret,string $code,int $now): ?int {
    if(!preg_match('/^[0-9]{6}$/D',$code)) return null;
    $current=intdiv($now,30);
    foreach([0,-1,1] as $delta) if($current+$delta>=0 && hash_equals(mfa_code($secret,$current+$delta),$code)) return $current+$delta;
    return null;
}
function mfa_begin(array $user): void {
    if(!session_regenerate_id(true)) throw new RuntimeException('Session unavailable');
    $_SESSION=['mfa_pending'=>['id'=>(int)$user['id'],'version'=>(int)$user['session_version'],'until'=>time()+600],
        'csrf'=>bin2hex(random_bytes(32))];
}
function mfa_pending(array $user): bool {
    $p=$_SESSION['mfa_pending']??[];
    return ($p['id']??null)===(int)$user['id'] && ($p['version']??null)===(int)$user['session_version'] && ($p['until']??0)>time();
}
function mfa_session(array $user): bool {
    return ($_SESSION['mfa_verified']??null)===(int)$user['id'];
}
function mfa_record(PDO $db,int $id): ?array {
    mfa_schema($db);
    $q=$db->prepare('SELECT * FROM mfa_accounts WHERE user_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function mfa_limit(PDO $db,int $id,string $ip): void {
    foreach(['user:'.$id,'ip:'.$ip] as $identity){
        $key=hash('sha256',$identity);
        $q=$db->prepare('INSERT INTO mfa_attempts(identity,attempts,expires) VALUES(?,1,?) ON CONFLICT(identity) DO UPDATE SET attempts=CASE WHEN expires<=? THEN 1 ELSE attempts+1 END, expires=CASE WHEN expires<=? THEN excluded.expires ELSE expires END');
        $q->execute([$key,time()+900,time(),time()]);
        $q=$db->prepare('SELECT attempts FROM mfa_attempts WHERE identity=?');$q->execute([$key]);
        if((int)$q->fetchColumn()>($identity[0]==='u'?10:40)){http_response_code(429);throw new DomainException('Příliš mnoho pokusů. Zkuste to za 15 minut.');}
    }
}
function mfa_recovery_codes(): array {
    $codes=[];for($i=0;$i<10;$i++) $codes[]=bin2hex(random_bytes(8));return $codes;
}
function mfa_enrol(PDO $db,int $id,string $secret,string $code): array {
    $counter=mfa_counter($secret,$code,time());
    if($counter===null) throw new DomainException('Kód není správný. Zkontrolujte čas v telefonu a zadejte aktuální kód.');
    $codes=mfa_recovery_codes();
    $q=$db->prepare('INSERT OR IGNORE INTO mfa_accounts(user_id,secret,last_counter,recovery) VALUES(?,?,?,?)');
    $q->execute([$id,$secret,$counter,json_encode(array_map(static fn($c)=>hash('sha256',$c),$codes),JSON_THROW_ON_ERROR)]);
    if($q->rowCount()!==1) throw new DomainException('Ověřování už bylo nastavené. Přihlaste se znovu.');
    return $codes;
}
function mfa_verify(PDO $db,int $id,string $code): bool {
    $db->exec('BEGIN IMMEDIATE');
    try{
        $record=mfa_record($db,$id);
        if(!$record){$db->exec('ROLLBACK');return false;}
        $counter=mfa_counter($record['secret'],$code,time());
        if($counter!==null && $counter>(int)$record['last_counter']){
            $db->prepare('UPDATE mfa_accounts SET last_counter=? WHERE user_id=?')->execute([$counter,$id]);
            $db->exec('COMMIT');return true;
        }
        if(preg_match('/^[a-f0-9]{16}$/D',$code)){
            $codes=json_decode($record['recovery'],true,512,JSON_THROW_ON_ERROR);
            $hash=hash('sha256',$code);
            foreach($codes as $i=>$candidate) if(hash_equals($candidate,$hash)){
                unset($codes[$i]);$db->prepare('UPDATE mfa_accounts SET recovery=? WHERE user_id=?')->execute([json_encode(array_values($codes),JSON_THROW_ON_ERROR),$id]);
                $db->exec('COMMIT');return true;
            }
        }
        $db->exec('ROLLBACK');return false;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
