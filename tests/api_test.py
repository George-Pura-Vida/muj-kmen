"""End-to-end PHP/SQLite tests. Uses isolated temporary data, no outgoing mail."""
import contextlib, http.cookiejar, json, os, pathlib, re, socket, subprocess, tempfile, time, urllib.request, urllib.parse, urllib.error
ROOT=pathlib.Path(__file__).resolve().parents[1]
class Session:
 def __init__(self,base):self.base=base;self.jar=http.cookiejar.CookieJar();self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf=''
 def call(self,path,data=None,as_json=False,csrf=True):
  headers={};body=None
  if data is not None:
   body=(json.dumps(data).encode() if as_json else urllib.parse.urlencode(data).encode());headers['Content-Type']='application/json' if as_json else 'application/x-www-form-urlencoded'
   headers['Origin']=self.base
   if as_json and csrf:headers['X-CSRF-Token']=self.csrf
  req=urllib.request.Request(self.base+path,data=body,headers=headers)
  try:r=self.http.open(req);return r.status,r.read().decode()
  except urllib.error.HTTPError as e:return e.code,e.read().decode()
 def token(self):
  status,html=self.call('/account.php?view=login');assert status==200
  self.csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html).group(1);return self.csrf
 def form(self,action,fields=None):return self.call('/account.php?view='+('profile' if action=='approval' else action),dict(action=action,csrf=self.csrf,**(fields or {})))
 def api(self,action,data=None,csrf=True):
  status,text=self.call('/api.php?action='+action,data,True,csrf);return status,json.loads(text)
