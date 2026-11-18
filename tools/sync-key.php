<?php
/** Provision private service credentials on the actual hosting machine. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../auth/bootstrap.php';
$destination=$argv[1]??'';
$days=filter_var($argv[2]??'90',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>720]]);
if($days===false){fwrite(STDERR,"Validity must be between 1 and 720 days.\n");exit(1);}
if($destination===''||$destination[0]!=='/'||file_exists($destination)){fwrite(STDERR,"Provide a new absolute credential-file path outside the web root.\n");exit(1);}
$parent=realpath(dirname($destination));$root=realpath(__DIR__.'/..');
if(!$parent||$parent===$root||str_starts_with($parent,$root.DIRECTORY_SEPARATOR)){fwrite(STDERR,"Credentials must remain outside the web root.\n");exit(1);}
if(is_file(auth_dir().'/diana-sync.json')){fwrite(STDERR,"Credentials already exist. Revoke the private diana-sync.json explicitly before rotation.\n");exit(1);}
umask(0077);
$read=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$write=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
$expires=time()+$days*86400;
$client=['origin'=>AUTH_ORIGIN,'readToken'=>$read,'writeToken'=>$write,'expiresAt'=>$expires];
$f=fopen($destination,'x');if(!$f)exit(1);fwrite($f,json_encode($client,JSON_PRETTY_PRINT)."\n");fclose($f);chmod($destination,0600);
$file=auth_dir().'/diana-sync.json';$f=fopen($file,'x');if(!$f){unlink($destination);exit(1);}
fwrite($f,json_encode(['read'=>hash('sha256',$read),'write'=>hash('sha256',$write),'expiresAt'=>$expires]));fclose($f);chmod($file,0600);
fwrite(STDOUT,"Service credentials created in the private destination; server stores hashes only. Expires in ".$days." days.\n");
