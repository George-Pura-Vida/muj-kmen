<?php
declare(strict_types=1);
/* DIANA read-only exact-name search. Fail closed; feature disabled until enabled. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function dianaRosterDeny(int $code): never { http_response_code($code); echo '{"ok":false}'; exit; }
$https=in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true);
if(!$https||($_SERVER['REQUEST_METHOD']??'')!=='POST')dianaRosterDeny(404);
// Reuse existing Diana full CRM authorization, no separate feature flag.\nif (getenv('DIANA_CRM_WRITE') !== 'all')dianaRosterDeny(403);
$hash=(string)getenv('DIANA_NOTE_TOKEN_SHA256');
$authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??'');
if(!preg_match('/^[a-f0-9]{64}$/D',$hash))dianaRosterDeny(404);
if(!preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$m))dianaRosterDeny(401);
if(!hash_equals($hash,hash('sha256',$m[1])))dianaRosterDeny(401);
$body=file_get_contents('php://input');
if(!is_string($body)||strlen($body)>512)dianaRosterDeny(413);
$input=json_decode($body,true);
$name=is_array($input)?($input['name']??null):null;
if(!is_string($name)||strlen($name)>160||preg_match('/[[:cntrl:]]/',$name))dianaRosterDeny(422);
$name=trim(preg_replace('/\s+/u',' ',$name)??'');
if(substr_count($name,' ')<1||strlen($name)<3)dianaRosterDeny(422);
require __DIR__.'/bootstrap.php';
try{
 $db=database();
 $norm=static fn(string $s):string => function_exists('mb_strtolower')?mb_strtolower(trim($s),'UTF-8'):strtolower(trim($s));
 $key=$norm($name);
 $rows=$db->query("SELECT id,payload FROM clients WHERE deleted_at='' LIMIT 20000");
 $matches=[];
 foreach($rows as $row){
  $c=json_decode((string)$row['payload'],true,64,JSON_THROW_ON_ERROR);
  if(!is_array($c))continue;
  $full=trim((string)($c['firstName']??'').' '.(string)($c['lastName']??''));
  if($norm($full)!==$key)continue;
  $matches[]=['id'=>(string)$row['id'],'first_name'=>(string)($c['firstName']??''),
   'last_name'=>(string)($c['lastName']??''),'email'=>(string)($c['email']??''),
   'phone'=>(string)($c['phone']??'')];
  if(count($matches)>20)dianaRosterDeny(409);
 }
 echo json_encode(['ok'=>true,'matches'=>$matches],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){dianaRosterDeny(503);}
