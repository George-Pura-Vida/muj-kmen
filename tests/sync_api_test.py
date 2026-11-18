"""Isolated PHP/SQLite API tests with fake clients and temporary credentials."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from api_test import Session, ROOT


def run():
 with tempfile.TemporaryDirectory(prefix='muj-kmen-sync-test-') as tmp:
  tmp=Path(tmp);(tmp/'sessions').mkdir()
  sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
  base=f'http://127.0.0.1:{port}'
  php=[os.getenv('PHP_BIN','php')]
  if os.getenv('PHP_EXTENSION_DIR'):
   php+=['-n']+sum((['-d','extension='+os.environ['PHP_EXTENSION_DIR']+'/'+n+'.so'] for n in ['pdo','pdo_sqlite','ctype']),[])
  env={**os.environ,'MUJKMEN_TEST_MODE':'1','MK_AUTH_DIR':str(tmp/'auth'),'MK_DATA_DIR':str(tmp/'data')}
  with (tmp/'server.log').open('w') as logs:
   server=subprocess.Popen(php+['-d','session.save_path='+str(tmp/'sessions'),'-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=logs,stderr=logs)
   def sync(token=None,payload=None,origin=None,method=None):
    headers={}
    if token:headers['Authorization']='Bearer '+token
    if payload is not None:headers['Content-Type']='application/json'
    if origin:headers['Origin']=origin
    body=json.dumps(payload).encode() if payload is not None else None
    req=urllib.request.Request(base+'/sync.php',data=body,headers=headers,method=method)
    try:
     with urllib.request.urlopen(req) as r:return r.status,json.load(r)
    except urllib.error.HTTPError as e:
     return e.code,json.loads(e.read())
   try:
    for _ in range(60):
     try:urllib.request.urlopen(base+'/api.php?action=bootstrap');break
     except OSError:time.sleep(.1)
    assert sync()[0]==503
    password=secrets.token_urlsafe(24)
    subprocess.run(php+[str(ROOT/'tools/admin-user.php')],input=password+'\n',text=True,env=env,check=True,capture_output=True)
    owner=Session(base);owner.token();owner.form('login',{'email':'jgjanousek@gmail.com','password':password});owner.csrf=owner.api('bootstrap')[1]['csrf']
    assert owner.api('import',{'format':'muj-kmen','version':1,'clients':[{'id':'fake-1','firstName':'Fake','lastName':'Test','group':'A','amount':1,'products':{}}]})[0]==200
    credential=tmp/'client.json'
    provision=subprocess.run(php+[str(ROOT/'tools/sync-key.php'),str(credential),'720'],env=env,check=True,capture_output=True,text=True)
    keys=json.loads(credential.read_text());read,write=keys['readToken'],keys['writeToken']
    assert read not in provision.stdout and write not in provision.stdout
    assert 720*86400-10 <= keys['expiresAt']-int(time.time()) <= 720*86400
    assert '720 days' in provision.stdout
    assert credential.stat().st_mode&0o777==0o600
    server_config=(tmp/'auth/diana-sync.json').read_text()
    assert read not in server_config and write not in server_config
    assert sync()[0]==401
    assert sync('wrong')[0]==401
    status,snapshot=sync(read);assert status==200 and len(snapshot['clients'])==1
    rev=snapshot['clients'][0]['revision']
    plan={'operationId':'op-plan-1','clientId':'fake-1','revision':rev,'type':'plan','nextAction':'Confirm service needs','nextContact':'2026-12-01'}
    assert sync(read,plan)[0]==403
    assert sync(write,plan,origin=base)[0]==403
    assert sync(write,{**plan,'type':'delete'})[0]==422
    assert sync(write,{**plan,'amount':0})[0]==422
    assert sync(write,{**plan,'revision':999})[0]==409
    assert sync(write,plan)[0]==200
    assert sync(write,plan)[1]['revision']==rev+1
    assert sync(write,{**plan,'nextAction':'Different'})[0]==409
    assert sync(write,{**plan,'operationId':'op-stale'})[0]==409
    s=sync(read)[1];assert s['clients'][0]['nextAction']=='Confirm service needs'
    assert s['clients'][0]['amount']==1 and s['clients'][0]['group']=='A' and s['entries']==[]
    note={'operationId':'op-entry-1','clientId':'fake-1','revision':rev+1,'type':'entry','entry':{'kind':'meeting','date':'2026-01-01','body':'Fictional confirmed meeting','source':'fake-source-1'}}
    assert sync(write,{**note,'entry':{**note['entry'],'source':''}})[0]==422
    assert sync(write,{**note,'entry':{**note['entry'],'date':'2999-01-01'}})[0]==422
    assert sync(write,note)[0]==200
    assert sync(write,note)[0]==200
    snapshot=sync(read)[1];assert len(snapshot['entries'])==1
    assert '[Diana 00' in snapshot['entries'][0]['body']
    assert snapshot['clients'][0]['revision']==rev+2
    # The original UI API still needs its normal authenticated owner session.
    assert Session(base).api('data')[0]==401
    assert owner.api('data')[0]==200
    # A client deleted in the UI cannot be restored/changed by the service API.
    assert owner.api('delete-clients',{'clients':[{'id':'fake-1','revision':rev+2}]})[0]==200
    assert sync(write,{**plan,'operationId':'deleted','revision':rev+3})[0]==404
    assert sync(read)[1]['clients']==[] and sync(read)[1]['entries']==[]
    keys_file=tmp/'auth/diana-sync.json';old=json.loads(keys_file.read_text());keys_file.write_text(json.dumps({**old,'expiresAt':1}))
    assert sync(read)[0]==401
    keys_file.unlink();assert sync(write)[0]==503
    print('PASS: disabled default, scoped credentials, browser rejection, plans, source-linked entries, idempotency, conflicts, deleted-client protection, expiration and revocation.')
   except Exception:
    # Log PHP diagnostics only; fixtures contain no real clients or live secrets.
    logs.flush();print((tmp/'server.log').read_text()[-1800:]);raise
   finally:server.terminate();server.wait(timeout=10)

if __name__=='__main__':run()
