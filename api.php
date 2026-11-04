<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
 $db=database();$action=$_GET['action']??'bootstrap';$method=$_SERVER['REQUEST_METHOD'];
 if($method==='POST'){
  if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail(403,'Platnost formuláře vypršela. Obnovte stránku.');
  $origin=$_SERVER['HTTP_ORIGIN']??'';$expectedOrigin=auth_test()?'http://'.($_SERVER['HTTP_HOST']??'127.0.0.1'):AUTH_ORIGIN;if($origin!=='' && $origin!==$expectedOrigin)fail(403,'Nepovolený původ požadavku.');
  if((int)($_SERVER['CONTENT_LENGTH']??0)>12*1024*1024)fail(413,'Soubor je příliš velký.');
  $in=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);if(!is_array($in))fail(422,'Neplatná data.');
 }
 if($action==='bootstrap' && $method==='GET'){echo json_encode(['version'=>MK_VERSION,'csrf'=>$_SESSION['csrf'],'user'=>$mkUser?['id'=>$mkUser['id'],'name'=>$mkUser['first_name'].' '.$mkUser['last_name'],'isAdmin'=>auth_is_admin($mkUser)]:null],JSON_UNESCAPED_UNICODE);exit;}
 if(!$mkUser)fail(401,'Přihlaste se prosím.');
 if($action==='logout' && $method==='POST'){auth_logout();echo '{"ok":true}';exit;}
 if($action==='data' && $method==='GET'){echo json_encode(['clients'=>clients_list($db),'entries'=>$db->query('SELECT * FROM entries ORDER BY occurred_at DESC,created_at DESC')->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);exit;}
 if($action==='export' && $method==='GET'){header('Content-Disposition: attachment; filename="muj-kmen-zaloha-'.date('Y-m-d').'.json"');echo json_encode(['format'=>'muj-kmen','version'=>1,'exportedAt'=>gmdate(DATE_ATOM),'clients'=>clients_list($db),'entries'=>$db->query('SELECT * FROM entries')->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;}
 if($action==='import' && $method==='POST'){
  if(!auth_is_admin($mkUser))fail(403,'Import může provést pouze správce.');
  if(($in['format']??'')!=='muj-kmen' || ($in['version']??0)!==1 || !is_array($in['clients']??null) || count($in['clients'])>10000)fail(422,'Vyberte platný import Můj kmen (verze 1).');
  $clients=[];$seen=[];foreach($in['clients'] as $c){if(!is_array($c))fail(422,'Neplatný klient.');$c=normal_client($c);if(isset($seen[$c['id']]))fail(422,'Import obsahuje duplicitní ID.');$seen[$c['id']]=true;$clients[]=$c;}
  $entries=$in['entries']??[];if(!is_array($entries)||count($entries)>100000)fail(422,'Neplatné zápisy.');
  $db->beginTransaction();$added=0;$skipped=0;$q=$db->prepare('INSERT OR IGNORE INTO clients(id,payload,revision,updated_at) VALUES(?,?,1,?)');
  foreach($clients as $c){$q->execute([$c['id'],json_encode($c,JSON_UNESCAPED_UNICODE),gmdate(DATE_ATOM)]);if($q->rowCount())$added++;else $skipped++;}
  $notesAdded=0;foreach($entries as $e){if(!is_array($e)||!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',(string)($e['id']??'')))throw new InvalidArgumentException('Neplatný zápis.');$q=$db->prepare('SELECT 1 FROM clients WHERE id=?');$q->execute([(string)($e['client_id']??'')]);if(!$q->fetchColumn())throw new InvalidArgumentException('Zápis bez klienta.');$kind=$e['kind']??'note';if(!in_array($kind,['phone','meeting','email','note'],true))throw new InvalidArgumentException('Neplatný druh zápisu.');$date=valid_date(substr((string)($e['occurred_at']??''),0,10));if($date==='')throw new InvalidArgumentException('Neplatné datum zápisu.');$q=$db->prepare('INSERT OR IGNORE INTO entries VALUES(?,?,?,?,?,?,?)');$q->execute([(string)$e['id'],(string)$e['client_id'],$kind,clean_text($e['body']??'',5000),clean_text($e['result']??'',1000),$date,gmdate(DATE_ATOM)]);$notesAdded+=$q->rowCount();}
  $db->commit();echo json_encode(['ok'=>true,'added'=>$added,'skipped'=>$skipped,'notesAdded'=>$notesAdded]);exit;
 }
 if($action==='client' && $method==='POST'){
  $c=normal_client($in['client']??[]);$revision=(int)($in['revision']??0);$q=$db->prepare('UPDATE clients SET payload=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');$q->execute([json_encode($c,JSON_UNESCAPED_UNICODE),gmdate(DATE_ATOM),$c['id'],$revision]);if(!$q->rowCount())fail(409,'Klient byl mezitím změněn. Obnovte data a zopakujte úpravu.');echo '{"ok":true}';exit;
 }
 if($action==='entry' && $method==='POST'){
  $id=clean_text($in['id']??'',80);if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$id))fail(422,'Neplatné ID zápisu.');$client=(string)($in['clientId']??'');$body=clean_text($in['body']??'',5000);$kind=$in['kind']??'note';$date=valid_date($in['date']??'');if($body===''||$date===''||!in_array($kind,['phone','meeting','email','note'],true))fail(422,'Doplňte text, datum a druh zápisu.');
  $db->beginTransaction();$q=$db->prepare('SELECT payload,revision FROM clients WHERE id=?');$q->execute([$client]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!$r){$db->rollBack();fail(404,'Klient nebyl nalezen.');}
  $q=$db->prepare('SELECT 1 FROM entries WHERE id=?');$q->execute([$id]);if($q->fetchColumn()){$db->commit();echo '{"ok":true,"duplicate":true}';exit;}
  if((int)($in['revision']??0)!==(int)$r['revision']){$db->rollBack();fail(409,'Klient byl mezitím změněn. Obnovte data a zápis zopakujte.');}
  $c=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);$c['nextAction']=clean_text($in['nextAction']??$c['nextAction'],1500);$c['nextContact']=valid_date($in['nextContact']??$c['nextContact']);
  $db->prepare('INSERT INTO entries VALUES(?,?,?,?,?,?,?)')->execute([$id,$client,$kind,$body,clean_text($in['result']??'',1000),$date,gmdate(DATE_ATOM)]);
  $db->prepare('UPDATE clients SET payload=?,revision=revision+1,updated_at=? WHERE id=?')->execute([json_encode($c,JSON_UNESCAPED_UNICODE),gmdate(DATE_ATOM),$client]);$db->commit();echo '{"ok":true}';exit;
 }
 fail(404,'Požadavek nebyl nalezen.');
}catch(JsonException $e){fail(422,'Soubor neobsahuje platná JSON data.');}catch(InvalidArgumentException $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fail(422,$e->getMessage());}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('Muj kmen: '.$e->getMessage());fail(500,'Operaci se nepodařilo dokončit. Data nebyla potvrzena; zkuste to znovu.');}
