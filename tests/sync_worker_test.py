"""No network: verify retries, conflict handling and private snapshot import."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
spec = importlib.util.spec_from_file_location('sync_worker', Path(__file__).resolve().parents[1] / 'tools/muj_kmen_sync.py')
w = importlib.util.module_from_spec(spec); spec.loader.exec_module(w)

class WorkerTests(unittest.TestCase):
 def setUp(self):
  self.tmp = tempfile.TemporaryDirectory(); self.db = w.open_state(self.tmp.name)
  self.snapshot = {'format':'muj-kmen-sync','version':1,'clients':[{'id':'fake-1','revision':1,'group':'A'}],'entries':[]}
  w.save_snapshot(self.db,self.snapshot,'api')
  self.plan={'operationId':'test-op','clientId':'fake-1','revision':1,'type':'plan','nextAction':'Call to confirm needs'}
 def tearDown(self): self.db.close(); self.tmp.cleanup()
 def test_import_is_marked_export(self):
  w.save_snapshot(self.db,{**self.snapshot,'format':'muj-kmen'},'export')
  self.assertEqual(w.summary(self.db)['source'],'export')
 def test_rejects_missing_revision_and_duplicate_client(self):
  for clients in [[{'id':'fake-1'}],self.snapshot['clients']*2]:
   with self.assertRaises(w.SyncError):w.save_snapshot(self.db,{**self.snapshot,'clients':clients},'api')
 def test_success_push_and_readback(self):
  w.enqueue(self.db,self.plan); calls=[]
  def api(c,p=None):
   calls.append(p)
   return (200,self.snapshot) if p is None else (200,{'ok':True,'operationId':p['operationId']})
  self.assertEqual(w.synchronize(self.db,{},True,api)['outbox'],{'sent':1})
  self.assertEqual(len(calls),3)
 def test_conflict_is_not_rebased_or_retried(self):
  w.enqueue(self.db,self.plan)
  def api(c,p=None):return (200,{**self.snapshot,'clients':[{'id':'fake-1','revision':2}]}) if p is None else (409,None)
  self.assertEqual(w.synchronize(self.db,{},True,api)['outbox'],{'conflict':1})
  w.synchronize(self.db,{},True,api)
  self.assertEqual(json.loads(self.db.execute('SELECT payload FROM outbox').fetchone()[0])['revision'],1)
 def test_network_failure_preserves_pending_and_identical_payload(self):
  w.enqueue(self.db,self.plan)
  def fail(c,p=None):
   if p is None:return 200,self.snapshot
   raise w.SyncError('network')
  with self.assertRaises(w.SyncError):w.synchronize(self.db,{},True,fail)
  self.assertEqual(w.summary(self.db)['outbox'],{'pending':1})
  w.enqueue(self.db,self.plan)
  with self.assertRaises(w.SyncError):w.enqueue(self.db,{**self.plan,'nextAction':'different'})
 def test_read_only_run_does_not_push(self):
  w.enqueue(self.db,self.plan)
  def api(c,p=None):self.assertIsNone(p);return 200,self.snapshot
  self.assertEqual(w.synchronize(self.db,{},False,api)['outbox'],{'pending':1})
 def test_requires_source_for_actual_entry(self):
  with self.assertRaises(w.SyncError):w.enqueue(self.db,{**self.plan,'type':'entry','entry':{'body':'met','kind':'meeting','date':'2026-01-01'}})
 def test_credential_permissions(self):
  p=Path(self.tmp.name)/'key.json';p.write_text('{}');p.chmod(0o644)
  with self.assertRaises(w.SyncError):w.private_json(p)

if __name__=='__main__':unittest.main()
