<?php
declare(strict_types=1);
require __DIR__.'/auth/bootstrap.php';
// Self-contained privacy view for Můj kmen.
auth_boot();
$views=['login','profile','privacy'];
$requested=is_string($_GET['view']??null)?$_GET['view']:'login';
$view=in_array($requested,$views,true)?$requested:'login';
if(!in_array($requested,$views,true) && $_SERVER['REQUEST_METHOD']==='GET'){http_response_code(403);exit('Registrace a obnova hesla jsou vypnuté. Kontaktujte správce hostingu.');}
$error='';$user=null;
try {
    auth_db();
    $user=auth_user();
    if($_SERVER['REQUEST_METHOD']==='POST')auth_process($user);
} catch(DomainException $e){$error=$e->getMessage();}
catch(Throwable $e){http_response_code(503);$error='Služba účtů je dočasně nedostupná. Zkuste to později nebo napište na info@jirijanousek.cz.';error_log('MŮJ KMEN account request failed: '.get_class($e));}
if($view==='profile'&&!$user)auth_redirect('login','Pro úpravu údajů se přihlaste.');
if($error===''&&$user&&in_array($view,['login','register'],true))auth_redirect('profile');
$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);
$titles=['login'=>'Přihlášení správce','profile'=>'Můj účet','privacy'=>'Údaje a soukromí'];
function field(string $name,string $label,string $type='text',bool $required=true,int $max=150,string $autocomplete=''):void{
    global $user,$view;
    $value=$_POST[$name]??($view==='profile'?($user[$name]??''):'');if(!is_string($value))$value='';if($view==='register' && $name==='email')$value=AUTH_APPROVER_EMAIL;
    echo '<label class="field"><span>'.h($label).($required?' <span class="required" aria-hidden="true">*</span>':'').'</span><input name="'.h($name).'" type="'.h($type).'" value="'.h($value).'" maxlength="'.$max.'" '.($required?'required ':'').($view==='register'&&$name==='email'?'readonly ':'').'autocomplete="'.h($autocomplete).'" '.($name==='ico'?'inputmode="numeric" pattern="[0-9]{8}" ':'').'></label>';
}
function password_field(string $name,string $label,string $autocomplete='new-password',bool $required=true):void{
    echo '<label class="field"><span>'.h($label).($required?' <span class="required" aria-hidden="true">*</span>':'').'</span><span class="password-wrap"><input id="'.h($name).'" name="'.h($name).'" type="password" '.($required?'required ':'').'maxlength="72" '.($autocomplete==='new-password'?'minlength="7" ':'').'autocomplete="'.h($autocomplete).'"><button class="eye" type="button" data-password="'.h($name).'" aria-controls="'.h($name).'" aria-label="Zobrazit heslo: '.h($label).'" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m3 3 18 18"/></svg></button></span></label>';
}
function csrf_fields(string $action,bool $token=false):void{
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'">';
    if($token){$v=$_POST['token']??'';echo '<input type="hidden" name="token" value="'.h(is_string($v)?$v:'').'">';}
}
function profile_fields():void{
    echo '<div class="fields-grid">';field('first_name','Jméno','text',true,150,'given-name');field('last_name','Příjmení','text',true,150,'family-name');
    field('company','Firma (nepovinné)','text',false,200,'organization');field('ico','IČ (nepovinné)','text',false,8);
    field('dic','DIČ (nepovinné, např. CZ12345678)','text',false,20);field('phone','Telefon pro kontaktní ověření','tel',true,40,'tel');
    echo '</div>';field('email','E-mail / přihlašovací jméno','email',true,254,'username');field('address','Adresa — ulice, číslo, PSČ a obec (nepovinné)','text',false,500,'street-address');
}
?>
<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="same-origin"><title><?= h($titles[$view]) ?> | Můj kmen</title><link rel="icon" href="/favicon.svg" type="image/svg+xml"><link rel="stylesheet" href="/account.css?v=1"><link rel="stylesheet" href="/account-ui.css?v=1"><script defer src="/account.js?v=1"></script></head>
<body>
<header class="account-header"><a class="brand" href="/"><img src="/favicon.svg" width="44" height="44" alt=""><span>MŮJ <strong>KMEN</strong><small>Jiří Janoušek</small></span></a>
<nav aria-label="Navigace účtu" class="account-nav"><a href="/">📊 Klientská matice</a>
<?php if($user): ?><a href="/account.php?view=profile">👤 Můj účet</a>
<?php else: ?><a href="/account.php?view=login">🔐 Přihlášení</a><?php endif; ?>
</nav></header>
<main class="account-layout<?= $view==='approvals'?' admin-layout':'' ?>"><aside class="welcome">
<p class="eyebrow">MŮJ KMEN · KLIENTI NA JEDNOM MÍSTĚ</p><h1>Vaši klienti.<br><em>Vaše dlouhodobé vztahy.</em></h1>
<p>Přihlaste se do svého klientského centra. Sledujte péči, produkty, příležitosti a další domluvené kroky.</p>
<div class="account-benefits"><p>🟡 Barevné skupiny a produktová matice</p><p>📈 Přehledné grafy a statistiky</p><p>✎ Zápisy, hledání a plánované kontakty</p></div>
<p class="welcome-note">Účet uchovává kontaktní údaje a přístupové heslo v zabezpečené podobě. Klientské údaje a zápisy jsou uložené na serveru a dostupné pouze povoleným účtům.</p></aside>
<section class="account-card" aria-labelledby="account-title"><p class="eyebrow">MŮJ ÚČET</p><h2 id="account-title"><span class="account-title-icon" aria-hidden="true"><?= in_array($view,['login','register'],true)?'👋':($view==='approvals'?'🛡️':'👤') ?></span><?= h($titles[$view]) ?></h2>
<?php if($error!==''):?><p class="message error" role="alert"><?= h($error) ?></p><?php endif;?>
<?php if($flash!==''):?><p class="message success" role="status"><?= h($flash) ?></p><?php endif;?>
<?php if($view==='login'): ?>
<p class="intro">Přihlaste se svým e-mailem a heslem. Aplikace je vyhrazena jedinému správci. Přihlášení a registrace dalších uživatelů nejsou povoleny. Účet je samostatný pro Můj kmen.</p><form method="post" action="/account.php?view=login"><?php csrf_fields('login');field('email','E-mail / login','email',true,254,'username');password_field('password','Heslo','current-password');?><button class="primary" type="submit">Přihlásit se →</button></form><p class="hint">Přístup má pouze Jiří Janoušek. Heslo lze spravovat pouze v administraci hostingu.</p>
<?php elseif($view==='profile'): ?>
<p class="intro">Přihlášený správce: <strong><?= h($user['email']) ?></strong>.</p><p class="message">Registrace dalších uživatelů je vypnutá. Heslo i identitu účtu lze spravovat pouze v databázi nebo v administraci hostingu.</p><a class="secondary" href="/">Přejít do klientské matice →</a><form class="logout" method="post" action="/account.php?view=profile"><?php csrf_fields('logout');?><button class="secondary" type="submit">Odhlásit se</button></form>
<?php elseif($view==='privacy'): ?>
<div class="privacy-copy"><p><strong>Správce osobních údajů:</strong> Jiří Janoušek, provozovatel aplikace Můj kmen. Kontakt pro uplatnění práv: <a href="mailto:info@jirijanousek.cz">info@jirijanousek.cz</a>.</p>
<h3>Jaké údaje a proč</h3><p>Pro registraci, přihlášení, správu profilu a bezpečnost účtu zpracováváme jméno, příjmení, e-mail, telefon, případně firmu, adresu, IČ a DIČ. Hesla uchováváme výhradně jako jednosměrné hashe; neposíláme je e-mailem. Údaje slouží pro provoz účtu, obnovu přístupu a nezbytná oznámení, nikoli automaticky pro marketing.</p>
<h3>Úložiště a nezbytné cookies</h3><p>Údaje účtů jsou uloženy mimo veřejný web na hostingu provozovatele. Pro přihlášení je nezbytná zabezpečená relační cookie a ochranný token formuláře; bez nich zabezpečený účet nefunguje. Zpracování vychází z poskytování požadované služby a oprávněného zájmu na zabezpečení.</p>
<h3>Doba uchování a Vaše práva</h3><p>Údaje vedeme po dobu existence účtu a případně déle v rozsahu právních povinností. Můžete požádat o přístup, opravu, výmaz, omezení zpracování či uplatnit další práva podle GDPR. Před vyřízením ověříme totožnost; stížnost lze podat Úřadu pro ochranu osobních údajů. Hosting a doručovatel e-mailů mohou údaje technicky zpracovávat v rozsahu potřebném pro provoz.</p>
<p>Klientské profily, produkty a zápisy se ukládají na serveru provozovatele. Přístup má výhradně jediný správce; registrace ani přihlášení dalších osob nejsou povoleny.</p>
<p><a href="https://jirijanousek.cz/ochrana-osobnich-udaju/" target="_blank" rel="noopener noreferrer">Další informace o ochraně osobních údajů</a>.</p></div>
<p class="form-links"><a href="/account.php?view=login">Přihlášení</a></p>
<?php endif; ?>

<nav class="footer-legal" aria-label="Právní informace"><a href="/account.php?view=privacy">Ochrana osobních údajů</a><a href="https://jirijanousek.cz/" target="_blank" rel="noopener noreferrer">jirijanousek.cz ↗</a></nav>
<p class="account-footer">© <?= date('Y') ?> Jiří Janoušek · Můj kmen</p></section></main></body></html>



