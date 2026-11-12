import copy
import argparse
import gzip
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

BASE = Path(__file__).resolve().parents[1]
def module(name):
    spec=importlib.util.spec_from_file_location(name,BASE/'scripts'/f'{name}.py')
    m=importlib.util.module_from_spec(spec); spec.loader.exec_module(m); return m
bench=module('bench'); exporter=module('export')

class SafetyTests(unittest.TestCase):
    def test_inventory_accepts_exact_registered_set(self):
        bench.validate_inventory(['benchmark','tesis_tenant_1'],['benchmark','tesis_tenant_1'])

    def test_inventory_rejects_development_testing_and_injection(self):
        for name in ['tesis','tesis_test','tesis_tenant_1;drop database tesis','tesis_tenant_0','unregistered']:
            with self.subTest(name=name), self.assertRaises(RuntimeError):
                bench.validate_inventory(['benchmark',name],['benchmark',name])

    def test_inventory_rejects_extra_or_missing_database(self):
        for names in [['benchmark','tesis_tenant_2'],['benchmark'],['tesis_tenant_1']]:
            with self.assertRaises(RuntimeError): bench.validate_inventory(names,['benchmark','tesis_tenant_1'])

    def states(self):
        before={'strategy':'shared_tables','token_count':256,'central_auth_audit':0,'record_sequence':{'last_value':5,'is_called':True},'audit_total':0,
                'tenants':[{'id':1,'records':5,'hash':'abc','residuals':0,'audit':[]}],
                'sequences':{'1':[{'schemaname':'public','sequencename':'audit_logs_id_seq','last_value':None}]}}
        after=copy.deepcopy(before); after['audit_total']=20
        after['tenants'][0]['audit']=[{'action':'record.list','n':10},{'action':'record.read','n':10}]
        after['sequences']['1'][0]['last_value']=20
        return before,after

    def test_warmup_accepts_comparable_state(self):
        bench.comparable(*self.states(),20)

    def test_warmup_rejects_record_content_counts_audit_and_sequence_drift(self):
        for field,value in [('hash','wrong'),('records',6),('residuals',1),('audit',[])]:
            a,b=self.states(); b['tenants'][0][field]=value
            with self.assertRaises(RuntimeError): bench.comparable(a,b,20)
        a,b=self.states(); b['record_sequence']['last_value']=6
        with self.assertRaises(RuntimeError): bench.comparable(a,b,20)
        a,b=self.states(); b['sequences']['1'][0]['last_value']=21
        with self.assertRaises(RuntimeError): bench.comparable(a,b,20)

    def test_guard_fails_before_docker_when_session_missing(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench,'BASE',Path(path)):
            with self.assertRaises(RuntimeError): bench.Harness('shared_tables').guard()

    def test_restoration_ignores_catalog_order_but_preserves_sequence_values(self):
        a={'central_sequences':[{'schemaname':'public','sequencename':name,'last_value':i} for i,name in enumerate(['users_id_seq','records_id_seq'])], 'sequences':{}}
        a['sequences']['1']=copy.deepcopy(a['central_sequences'])
        b=copy.deepcopy(a)
        b['central_sequences'].reverse(); b['sequences']['1'].reverse()
        self.assertEqual(bench.canonical_state(a),bench.canonical_state(b))
        b['sequences']['1'][0]['last_value']=99
        self.assertNotEqual(bench.canonical_state(a),bench.canonical_state(b))

    def test_no_confirmatory_command_exists(self):
        self.assertFalse(bench.CONFIG['confirmatory_frozen'])
        self.assertNotIn('confirmatory-run',(BASE/'scripts/bench.py').read_text())

    def test_export_real_fixture(self):
        with tempfile.TemporaryDirectory() as path:
            p=Path(path); (p/'manifest.json').write_text(json.dumps({'run':'r1','strategy':'shared_tables','profile':'F0','arguments':{'block':'b1'}}))
            with gzip.open(p/'samples.json.gz','wt') as f:
                f.write(json.dumps({'type':'Point','metric':'http_req_duration','data':{'time':'2026-11-02T00:00:00Z','value':123,'tags':{'name':'POST','status':'201','window':'measurement'}}})+'\n')
            (p/'resources.jsonl').write_text(json.dumps({'utc':'now','docker':json.dumps({'Name':'db','CPUPerc':'2%'})})+'\n')
            exporter.export(p)
            self.assertIn('123',(p/'http.csv').read_text()); self.assertIn('POST',(p/'http.csv').read_text())
            self.assertIn('r1,b1,shared_tables,F0',(p/'http.csv').read_text())
            self.assertIn('2%',(p/'resources.csv').read_text())

if __name__=='__main__': unittest.main()

