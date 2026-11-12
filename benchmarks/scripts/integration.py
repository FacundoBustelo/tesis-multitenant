#!/usr/bin/env python3
"""Validate an initialized F0 experiment; never calls pilot-run or sustained load."""
import argparse
import copy
import csv
import datetime as dt
import gzip
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

from bench import BASE, Harness, STRATEGIES, canonical_state, comparable
from export import export


def require(ok, message):
    if not ok:
        raise RuntimeError(message)


def save(path, value):
    path.write_text(json.dumps(value, indent=2) + '\n')


def rejected(action, message):
    try:
        action()
    except RuntimeError as exc:
        require(message in str(exc), 'Unexpected safety rejection')
        return str(exc)
    raise RuntimeError('SAFETY DISCREPANCY: unsafe action was accepted; stop destructive work')


def infrastructure(h):
    ids = h.dc('ps', '-q').splitlines()
    inspected = json.loads(subprocess.check_output(['docker', 'inspect', *ids], text=True))
    network = h.project + '_experiment'
    net = json.loads(subprocess.check_output(['docker', 'network', 'inspect', network], text=True))[0]
    require(net['Internal'], 'Experimental network must be internal')
    containers = []
    for c in inspected:
        require(c['Config']['Labels']['com.docker.compose.project'] == h.project, 'Wrong project')
        require(set(c['NetworkSettings']['Networks']) == {network}, 'Unexpected network attachment')
        require(not c['HostConfig']['PortBindings'], 'Experimental ports must not be published')
        require(c['State']['Running'], 'Service is not running')
        for mount in c['Mounts']:
            if mount['Type'] == 'volume':
                require(mount['Name'] in {h.project+'_database', h.project+'_runtime'}, 'Unexpected volume')
            else:
                require(Path(mount['Source']).is_relative_to(BASE), 'Unexpected bind mount')
        env = dict(x.split('=', 1) for x in c['Config']['Env'] if '=' in x)
        if c['Config']['Labels']['com.docker.compose.service'] == 'app':
            require(env['APP_ENV'] == 'benchmark' and env['DB_HOST'] == 'db'
                    and env['DB_DATABASE'] == 'benchmark' and env['DB_USERNAME'] == 'bench_runner'
                    and env['DB_PASSWORD'] == 'benchmark_runner_only' and not env['DB_URL'], 'Unsafe app credentials')
        containers.append({'name':c['Name'], 'image':c['Image'], 'running':c['State']['Running'],
                           'networks':list(c['NetworkSettings']['Networks']),
                           'volumes':[m.get('Name') for m in c['Mounts'] if m['Type']=='volume'],
                           'published_ports':c['HostConfig']['PortBindings']})
    roles = json.loads(h.sql("select json_agg(t) from (select rolname,rolsuper,rolcreatedb,rolcreaterole from pg_roles where rolname !~ '^pg_' order by rolname) t"))
    require({r['rolname'] for r in roles} == {'bench_admin','bench_runner'}, 'Unexpected database roles')
    runner = next(r for r in roles if r['rolname']=='bench_runner')
    require(not any(runner[k] for k in ('rolsuper','rolcreatedb','rolcreaterole')), 'Privileged app role')
    h.dc('exec','-T','app','php','-r',"if (file_exists('.env') || file_exists('bootstrap/cache/config.php')) exit(1);")
    return {'project':h.project,'network':network,'internal':net['Internal'],'containers':containers,'roles':roles}


def expected_hash(t):
    rows = []
    for i in range(1, 6):
        key = f'20261102:{t}:{i}'
        rows.append(json.dumps([(f'Record {key} ').ljust(80,'x')[:80], hashlib.sha256(key.encode()).hexdigest()*16], separators=(',',':'))+'\n')
    return hashlib.sha256(''.join(rows).encode()).hexdigest()


