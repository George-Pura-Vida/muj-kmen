import {spawn} from 'node:child_process';
import {readFileSync,mkdirSync} from 'node:fs';
import {resolve} from 'node:path';
const portIndex=process.argv.indexOf('--port');
const port=Number(portIndex>=0?process.argv[portIndex+1]:process.env.PORT||3000);
if(!Number.isInteger(port)||port<1||port>65535)throw Error('Invalid preview port');
let local={};try{local=JSON.parse(readFileSync('.sites-runtime/php-preview.json','utf8'));}catch{}
const php=process.env.PHP_BIN||(local.php?resolve(local.php):'php');
const ext=process.env.PHP_EXTENSION_DIR||(local.extensionDir?resolve(local.extensionDir):'');
const args=ext?['-n',...['pdo','pdo_sqlite','ctype'].flatMap(n=>['-d','extension='+ext+'/'+n+'.so'])]:[];
mkdirSync('.sites-runtime/sessions',{recursive:true});args.push('-d','session.save_path='+process.cwd()+'/.sites-runtime/sessions');
const child=spawn(php,[...args,'-S','0.0.0.0:'+port,'-t','.'],{stdio:'inherit',env:{...process.env,MUJKMEN_TEST_MODE:'1'}});
for(const sig of ['SIGTERM','SIGINT'])process.on(sig,()=>child.kill(sig));
child.on('exit',code=>process.exit(code??1));