def run():
 with tempfile.TemporaryDirectory(prefix='mujkmen-tests-') as t:
  t=pathlib.Path(t);sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close();base=f'http://127.0.0.1:{port}'
  args=[os.getenv('PHP_BIN','php')]
  if os.getenv('PHP_EXTENSION_DIR'):args+=['-n']+sum((['-d','extension='+os.getenv('PHP_EXTENSION_DIR')+'/'+n+'.so'] for n in ['pdo','pdo_sqlite','ctype']),[])
  for p in ROOT.rglob('*.php'):subprocess.run(args+['-l',str(p)],check=True,stdout=subprocess.DEVNULL)
  (t/'sessions').mkdir();args+=['-d','session.save_path='+str(t/'sessions')]
  env={**os.environ,'MUJKMEN_TEST_MODE':'1','MK_AUTH_DIR':str(t/'auth'),'MK_DATA_DIR':str(t/'data')}
  with open(t/'server.log','w') as logs:
   server=subprocess.Popen(args+['-S',f'127.0.0.1:{port}','-t',str(ROOT)],env=env,stdout=logs,stderr=logs)
   try:
    for _ in range(60):
     try:urllib.request.urlopen(base+'/api.php?action=bootstrap');break
     except Exception:time.sleep(.1)
    anon=Session(base);assert anon.api('data')[0]==401
    owner=Session(base);owner.token()
    password='Test-only-strong-password-374!'
    def register(s,email,name):
     return s.form('register',{'email':email,'first_name':name,'last_name':'Test','company':'','ico':'','dic':'','phone':'+420777111222','address':'','password':password,'password_confirm':password,'privacy':'yes'})
    subprocess.run(args+[str(ROOT/'tools/admin-user.php')],input=password+'\n',text=True,env=env,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    assert register(owner,'jgjanousek@gmail.com','Owner')[0]==403
    assert owner.form('login',{'email':'jgjanousek@gmail.com','password':password})[0]==200
    b=owner.api('bootstrap')[1];assert b['user']['isAdmin'];owner.csrf=b['csrf']
    assert owner.api('import',{'format':'muj-kmen','version':1,'clients':[]},csrf=False)[0]==403
    c={'id':'test-1','firstName':'Anna','lastName':'Testová','group':'A','amount':123456.78,'products':{},'nextContact':'2026-11-05','nextAction':'Ověřit domluvu'}
    seed={'format':'muj-kmen','version':1,'clients':[c]}
    assert owner.api('import',seed)[1]['added']==1
    assert owner.api('import',seed)[1]['skipped']==1
    current=owner.api('data')[1]['clients'][0];assert current['products']['insurance']['state']=='verify'
    changed={**current,'group':'B'};assert owner.api('client',{'client':changed,'revision':current['revision']})[0]==200
    assert owner.api('client',{'client':changed,'revision':current['revision']})[0]==409
    current=owner.api('data')[1]['clients'][0]
    note={'id':'note-test-1','clientId':'test-1','kind':'phone','body':'Otestovaný zápis','result':'Domluveno','date':'2026-11-04','nextContact':'2026-11-07','nextAction':'Další schůzka','revision':current['revision']}
    assert owner.api('entry',note)[0]==200
    assert owner.api('entry',note)[1]['duplicate']
    data=owner.api('data')[1];assert len(data['entries'])==1 and data['clients'][0]['nextContact']=='2026-11-07'
    backup=owner.api('export')[1];assert backup['clients'][0]['group']=='B' and len(backup['entries'])==1
    # Deletion is reversible, revision checked, atomic, and preserves notes.
    current=owner.api('data')[1]['clients'][0]
    assert owner.api('delete-clients',{'clients':[{'id':current['id'],'revision':current['revision']}]},csrf=False)[0]==403
    assert owner.api('delete-clients',{'clients':[{'id':current['id'],'revision':0}]})[0]==409
    assert len(owner.api('data')[1]['clients'])==1
    assert owner.api('delete-clients',{'clients':[{'id':current['id'],'revision':current['revision']},{'id':'missing','revision':1}]})[0]==409
    assert len(owner.api('data')[1]['clients'])==1
    assert owner.api('delete-clients',{'clients':[{'id':current['id'],'revision':current['revision']}]})[1]['count']==1
    assert owner.api('data')[1]=={'clients':[],'entries':[]}
    assert owner.api('export')[1]['entries']==[]
    assert owner.api('client',{'client':current,'revision':current['revision']+1})[0]==409
    assert owner.api('entry',{**note,'id':'note-after-delete'})[0]==404
    assert len(owner.api('trash')[1]['clients'])==1
    assert owner.api('restore-clients',{'clients':[{'id':current['id']}]})[1]['count']==1
    restored=owner.api('data')[1]
    assert len(restored['clients'])==1 and len(restored['entries'])==1
    assert restored['clients'][0]['nextContact']=='2026-11-07'
    assert owner.api('trash')[1]['clients']==[]
    invalid={'format':'muj-kmen','version':1,'clients':[{**c,'id':'test-2','nextContact':'2026-02-31'}]};assert owner.api('import',invalid)[0]==422
    other=Session(base);other.token();assert register(other,'pending@example.test','Other')[0]==403
    other.form('login',{'email':'pending@example.test','password':password});assert other.api('data')[0]==401
    assert __import__('sqlite3').connect(t/'auth/accounts.sqlite').execute('SELECT COUNT(*) FROM users').fetchone()[0]==1
    for action in ['register','forgot','resend','reset','verify','password','profile','confirm-email','approval']:
     assert owner.form(action,{'email':'jgjanousek@gmail.com','password':password})[0]==403,action
    for view in ['register','forgot','reset','verify','confirm-email']:
     assert anon.call('/account.php?view='+view)[0]==403,view
    assert owner.api('data')[0]==200
    assert not (t/'auth/test-outbox.jsonl').exists()
    legacy={**c,'id':'legacy-import','group':'Mimo péči'}
    assert owner.api('import',{'format':'muj-kmen','version':1,'clients':[legacy]})[1]['added']==1
    assert next(x for x in owner.api('data')[1]['clients'] if x['id']=='legacy-import')['group']=='E'
    # Simulate a database created before the group merge, then reopen it through the API.
    with __import__('sqlite3').connect(t/'data/muj-kmen.sqlite') as legacy_db:
     legacy_db.execute("INSERT INTO clients(id,payload,revision,updated_at) VALUES(?,?,1,?)",('legacy-migration',json.dumps({**c,'id':'legacy-migration','group':'Mimo péči'}),'2026-11-05'))
     legacy_db.execute("DELETE FROM schema_migrations WHERE name='merge-unassigned-into-E'")
    migrated=owner.api('data')[1]
    assert next(x for x in migrated['clients'] if x['id']=='legacy-migration')['group']=='E'
    assert len(migrated['entries'])==1
    print('PASS: PHP lint; owner CLI setup and login; no registration/recovery/password routes; owner-only data; CSRF; six products; import; conflicts; notes; backup; invalid date.')
   except Exception:
    logs.flush();print((t/'server.log').read_text()[-3000:]);raise
   finally:server.terminate();server.wait(timeout=10)
if __name__=='__main__':run()