class SequenceTests(unittest.TestCase):
    def test_shared_sequences_not_counted_per_tenant(self):
        state={'strategy':'shared_tables','sequences':{str(t):[{'schemaname':'public','sequencename':'audit_logs_id_seq','last_value':40}] for t in (1,2)}}
        self.assertEqual(bench.audit_sequence_sum(state),40)

    def test_separate_database_sequences_are_summed(self):
        state={'strategy':'separate_databases','sequences':{str(t):[{'schemaname':'public','sequencename':'audit_logs_id_seq','last_value':20}] for t in (1,2)}}
        self.assertEqual(bench.audit_sequence_sum(state),40)

class GuardTests(unittest.TestCase):
    def harness(self):
        h=bench.Harness('shared_tables');h.state={'marker':'a'*32};return h

    def test_guard_rejects_development_volume_before_sql(self):
        h=self.harness()
        info=[{'Config':{'Labels':{'com.docker.compose.project':h.project}},'Mounts':[{'Destination':'/var/lib/postgresql/data','Name':'tesis-multitenant_postgres_data'}],'HostConfig':{'PortBindings':{}}}]
        with patch.object(h,'dc',return_value='container'), patch.object(bench.subprocess,'check_output',return_value=json.dumps(info)), patch.object(h,'sql') as sql:
            with self.assertRaises(RuntimeError):h.guard()
            sql.assert_not_called()

    def test_guard_rejects_wrong_marker_before_inventory(self):
        h=self.harness()
        info=[{'Config':{'Labels':{'com.docker.compose.project':h.project}},'Mounts':[{'Destination':'/var/lib/postgresql/data','Name':h.project+'_database'}],'HostConfig':{'PortBindings':{}}}]
        with patch.object(h,'dc',return_value='container'), patch.object(bench.subprocess,'check_output',return_value=json.dumps(info)), patch.object(h,'sql',return_value='wrong') as sql:
            with self.assertRaises(RuntimeError):h.guard()
            self.assertEqual(sql.call_count,1)

    def test_state_comparison_rejects_cross_strategy_content_difference(self):
        import sys
        with patch.dict(sys.modules,{'bench':bench}): comparator=module('compare_states')
        a,b=SafetyTests().states(); b=copy.deepcopy(a);b['strategy']='separate_schemas'
        self.assertEqual(comparator.normalized(a),comparator.normalized(b))
        b['tenants'][0]['hash']='different'
        self.assertNotEqual(comparator.normalized(a),comparator.normalized(b))