def validate(strategy, session_id=None):
    h = Harness(strategy, session_id)
    require(h.state.get('profile') == 'F0', 'Integration is restricted to initialized F0')
    require(not (h.folder/'tokens.private.json').exists(), 'Unexpected existing private token fixture')
    h.guard()
    run = 'integration-'+dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S')
    folder = h.folder/run
    folder.mkdir(mode=0o700)
    relative = h.result_path/run
    print(f'{strategy}: preflight and isolation', flush=True)
    save(folder/'isolation.json', infrastructure(h))
    baseline = canonical_state(json.loads((h.folder/'baseline.json').read_text()))
    require(h.php('state') == baseline, 'Experiment must begin at baseline')
    require([t['id'] for t in baseline['tenants']] == [1,2,3], 'Expected three tenants')
    for t in baseline['tenants']:
        require(t['records']==5 and t['hash']==expected_hash(t['id']), 'Deterministic fixture mismatch')
    identities = json.loads(h.sql("select json_agg(t) from (select t.id,t.slug,(select count(*) from users u where u.tenant_id=t.id and u.email='actor@example.test') as users,(select count(*) from staff s where s.tenant_id=t.id and s.email='actor@example.test') as staff from tenants t order by t.id) t"))
    require(all(t['slug']==f"bench-{t['id']}" and t['users']==1 and t['staff']==1 for t in identities), 'Unexpected actors')
    save(folder/'identities.json', identities)
    safety = {}
    wrong = copy.copy(h)
    wrong.state = dict(h.state, marker='0'*32 if h.state['marker']!='0'*32 else '1'*32)
    safety['wrong_marker_restore'] = rejected(wrong.restore, 'Wrong database marker')
    # Corrupt the LAST dump in a disposable copy. All hashes must pass before stopping services.
    with tempfile.TemporaryDirectory(prefix='benchmark-snapshot-') as temp:
        corrupt = copy.copy(h)
        corrupt.folder = Path(temp)
        for name in h.state['databases']:
            (corrupt.folder/(name+'.dump')).write_bytes((h.folder/(name+'.dump')).read_bytes())
        with (corrupt.folder/(h.state['databases'][-1]+'.dump')).open('ab') as stream:
            stream.write(b'integration-corruption')
        safety['corrupt_last_dump_restore'] = rejected(corrupt.restore, 'Snapshot hash mismatch')
    # Exercise fixture guard against the disposable cluster, never a development connection.
    for key,value in [('APP_ENV','production'),('DB_DATABASE','postgres'),('BENCH_MARKER','f'*32)]:
        result = subprocess.run(['docker','compose','-f',str(BASE/'compose.yaml'),'-p',h.project,
                                 'run','--rm','-T','-e',f'{key}={value}','admin','php','/bench/fixtures/manage.php','state'],
                                env=h.env,capture_output=True,text=True)
        require(result.returncode != 0 and 'Benchmark destination/marker mismatch' in result.stdout+result.stderr,
                'SAFETY DISCREPANCY: fixture destination guard failed')
        safety['fixture_'+key] = 'rejected before fixture operation'
    save(folder/'safety.json', safety)
    save(folder/'before-http.json', baseline)
    print(f'{strategy}: bounded HTTP checks', flush=True)
    try:
        result = json.loads(h.dc('exec','-T','app','php','/bench/fixtures/integration.php'))
    except subprocess.CalledProcessError as exc:
        save(folder/'http-failure.json', json.loads(exc.stdout))
        raise RuntimeError('Bounded HTTP check failed; see http-failure.json') from None
    save(folder/'http-checks.json', result)
    dirty = h.php('state')
    save(folder/'after-http.json', dirty)
    require(all(t['records']==4 and t['hash']!=expected_hash(t['id']) for t in dirty['tenants']), 'HTTP mutations missing')
    require(dirty['audit_total']>0 and dirty['record_sequence']['last_value']==18, 'Audit/sequence mutations missing')
    print(f'{strategy}: restore records, audit and sequences', flush=True)
    h.restore()
    restored = h.php('state')
    require(restored==baseline, 'Restore did not recover exact baseline')
    save(folder/'after-restore.json', restored)
    print(f'{strategy}: k6, exactly six read requests / one VU', flush=True)
    fixture = h.folder/'tokens.private.json'
    private = h.php('tokens',1)
    tokens = [token for t in private['tenants'] for role in ('user','staff') for token in t[role]]
    try:
        save(fixture, private)
        os.chmod(fixture,0o600)
        before = h.php('state')
        save(folder/'before-warmup.json',before)
        h.dc('run','--rm','-T','k6','version')
        log = h.dc('run','--rm','-T','k6','run','-e','MODE=warmup','-e','MAX_VUS=1',
                   '-e','WARMUP_REQUESTS=2','-e',f'FIXTURE={h.result_path}/tokens.private.json',
                   '-e',f'SUMMARY={relative}/summary.json','--out',f'json={relative}/samples.json.gz','/scripts/workload.js')
        (folder/'k6.log').write_text(log)
        after = h.php('state')
        comparable(before,after,2)
        save(folder/'integration-start-state.json',after)
        metrics = json.loads((folder/'summary.json').read_text())['metrics']
        require(metrics['http_reqs']['values']['count']==6 and metrics['iterations']['values']['count']==6, 'Unexpected k6 request count')
        require(metrics['http_req_failed']['values']['passes']==0 and metrics['checks']['values']['fails']==0, 'k6 HTTP checks failed')
        h.capture(folder)
        save(folder/'counters-after.json',h.counters())
        ids = h.dc('ps','-q').splitlines()
        stats = subprocess.check_output(['docker','stats','--no-stream','--format','json',*ids],text=True)
        (folder/'resources.jsonl').write_text(json.dumps({'utc':dt.datetime.now(dt.timezone.utc).isoformat(),'docker':stats})+'\n')
        save(folder/'manifest.json',{'run':run,'phase':'integration','strategy':strategy,'profile':'F0',
                                    'session_id':h.session_id,'project':h.project,
                                    'arguments':{'block':'integration-F0','requests':6,'vus':1},
                                    'utc':dt.datetime.now(dt.timezone.utc).isoformat()})
        export(folder)
        with gzip.open(folder/'samples.json.gz','rt') as stream:
            rows = [json.loads(line) for line in stream]
        require(any(r['type']=='Point' for r in rows), 'Missing real k6 samples')
        with (folder/'http.csv').open() as stream:
            exported=list(csv.DictReader(stream))
        require(exported and all(r['block']=='integration-F0' and r['profile']=='F0' for r in exported), 'Export identifiers missing')
        secrets = ['benchmark_runner_only','benchmark_admin_only','Benchmark-only-2026!',*tokens]
        for path in folder.rglob('*'):
            if path.is_file():
                data = gzip.open(path,'rb').read() if path.suffix=='.gz' else path.read_bytes()
                require(not any(s.encode() in data for s in secrets), f'Secret found in artifact {path.name}')
        print(f'{strategy}: final restore and artifact verification', flush=True)
        h.restore()
        require(h.php('state')==baseline, 'Final restore mismatch')
        save(folder/'validation.json',{'phase':'integration','passed':True,'http_requests':result['requests'],
                                      'k6_requests':6,'vus':1,'restores_exact':2,'logical_hashes_match':True,
                                      'secret_scan':'passed','pilot_executed':False,'confirmatory_executed':False})
    finally:
        fixture.unlink(missing_ok=True)
    print(folder,flush=True)


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--strategy',required=True,choices=STRATEGIES)
    parser.add_argument('--session',help='Isolated session ID; omit for legacy sessions')
    args=parser.parse_args()
    os.umask(0o077)
    validate(args.strategy,args.session)
