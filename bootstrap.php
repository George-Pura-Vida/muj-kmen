<?php
declare(strict_types=1);
const MK_VERSION = '1.0.0';
$local = is_file(__DIR__.'/config.local.php') ? require __DIR__.'/config.local.php' : [];
if (!is_array($local)) $local = [];
function setting(string $key, string $fallback=''): string { global $local; return (string)($local[$key] ?? getenv('MK_'.strtoupper($key)) ?: $fallback); }
require_once __DIR__.'/auth/bootstrap.php';
auth_boot();
$mkUser=auth_user();
function fail(int $status,string $message): never { http_response_code($status); echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE); exit; }
function database(): PDO {
 static $db=null; if ($db) return $db;
 $dir=setting('data_dir',dirname(__DIR__).'/muj-kmen-data'); if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Data directory not writable');
 if (!is_file($dir.'/.htaccess')) file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n");
 $db=new PDO('sqlite:'.$dir.'/muj-kmen.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('PRAGMA foreign_keys=ON'); $db->exec('PRAGMA busy_timeout=5000');
 $db->exec('CREATE TABLE IF NOT EXISTS clients(id TEXT PRIMARY KEY, payload TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS entries(id TEXT PRIMARY KEY, client_id TEXT NOT NULL REFERENCES clients(id), kind TEXT NOT NULL, body TEXT NOT NULL, result TEXT NOT NULL, occurred_at TEXT NOT NULL, created_at TEXT NOT NULL)');
 return $db;
}
function clean_text(mixed $v,int $max=1000): string { if (!is_string($v)) return ''; if (strlen($v)>$max*4) fail(422,'Text je příliš dlouhý.'); return trim($v); }
function valid_date(mixed $v): string { if ($v===null || $v==='') return ''; if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)) fail(422,'Neplatné datum.'); $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$v); if (!$dt || $dt->format('Y-m-d')!==$v) fail(422,'Neplatné datum.'); return $v; }
function normal_client(array $c): array {
 $id=(string)($c['id']??''); if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)) fail(422,'Neplatné ID klienta.');
 $g=$c['group']??'Mimo péči'; if (!in_array($g,['A','B','C','D','E','Mimo péči'],true)) fail(422,'Neplatná skupina.');
 $first=clean_text($c['firstName']??'',100);$last=clean_text($c['lastName']??'',150); if ($first==='' && $last==='') fail(422,'Doplňte jméno klienta.');
 $amount=$c['amount']??0; if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount<0 || (float)$amount>1e13) fail(422,'Neplatný objem.');
 $products=[];foreach(['gold','silver','funds','bitcoin','swiss','insurance'] as $key){$p=$c['products'][$key]??[]; $state=$p['state']??'verify'; if(!in_array($state,['record','confirmed','interest','verify'],true))fail(422,'Neplatný stav produktu.');$products[$key]=['state'=>$state,'evidence'=>clean_text($p['evidence']??'',2000)];}
 return ['id'=>$id,'firstName'=>$first,'lastName'=>$last,'group'=>$g,'amount'=>(float)$amount,'products'=>$products,'tradePotential'=>clean_text($c['tradePotential']??'',2000),'cooperationPotential'=>clean_text($c['cooperationPotential']??'',2000),'tradePriority'=>(bool)($c['tradePriority']??false),'cooperationPriority'=>(bool)($c['cooperationPriority']??false),'sourceNote'=>clean_text($c['sourceNote']??'',5000),'nextAction'=>clean_text($c['nextAction']??'',1500),'nextContact'=>valid_date($c['nextContact']??''),'sourceDate'=>valid_date($c['sourceDate']??'')];
}
function clients_list(PDO $db): array {$r=[];foreach($db->query('SELECT * FROM clients') as $row){$c=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);$c['revision']=(int)$row['revision'];$c['updatedAt']=$row['updated_at'];$r[]=$c;}return $r;}
