<?php
/** Dedicated Diana API. Disabled until private credentials are provisioned by CLI. */
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Vary: Authorization, Cookie');
function sync_fail(int $code, string $message): never { fail($code, $message); }
function sync_text(mixed $value, int $max, bool $required=false): string {
 if(!is_string($value) || strlen($value)>$max*4 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$value))sync_fail(422,'Invalid text.');
 $value=trim($value);if($required && $value==='')sync_fail(422,'Required text missing.');return $value;
}
function sync_id(mixed $value): string { if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$value))sync_fail(422,'Invalid identifier.');return $value; }
try {
 if(!auth_test() && ($_SERVER['HTTPS']??'')!=='on' && ($_SERVER['SERVER_PORT']??'')!=='443')sync_fail(403,'HTTPS required.');
 $file=auth_dir().'/diana-sync.json';
 if(!is_file($file))sync_fail(503,'Diana sync is not configured.');
 $keys=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
 $header=$_SERVER['HTTP_AUTHORIZATION']??'';
 if(!preg_match('/^Bearer ([A-Za-z0-9_-]{43,128})$/D',$header,$m))sync_fail(401,'Service authentication required.');
 $digest=hash('sha256',$m[1]);$scope='';
 foreach(['read','write'] as $candidate)if(is_string($keys[$candidate]??null)&&hash_equals($keys[$candidate],$digest))$scope=$candidate;
 if($scope==='')sync_fail(401,'Service authentication required.');
 if((int)($keys['expiresAt']??0)<=time())sync_fail(401,'Service credentials expired.');
 // No browser session, CSRF exception, user login, or permissive CORS is granted.
 session_write_close();
 $method=$_SERVER['REQUEST_METHOD'];
 if($method==='GET'){
  $db=database();$db->beginTransaction();
  $clients=clients_list($db);
  $entries=$db->query("SELECT entries.* FROM entries JOIN clients ON clients.id=entries.client_id WHERE clients.deleted_at='' ORDER BY occurred_at DESC,created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
  $db->commit();echo json_encode(['format'=>'muj-kmen-sync','version'=>1,'fetchedAt'=>gmdate(DATE_ATOM),'clients'=>$clients,'entries'=>$entries],JSON_UNESCAPED_UNICODE);exit;
 }
 if($method!=='POST')sync_fail(405,'Only GET and POST are supported.');
 if($scope!=='write')sync_fail(403,'Write scope required.');
 if(($_SERVER['HTTP_ORIGIN']??'')!=='')sync_fail(403,'Browser requests are not supported.');
 if(strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json')sync_fail(415,'JSON required.');
 $raw=file_get_contents('php://input',false,null,0,262145);if(strlen($raw)>262144)sync_fail(413,'Request too large.');
 $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($body)||array_diff(array_keys($body),['operationId','clientId','revision','type','nextAction','nextContact','entry']))sync_fail(422,'Unsupported fields.');
 $op=sync_id($body['operationId']??null);$cid=sync_id($body['clientId']??null);$type=$body['type']??'';
 if(!in_array($type,['plan','entry'],true))sync_fail(422,'Only plans and entries may be written.');
 if(!is_int($body['revision']??null)||$body['revision']<1)sync_fail(422,'Revision required.');
 $db=database();
 $db->exec('CREATE TABLE IF NOT EXISTS diana_sync_operations(id TEXT PRIMARY KEY, request_hash TEXT NOT NULL, response TEXT NOT NULL, created_at TEXT NOT NULL)');
 $db->exec('BEGIN IMMEDIATE');
 $q=$db->prepare('SELECT request_hash,response FROM diana_sync_operations WHERE id=?');$q->execute([$op]);$previous=$q->fetch(PDO::FETCH_ASSOC);
 $requestHash=hash('sha256',$raw);
 if($previous){
  if(!hash_equals($previous['request_hash'],$requestHash)){$db->exec('ROLLBACK');sync_fail(409,'Operation ID reused with different content.');}
  $db->exec('COMMIT');echo $previous['response'];exit;
 }
 $q=$db->prepare("SELECT payload,revision FROM clients WHERE id=? AND deleted_at=''");$q->execute([$cid]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!$row){$db->exec('ROLLBACK');sync_fail(404,'Client not found.');}
 if((int)$row['revision']!==$body['revision']){$db->exec('ROLLBACK');sync_fail(409,'Client changed; review the latest data before retrying.');}
 $client=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
 if(array_key_exists('nextAction',$body))$client['nextAction']=sync_text($body['nextAction'],1500);
 if(array_key_exists('nextContact',$body))$client['nextContact']=valid_date($body['nextContact']);
 $now=gmdate(DATE_ATOM);
 if($type==='plan'){
  if(isset($body['entry'])||(!array_key_exists('nextAction',$body)&&!array_key_exists('nextContact',$body))){$db->exec('ROLLBACK');sync_fail(422,'Plan fields required.');}
 }else{
  $e=$body['entry']??null;
  if(!is_array($e)||array_diff(array_keys($e),['kind','date','body','result','source'])){$db->exec('ROLLBACK');sync_fail(422,'Invalid entry fields.');}
  $kind=$e['kind']??'';if(!in_array($kind,['phone','meeting','email','note'],true)){$db->exec('ROLLBACK');sync_fail(422,'Invalid entry kind.');}
  $date=valid_date($e['date']??'');if($date===''||$date>gmdate('Y-m-d')){$db->exec('ROLLBACK');sync_fail(422,'Entries must describe past or current events; use a plan for future work.');}
  $text=sync_text($e['body']??null,5000,true);$source=sync_text($e['source']??null,500,true);$result=sync_text($e['result']??'',1000);
  // A caller must cite a real source; this endpoint cannot verify its truth.
  $text.="\n\n[Diana 00 · zdroj: ".$source."]";
  $db->prepare('INSERT INTO entries VALUES(?,?,?,?,?,?,?)')->execute(['diana-'.$op,$cid,$kind,$text,$result,$date,$now]);
 }
 $db->prepare('UPDATE clients SET payload=?,revision=revision+1,updated_at=? WHERE id=?')->execute([json_encode($client,JSON_UNESCAPED_UNICODE),$now,$cid]);
 $response=json_encode(['ok'=>true,'operationId'=>$op,'revision'=>(int)$row['revision']+1,'updatedAt'=>$now]);
 $db->prepare('INSERT INTO diana_sync_operations VALUES(?,?,?,?)')->execute([$op,$requestHash,$response,$now]);
 $db->exec('COMMIT');echo $response;
}catch(JsonException|InvalidArgumentException $e){if(isset($db))try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}sync_fail(422,'Invalid data.');}
catch(Throwable $e){if(isset($db))try{$db->exec('ROLLBACK');}catch(Throwable $ignored){}error_log('Muj kmen sync: internal failure');sync_fail(500,'Sync failed; no operation confirmed.');}