class SessionTests(unittest.TestCase):
    def test_named_sessions_leave_legacy_paths_and_metadata_untouched(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            legacy = bench.Harness('shared_tables')
            legacy.folder.mkdir(parents=True)
            original = json.dumps({'marker': 'a'*32, 'source_hashes': {'old': 'digest'}})
            legacy.session.write_text(original)
            named = bench.Harness('shared_tables', 'phase8-f0')
            other = bench.Harness('shared_tables', 'phase8-d2')
            self.assertEqual(named.folder, Path(path)/'results/sessions/phase8-f0/shared_tables')
            self.assertEqual(named.result_path, Path('/results/sessions/phase8-f0/shared_tables'))
            self.assertEqual(named.project, 'tesis-benchmark-shared-tables-phase8-f0')
            self.assertNotEqual(named.project, other.project)
            self.assertFalse(named.session.exists())
            self.assertEqual(legacy.session.read_text(), original)
            self.assertEqual(bench.Harness('shared_tables').project, 'tesis-benchmark-shared-tables')

    def test_session_identifiers_reject_unsafe_or_ambiguous_paths(self):
        for session_id in ['', '../old', 'a/b', 'ABC', 'with_space', 'a'*33]:
            with self.subTest(session_id=session_id), self.assertRaises(ValueError):
                bench.Harness('shared_tables', session_id)

    def test_new_session_records_current_sources_without_touching_old_session(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            old = bench.Harness('shared_tables')
            old.folder.mkdir(parents=True)
            old.session.write_text('{"marker":"old"}')
            h = bench.Harness('shared_tables', 'phase8-f0')
            # All external operations are mocked: no Docker, PHP, DB or dumps.
            with patch.object(bench.subprocess, 'check_output', return_value=old.project+'_database\n'), \
                    patch.object(bench, 'source_hashes', return_value={'new-source': 'new-digest'}), \
                    patch.object(h, 'dc'), patch.object(h, 'php'), \
                    patch.object(h, 'guard', return_value=['benchmark']), patch.object(h, 'snapshot'):
                h.init('F0')
            stored = json.loads(h.session.read_text())
            self.assertEqual(stored['source_hashes'], {'new-source': 'new-digest'})
            self.assertEqual(stored['session_id'], 'phase8-f0')
            self.assertEqual(stored['project'], h.project)
            self.assertEqual(old.session.read_text(), '{"marker":"old"}')
            reopened = bench.Harness('shared_tables', 'phase8-f0')
            self.assertEqual(reopened.state, stored)

    def test_existing_session_and_volume_still_block_reinitialization(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'phase8-f0')
            with patch.object(bench.subprocess, 'check_output', return_value=h.project+'_database\n'), \
                    patch.object(h, 'dc') as dc:
                with self.assertRaisesRegex(RuntimeError, 'Existing experimental volume'):
                    h.init('F0')
                dc.assert_not_called()
            h.folder.mkdir(parents=True)
            h.session.write_text('{}')
            with patch.object(bench.subprocess, 'check_output') as check:
                with self.assertRaisesRegex(RuntimeError, 'Session already exists'):
                    h.init('F0')
                check.assert_not_called()

    def test_changed_sources_still_fail_before_restore(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'phase8-f0')
            h.state = {'source_hashes': {'old': 'digest'}}
            with patch.object(bench.subprocess, 'check_output', return_value=''), \
                    patch.object(bench, 'source_hashes', return_value={'new': 'digest'}), \
                    patch.object(h, 'restore') as restore:
                with self.assertRaisesRegex(RuntimeError, 'Source changed'):
                    h.run(None)
                restore.assert_not_called()

    def test_named_session_pilot_paths_and_manifest_with_all_services_mocked(self, no_monitor=False):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'phase8-f0')
            h.folder.mkdir(parents=True)
            h.state = {'source_hashes': {'source': 'digest'}, 'profile': 'F0'}
            before, after = SafetyTests().states()
            args = argparse.Namespace(mode='R', session='phase8-f0', block='b1', vus=1,
                                      duration='120s', rate=None, victim_rate=1, noise_rate=0, noise_mode='W', no_monitor=no_monitor)
            paths = []
            def fake_k6(folder, mode, fixture, args):
                paths.append((folder, fixture))
                target = Path(path)/'results'/folder.relative_to('/results')
                (target/'summary.json').write_text('{"metrics":{}}')
                return 'fake k6; no requests'
            with patch.object(bench.subprocess, 'check_output', return_value=''), \
                    patch.object(bench, 'source_hashes', return_value=h.state['source_hashes']), \
                    patch.object(h, 'restore', return_value={'exact':True}), patch.object(h, 'capture'), \
                    patch.object(h, 'counters', return_value={}), \
                    patch.object(h, 'php', side_effect=[{'tenants': []}, before, after, after]), \
                    patch.object(h, 'k6', side_effect=fake_k6), \
                    patch.object(bench.threading, 'Thread') as thread, patch('builtins.print'):
                h.run(args)
                self.assertEqual(thread.called, not no_monitor)
            manifest_path = next(h.folder.glob('*/manifest.json'))
            manifest = json.loads(manifest_path.read_text())
            self.assertEqual(manifest['session_id'], 'phase8-f0')
            self.assertEqual(manifest['project'], h.project)
            self.assertEqual(manifest['monitoring']['enabled'], not no_monitor)
            self.assertEqual(len(paths), 2)
            for folder, fixture in paths:
                self.assertTrue(folder.is_relative_to(h.result_path))
                self.assertEqual(fixture, h.result_path/'tokens.private.json')
            self.assertFalse((h.folder/'tokens.private.json').exists())

    def test_no_monitor_omits_periodic_worker_and_exports_without_resources(self):
        self.test_named_session_pilot_paths_and_manifest_with_all_services_mocked(no_monitor=True)
        with tempfile.TemporaryDirectory() as path:
            folder = Path(path)
            (folder/'manifest.json').write_text(json.dumps({'run':'r1','strategy':'shared_tables'}))
            with gzip.open(folder/'samples.json.gz', 'wt') as stream:
                stream.write('')
            exporter.export(folder)
            self.assertEqual(len((folder/'resources.csv').read_text().splitlines()), 1)

    def test_named_session_metadata_mismatch_is_rejected(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'phase8-f0')
            h.folder.mkdir(parents=True)
            h.session.write_text(json.dumps({'session_id': 'other', 'project': h.project}))
            with self.assertRaisesRegex(RuntimeError, 'Session identity mismatch'):
                bench.Harness('shared_tables', 'phase8-f0')

    def test_named_session_cannot_use_legacy_volume_even_with_named_project_label(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'phase8-f0')
            h.state = {'marker': 'a'*32}
            info = [{'Config': {'Labels': {'com.docker.compose.project': h.project}},
                     'Mounts': [{'Destination': '/var/lib/postgresql/data',
                                 'Name': 'tesis-benchmark-shared-tables_database'}],
                     'HostConfig': {'PortBindings': {}}}]
            with patch.object(h, 'dc', return_value='container'), \
                    patch.object(bench.subprocess, 'check_output', return_value=json.dumps(info)), \
                    patch.object(h, 'sql') as sql:
                with self.assertRaisesRegex(RuntimeError, 'own unpublished volume'):
                    h.guard()
                sql.assert_not_called()


class PhaseLabelTests(unittest.TestCase):
    """Minimal campaign label: historical sessions stay 'pilot'; new ones may be 'confirmatory'."""

    def test_phase_choices_and_cli_flag(self):
        self.assertEqual(bench.PHASES, ('pilot', 'confirmatory'))
        self.assertIn("'--phase'", (BASE/'scripts/bench.py').read_text())
        with self.assertRaises(ValueError):
            bench.Harness('shared_tables', 'conf9-d2-20261110', 'exploratory')

    def test_legacy_session_without_label_is_pilot_and_opens_without_phase(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'phase8-f0')
            h.folder.mkdir(parents=True)
            h.session.write_text(json.dumps({'session_id': 'phase8-f0', 'project': h.project, 'marker': 'a'*32}))
            self.assertIsNone(bench.Harness('shared_tables', 'phase8-f0').phase)
            self.assertEqual(bench.Harness('shared_tables', 'phase8-f0', 'pilot').state.get('phase', 'pilot'), 'pilot')
            with self.assertRaisesRegex(RuntimeError, 'Session phase mismatch'):
                bench.Harness('shared_tables', 'phase8-f0', 'confirmatory')

    def test_confirmatory_session_rejects_pilot_label_and_default_init_stays_pilot(self):
        with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
            h = bench.Harness('shared_tables', 'conf9-d2-20261110', 'confirmatory')
            h.folder.mkdir(parents=True)
            h.session.write_text(json.dumps({'session_id': 'conf9-d2-20261110', 'project': h.project, 'phase': 'confirmatory', 'marker': 'b'*32}))
            self.assertEqual(bench.Harness('shared_tables', 'conf9-d2-20261110', 'confirmatory').state['phase'], 'confirmatory')
            with self.assertRaisesRegex(RuntimeError, 'Session phase mismatch'):
                bench.Harness('shared_tables', 'conf9-d2-20261110', 'pilot')
        for phase, expected in ((None, 'pilot'), ('confirmatory', 'confirmatory')):
            with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
                h = bench.Harness('shared_tables', 'conf9-d2-20261110', phase)
                with patch.object(bench.subprocess, 'check_output', return_value=''), \
                        patch.object(bench, 'source_hashes', return_value={'s': 'd'}), \
                        patch.object(h, 'dc'), patch.object(h, 'php'), \
                        patch.object(h, 'guard', return_value=['benchmark']), patch.object(h, 'snapshot'):
                    h.init('D2')
                self.assertEqual(json.loads(h.session.read_text())['phase'], expected)

    def test_manifest_carries_session_phase(self):
        for stored, expected in (({}, 'pilot'), ({'phase': 'pilot'}, 'pilot'), ({'phase': 'confirmatory'}, 'confirmatory')):
            with tempfile.TemporaryDirectory() as path, patch.object(bench, 'BASE', Path(path)):
                h = bench.Harness('shared_tables', 'conf9-d2-20261110')
                h.folder.mkdir(parents=True)
                h.state = {'source_hashes': {'source': 'digest'}, 'profile': 'D2', **stored}
                before, after = SafetyTests().states()
                args = argparse.Namespace(mode='R', session='conf9-d2-20261110', block='p9-b1-d2-r-fixed', vus=1,
                                          duration='300s', rate=10, victim_rate=1, noise_rate=0, noise_mode='W', no_monitor=False, phase=expected)
                def fake_k6(folder, mode, fixture, args):
                    (Path(path)/'results'/folder.relative_to('/results')/'summary.json').write_text('{"metrics":{}}')
                    return 'fake k6; no requests'
                with patch.object(bench.subprocess, 'check_output', return_value=''), \
                        patch.object(bench, 'source_hashes', return_value=h.state['source_hashes']), \
                        patch.object(h, 'restore', return_value={'exact': True}), patch.object(h, 'capture'), \
                        patch.object(h, 'counters', return_value={}), \
                        patch.object(h, 'php', side_effect=[{'tenants': []}, before, after, after]), \
                        patch.object(h, 'k6', side_effect=fake_k6), \
                        patch.object(bench.threading, 'Thread'), patch('builtins.print'):
                    h.run(args)
                manifest = json.loads(next(h.folder.glob('*/manifest.json')).read_text())
                self.assertEqual(manifest['phase'], expected)
                self.assertEqual(manifest['arguments']['duration'], '300s')
