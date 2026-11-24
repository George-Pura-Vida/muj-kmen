<?php
declare(strict_types=1);
// Adapter provides $db, $mfaName, $mfaOrigin, $mfaLogin, $mfaFind, $mfaOpen.
header('Cache-Control: private, no-store, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
function mfa_h(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$error='';$codes=null;$record=null;$user=null;
try{
    mfa_schema($db);
    $pending=$_SESSION['mfa_pending']??[];
    $user=$mfaFind((int)($pending['id']??0));
    if(!$user || !mfa_pending($user)){header('Location: '.$mfaLogin,true,303);exit;}
    $id=(int)$user['id'];
    $record=mfa_record($db,$id);
    if(!$record) $_SESSION['mfa_setup']??=mfa_base32(random_bytes(20));
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
        $csrf=$_POST['csrf']??null;$origin=$_SERVER['HTTP_ORIGIN']??'';
        if(!is_string($csrf)||!hash_equals($_SESSION['csrf'],$csrf)||($origin!==''&&$origin!==$mfaOrigin)){
            http_response_code(403);throw new DomainException('Formulář vypršel. Obnovte stránku.');
        }
        if(($_POST['action']??'')==='cancel'){$_SESSION=['csrf'=>bin2hex(random_bytes(32))];session_regenerate_id(true);header('Location: '.$mfaLogin,true,303);exit;}
        mfa_limit($db,$id,$_SERVER['REMOTE_ADDR']??'unknown');
        $code=is_string($_POST['code']??null)?trim($_POST['code']):'';
        if(!$record){
            $codes=mfa_enrol($db,$id,$_SESSION['mfa_setup'],$code);
            unset($_SESSION['mfa_setup']);$record=mfa_record($db,$id);
        }elseif(!mfa_verify($db,$id,$code)){
            throw new DomainException('Kód není správný nebo už byl použit. Zadejte nový kód.');
        }
        $mfaOpen($user);
        $_SESSION['mfa_verified']=$id;
        if(!$codes){header('Location: /',true,303);exit;}
    }
}catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){http_response_code(503);error_log('MFA unavailable: '.get_class($e));exit('Ověření je dočasně nedostupné. Zkuste to později.');}
$secret=$record?'':($_SESSION['mfa_setup']??'');
$uri=$secret!==''?'otpauth://totp/'.rawurlencode($mfaName.':'.$user['email']).'?secret='.$secret.'&issuer='.rawurlencode($mfaName).'&algorithm=SHA1&digits=6&period=30':'';
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>Google Authenticator · <?=mfa_h($mfaName)?></title><script src="/vendor/qrcodegen.js" defer></script><script src="/mfa-qr.js" defer></script><style>
body{background:#f4f7f6;color:#192c27;font:17px system-ui;margin:0;padding:24px}main{max-width:540px;margin:5vh auto;background:white;padding:28px;border-radius:20px;box-shadow:0 5px 30px #1231}h1{font-size:25px}input,button{box-sizing:border-box;font:inherit;padding:13px;width:100%;margin:9px 0}button,a.primary{background:#176b50;color:white;border:0;border-radius:8px;cursor:pointer}code{overflow-wrap:anywhere}#mfa-qr svg{display:block;width:230px;height:230px;margin:18px auto;background:white;padding:10px}.error{color:#a52323}li{margin:9px 0}a{color:#176b50}
</style></head><body><main><p>🔐 <?=mfa_h($mfaName)?></p><h1>Google Authenticator</h1>
<?php if($codes):?><h2>Ověřování je zapnuté</h2><p>Uložte si tyto záložní kódy na bezpečné místo. Každý lze použít jednou místo kódu z telefonu. Znovu se nezobrazí.</p><ul><?php foreach($codes as $c):?><li><code><?=mfa_h($c)?></code></li><?php endforeach;?></ul><p><a href="/">Záložní kódy mám uložené – vstoupit do aplikace →</a></p>
<?php else:?>
<?php if($error!==''):?><p class="error" role="alert"><?=mfa_h($error)?></p><?php endif;?>
<?php if(!$record):?><p>Připojte tento účet v aplikaci Google Authenticator v telefonu: <strong>+ → Naskenovat QR kód</strong>.</p><div id="mfa-qr" data-uri="<?=mfa_h($uri)?>"></div><p>Pokud QR kód nelze načíst, přidejte klíč ručně jako časový:</p><p><code><?=mfa_h($secret)?></code></p><p>Účet: <?=mfa_h($mfaName.' · '.$user['email'])?>. Aktivaci dokončete zadáním kódu z telefonu.</p>
<?php else:?><p>Zadejte aktuální šestimístný kód z Google Authenticatoru pro <?=mfa_h($mfaName)?>. Případně použijte jeden záložní kód.</p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=mfa_h($_SESSION['csrf'])?>"><label for="code">Ověřovací kód</label><input id="code" name="code" autocomplete="one-time-code" maxlength="16" minlength="6" required autofocus spellcheck="false"><button type="submit"><?= $record?'Ověřit a přihlásit se':'Aktivovat Google Authenticator'?></button></form><form method="post"><input type="hidden" name="csrf" value="<?=mfa_h($_SESSION['csrf'])?>"><button name="action" value="cancel">Zpět k přihlášení</button></form><p>Přihlášení čeká na dokončení nejvýše 10 minut.</p>
<?php endif;?></main></body></html>
