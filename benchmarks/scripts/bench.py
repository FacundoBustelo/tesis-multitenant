#!/usr/bin/env python3
"""Explicit pilot commands only. No command automatically launches a campaign."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import platform
import re
import subprocess
import threading
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]
BASE = ROOT / 'benchmarks'
STRATEGIES = ('shared_tables', 'separate_schemas', 'separate_databases')
PHASES = ('pilot', 'confirmatory')
CONFIG = json.loads((BASE / 'config/pilot.json').read_text())


def source_hashes():
    files = [p for tree in ('src/app','src/config','src/database','src/routes','benchmarks') for p in (ROOT/tree).rglob('*')
             if p.is_file() and 'results' not in p.parts and '__pycache__' not in p.parts]
    files += [ROOT/'src/composer.lock', ROOT/'src/bootstrap/app.php', ROOT/'src/bootstrap/providers.php']
    return {str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(set(files))}


def validate_inventory(names, expected):
    if set(names) != set(expected) or 'benchmark' not in expected:
        raise RuntimeError('Database inventory mismatch; no restore permitted')
    if any(n != 'benchmark' and not re.fullmatch(r'tesis_tenant_[1-9][0-9]*', n) for n in names):
        raise RuntimeError('Unsafe database name')


def canonical_state(state):
    """Catalog row order is not part of sequence state; preserve every value."""
    state = json.loads(json.dumps(state))
    key = lambda row: (row['schemaname'], row['sequencename'])
    state['central_sequences'] = sorted(state['central_sequences'], key=key)
    state['sequences'] = {tenant: sorted(rows, key=key) for tenant, rows in state['sequences'].items()}
    return state


def comparable(before, after, warmup):
    if before['record_sequence'] != after['record_sequence']:
        raise RuntimeError('Record sequence changed during read warmup')
    if before['token_count'] != after['token_count'] or after['central_auth_audit']:
        raise RuntimeError('Token or authentication state mismatch')
    for a, b in zip(before['tenants'], after['tenants'], strict=True):
        if any(a[k] != b[k] for k in ('id', 'records', 'hash', 'residuals')) or b['residuals']:
            raise RuntimeError('Dataset changed during warmup')
        expected = {'record.list': warmup // 2, 'record.read': warmup // 2}
        if {x['action']: int(x['n']) for x in b['audit']} != expected:
            raise RuntimeError('Warmup audit is not equivalent')
    if audit_sequence_sum(after) - audit_sequence_sum(before) != len(before['tenants']) * warmup:
        raise RuntimeError('Audit sequences do not match warmup effects')
    if after['audit_total'] != len(before['tenants']) * warmup:
        raise RuntimeError('Audit volume mismatch')


def audit_sequence_sum(state):
    values = {}
    for tenant, sequences in state['sequences'].items():
        for seq in sequences:
            if seq['sequencename'] != 'audit_logs_id_seq':
                continue
            database = tenant if state['strategy'] == 'separate_databases' else 'central'
            values[(database, seq['schemaname'], seq['sequencename'])] = int(seq['last_value'] or 0)
    return sum(values.values())


class Harness:
    def __init__(self, strategy, session_id=None, phase=None):
        if session_id is not None and not re.fullmatch(r'[a-z][a-z0-9-]{0,31}', session_id):
            raise ValueError('Session ID must be 1-32 lowercase letters/digits/hyphens, starting with a letter')
        if phase is not None and phase not in PHASES:
            raise ValueError('Phase must be one of ' + ', '.join(PHASES))
        self.strategy = strategy
        self.session_id = session_id
        self.phase = phase
        self.project = 'tesis-benchmark-' + strategy.replace('_', '-')
        root = BASE / 'results'
        if session_id is not None:
            self.project += '-' + session_id
            root = root / 'sessions' / session_id
        self.folder = root / strategy
        self.result_path = Path('/results') / self.folder.relative_to(BASE / 'results')
        self.session = self.folder / 'session.json'
        self.env = dict(os.environ, BENCH_STRATEGY=strategy, BENCH_UID=str(os.getuid()), BENCH_GID=str(os.getgid()))
        if self.session.exists():
            self.state = json.loads(self.session.read_text())
            if session_id is not None and (self.state.get('session_id') != session_id or self.state.get('project') != self.project):
                raise RuntimeError('Session identity mismatch')
            # Historical sessions predate the explicit label and remain 'pilot'.
            if phase is not None and self.state.get('phase', 'pilot') != phase:
                raise RuntimeError('Session phase mismatch')
            self.env['BENCH_MARKER'] = self.state['marker']
        else:
            self.state = {}

    def dc(self, *args, binary=False, merge_error=False, **kw):
        command = ['docker', 'compose', '-f', str(BASE / 'compose.yaml'), '-p', self.project, *args]
        result = subprocess.run(command, env=self.env, check=True, capture_output=True, text=not binary, **kw)
        return result.stdout + result.stderr if merge_error else result.stdout

    def sql(self, query, database='benchmark'):
        return self.dc('exec', '-T', 'db', 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-U', 'bench_admin', '-d', database, '-Atc', query).strip()

    def php(self, command, *args):
        return json.loads(self.dc('run', '--rm', '-T', 'admin', 'php', '/bench/fixtures/manage.php', command, *map(str, args)))

    def guard(self):
        if not self.state or not re.fullmatch('[a-f0-9]{32}', self.state['marker']):
            raise RuntimeError('Missing session marker')
        container = self.dc('ps', '-q', 'db').strip()
        info = json.loads(subprocess.check_output(['docker', 'inspect', container], text=True))[0]
        labels = info['Config']['Labels']
        mounts = [m for m in info['Mounts'] if m['Destination'] == '/var/lib/postgresql/data']
        if labels.get('com.docker.compose.project') != self.project or len(mounts) != 1:
            raise RuntimeError('Wrong compose project or volume')
        if mounts[0].get('Name') != self.project + '_database' or info['HostConfig'].get('PortBindings'):
            raise RuntimeError('Database must use its own unpublished volume')
        if self.sql('select marker from public.benchmark_marker') != self.state['marker']:
            raise RuntimeError('Wrong database marker')
        names = self.sql("select datname from pg_database where not datistemplate and datname <> 'postgres' order by datname").splitlines()
        expected = ['benchmark'] + self.sql('select database_name from tenant_databases order by tenant_id').splitlines()
        validate_inventory(names, expected)
        if self.state.get('databases') and set(expected) != set(self.state['databases']):
            raise RuntimeError('Inventory differs from snapshot')
        for name in names:
            owner = self.sql(f"select pg_get_userbyid(datdba) from pg_database where datname='{name}'")
            if owner != 'bench_admin':
                raise RuntimeError('Unexpected database owner')
            if name != 'benchmark':
                tenant_id = int(name.rsplit('_', 1)[1])
                marker = self.sql(f'select marker from tenant_databases where tenant_id={tenant_id}')
                if self.sql(f'select marker from tenant_metadata where tenant_id={tenant_id}', name) != marker:
                    raise RuntimeError('Tenant marker mismatch')
        return names

    def init(self, profile):
        if self.session.exists():
            raise RuntimeError('Session already exists; use restore, never reseed')
        # Refuse pre-existing volumes, including interrupted initialization.
        volumes = subprocess.check_output(['docker', 'volume', 'ls', '--format', '{{.Name}}'], text=True).splitlines()
        if self.project + '_database' in volumes:
            raise RuntimeError('Existing experimental volume requires manual reconciliation')
        self.folder.mkdir(parents=True, exist_ok=False)
        os.chmod(self.folder, 0o700)
        self.env['BENCH_MARKER'] = uuid.uuid4().hex
        self.state = {'marker': self.env['BENCH_MARKER'], 'strategy': self.strategy, 'profile': profile, 'phase': self.phase or 'pilot',
                      'session_id': self.session_id, 'project': self.project}
        self.session.write_text(json.dumps(self.state, indent=2))
        self.state['source_hashes'] = source_hashes()
        self.session.write_text(json.dumps(self.state,indent=2))
        self.dc('build', 'app', 'admin')
        self.dc('up', '-d', '--wait', 'db')
        self.dc('run', '--rm', '-T', 'admin', 'php', 'artisan', 'tenancy:install', '--no-interaction', '--force')
        t, n = CONFIG['profiles'][profile]
        self.php('seed', t, n)
        self.state['databases'] = self.guard()
        self.session.write_text(json.dumps(self.state, indent=2))
        self.snapshot()
        self.dc('up', '-d', 'app', 'nginx')

    def snapshot(self):
        names = self.guard()
        for name in names:
            path = self.folder / (name + '.dump')
            if path.exists():
                raise RuntimeError('Immutable baseline dump already exists')
            path.write_bytes(self.dc('exec', '-T', 'db', 'pg_dump', '-U', 'bench_admin', '-Fc', '-d', name, binary=True))
        (self.folder / 'baseline.json').write_text(json.dumps(self.php('state'), indent=2))
        self.state['dump_hashes'] = {n: hashlib.sha256((self.folder / (n + '.dump')).read_bytes()).hexdigest() for n in names}
        self.session.write_text(json.dumps(self.state, indent=2))

    def restore(self):
        names = self.guard()  # Validate the complete set before the first destructive action.
        for name in names:
            blob = (self.folder / (name + '.dump')).read_bytes()
            if hashlib.sha256(blob).hexdigest() != self.state['dump_hashes'][name]:
                raise RuntimeError('Snapshot hash mismatch')
        self.dc('stop', 'nginx', 'app')
        active = int(self.sql("select count(*) from pg_stat_activity where backend_type='client backend' and datname is not null and pid<>pg_backend_pid()"))
        if active:
            raise RuntimeError('Other clients active; refusing restore')
        for name in names:
            self.dc('exec', '-T', 'db', 'pg_restore', '-U', 'bench_admin', '--exit-on-error', '--clean', '--if-exists', '-d', name,
                    binary=True, input=(self.folder / (name + '.dump')).read_bytes())
            self.sql('ANALYZE', name)
        self.guard()
        actual = self.php('state')
        baseline = json.loads((self.folder / 'baseline.json').read_text())
        if canonical_state(actual) != canonical_state(baseline):
            raise RuntimeError('Restored records/audit/sequences differ from baseline')
        self.dc('up', '-d', 'app', 'nginx')
        return {'exact': True, 'utc': dt.datetime.now(dt.timezone.utc).isoformat(),
                'databases': names, 'dump_hashes': self.state['dump_hashes'],
                'state_sha256': hashlib.sha256(json.dumps(canonical_state(actual), sort_keys=True).encode()).hexdigest()}

    def capture(self, folder):
        (folder / 'host.json').write_text(json.dumps({'platform': platform.uname()._asdict(), 'cpu_count': os.cpu_count()}, indent=2))
        commands = {'docker': ['docker', 'version'], 'compose': ['docker','compose','version'],
                    'cpu': ['lscpu'], 'memory': ['free','-b'], 'disk': ['lsblk','-J'], 'mounts': ['findmnt','-J'],
                    'swap': ['swapon','--show'], 'load': ['cat','/proc/loadavg'], 'processes': ['ps','-eo','comm,%cpu,%mem']}
        for label, command in commands.items():
            result = subprocess.run(command, capture_output=True, text=True)
            (folder / (label + '.txt')).write_text(result.stdout + result.stderr)
        for label, args in {'php': ('exec','-T','app','php','-r',"echo json_encode(['version'=>PHP_VERSION,'extensions'=>get_loaded_extensions(),'ini'=>ini_get_all()], JSON_PRETTY_PRINT);"), 'fpm': ('exec','-T','app','php-fpm','-tt'),
                            'images': ('images','--format','json'),
                            'app-cgroups': ('exec','-T','app','cat','/sys/fs/cgroup/cpu.max','/sys/fs/cgroup/memory.max'),
                            'db-cgroups': ('exec','-T','db','cat','/sys/fs/cgroup/cpu.max','/sys/fs/cgroup/memory.max')}.items():
            try:
                result = self.dc(*args,merge_error=True)
            except subprocess.CalledProcessError as e:
                result = e.stdout + e.stderr
            (folder / (label + '.txt')).write_text(result)
        governors={str(p):p.read_text().strip() for p in Path('/sys/devices/system/cpu').glob('cpu*/cpufreq/scaling_governor')}
        (folder/'cpu-governors.json').write_text(json.dumps(governors,indent=2))
        ids=self.dc('ps','-q').splitlines()
        inspected=json.loads(subprocess.check_output(['docker','inspect',*ids],text=True))
        # Config.Env contains credentials: deliberately omit it.
        safe=[{k:c.get(k) for k in ('Id','Image','HostConfig','Mounts','NetworkSettings')} for c in inspected]
        (folder/'containers.json').write_text(json.dumps(safe,indent=2))
        images=sorted({c['Image'] for c in inspected} | {'grafana/k6:1.3.0'})
        (folder/'image-inspect.json').write_bytes(subprocess.check_output(['docker','image','inspect',*images]))
        (folder/'pg-settings.json').write_text(self.sql("select json_agg(x) from (select name,setting,unit,source from pg_settings order by name) x"))
        for name in self.guard():
            (folder/(name+'-schema.sql')).write_bytes(self.dc('exec','-T','db','pg_dump','-U','bench_admin','--schema-only',name,binary=True))
        (folder/'source-hashes.json').write_text(json.dumps(source_hashes(),indent=2))
        (folder/'pilot-config.json').write_text(json.dumps(CONFIG,indent=2))

    def counters(self):
        return {view: json.loads(self.sql(f'select coalesce(json_agg(t),\'[]\'::json) from {view} t')) for view in ('pg_stat_database','pg_stat_wal','pg_stat_io','pg_stat_bgwriter','pg_stat_activity')}

    def k6(self, folder, mode, fixture, args):
        options={'MODE':mode,'FIXTURE':str(fixture),'SUMMARY':str(folder/'summary.json'),'VUS':args.vus,
                 'MAX_VUS':CONFIG['max_vus'],'DURATION':args.duration,'WARMUP_REQUESTS':CONFIG['warmup_requests_per_tenant'],
                 'VICTIM_RATE':args.victim_rate,'NOISE_RATE':args.noise_rate,'NOISE_MODE':args.noise_mode}
        if args.rate: options['RATE']=args.rate
        envargs=[item for k,v in options.items() for item in ('-e', f'{k}={v}')]
        return self.dc('run','--rm','-T','k6','run',*envargs,'--out',f'json={folder}/samples.json.gz','/scripts/workload.js')

    def run(self, args):
        try:
            return self._run(args)
        finally:
            (self.folder/'tokens.private.json').unlink(missing_ok=True)

    def _run(self, args):
        # Avoid concurrent experimental projects on the same host.
        projects = subprocess.check_output(['docker','ps','--format','{{.Label \"com.docker.compose.project\"}}'],text=True).splitlines()
        if any(p.startswith('tesis-benchmark-') and p != self.project for p in projects):
            raise RuntimeError('Stop other experimental projects before measuring')
        if source_hashes() != self.state.get('source_hashes'):
            raise RuntimeError('Source changed since build; initialize a new reconciled experiment')
        restoration = self.restore()
        run_id=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%S')+'-'+uuid.uuid4().hex[:6]
        folder=self.folder/run_id; folder.mkdir(); os.chmod(folder,0o700)
        relative=self.result_path/run_id
        (folder/'restoration.json').write_text(json.dumps(restoration, indent=2))
        fixture=self.folder/'tokens.private.json'
        fixture.write_text(json.dumps(self.php('tokens',CONFIG['max_vus'])))
        os.chmod(fixture,0o600)
        before=self.php('state'); (folder/'before-warmup.json').write_text(json.dumps(before,indent=2))
        warm=folder/'warmup'; warm.mkdir()
        self.k6(relative/'warmup','warmup',self.result_path/fixture.name,args)
        after=self.php('state'); comparable(before,after,CONFIG['warmup_requests_per_tenant'])
        (folder/'measurement-start-state.json').write_text(json.dumps(after,indent=2))
        self.capture(folder)
        (folder/'counters-before.json').write_text(json.dumps(self.counters()))
        monitored = not getattr(args, 'no_monitor', False)
        manifest={'run':run_id,'phase':self.state.get('phase','pilot'),'strategy':self.strategy,'profile':self.state['profile'], 'session_id':self.session_id, 'project':self.project, 'arguments':vars(args),'utc':dt.datetime.now(dt.timezone.utc).isoformat(), 'conventions':CONFIG['conventions'],
                  'monitoring': {'enabled': monitored, 'docker_wait_seconds': 1, 'postgres_fpm_every': 5,
                                 'boundary_counters': True}}
        (folder/'manifest.json').write_text(json.dumps(manifest,indent=2))
        stop=threading.Event()
        def monitor():
            with (folder/'resources.jsonl').open('w') as stream:
                i=0
                while not stop.is_set():
                    try:
                        ids=subprocess.check_output(['docker','ps','--filter',f'label=com.docker.compose.project={self.project}','-q'],text=True).splitlines()
                        stats=subprocess.check_output(['docker','stats','--no-stream','--format','json',*ids],text=True) if ids else ''
                        row={'utc':dt.datetime.now(dt.timezone.utc).isoformat(),'docker':stats}
                        if i%5==0: row['postgres']=self.counters(); row['fpm']=self.dc('exec','-T','app','php','-r',"echo file_get_contents('http://nginx/fpm-status?json');")
                        stream.write(json.dumps(row)+'\n'); stream.flush(); i+=1
                    except Exception as exc: stream.write(json.dumps({'collector_error':str(exc)})+'\n')
                    stop.wait(1)
        worker=threading.Thread(target=monitor) if monitored else None
        if worker: worker.start()
        try:
            output=self.k6(relative,args.mode,self.result_path/fixture.name,args)
            (folder/'k6.log').write_text(output)
        except subprocess.CalledProcessError as exc:
            (folder/'k6.log').write_text((exc.stdout or '') + (exc.stderr or ''))
            (folder/'execution-error.json').write_text(json.dumps({'returncode': exc.returncode}, indent=2))
            raise
        finally:
            stop.set()
            if worker: worker.join()
            (folder/'counters-after.json').write_text(json.dumps(self.counters()))
            end=self.php('state')
            (folder/'measurement-end-state.json').write_text(json.dumps(end,indent=2))
            summary_path=folder/'summary.json'
            metrics=json.loads(summary_path.read_text()).get('metrics',{}) if summary_path.exists() else {}
            count=lambda name: metrics.get(name,{}).get('values',{}).get('count',0)
            report={'cycles_started':count('cycles_started'),'cycles_completed':count('cycles_completed'),
                    'cycles_reported_incomplete':count('cycles_incomplete'),
                    'cycles_unfinished_or_failed':count('cycles_started')-count('cycles_completed'),
                    'residual_records':sum(t['residuals'] for t in end['tenants']),
                    'operation_errors':count('operation_errors'),'dropped_iterations':count('dropped_iterations'),
                    'summary_present':summary_path.exists()}
            (folder/'validation.json').write_text(json.dumps(report,indent=2))
            fixture.unlink(missing_ok=True)
        print(folder)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command',choices=['init','preflight','restore','capture','pilot-run'])
    parser.add_argument('--strategy',required=True,choices=STRATEGIES)
    parser.add_argument('--session',help='New isolated session ID; omit only for legacy sessions')
    parser.add_argument('--block',default='exploratory',help='Equivalent independent block identifier')
    parser.add_argument('--profile',choices=CONFIG['profiles'],default='F0')
    parser.add_argument('--mode',choices=CONFIG['scenarios'],default='R')
    parser.add_argument('--vus',type=int,choices=[1,8,32],default=1)
    parser.add_argument('--duration',choices=['120s','300s','600s'],default='120s')
    parser.add_argument('--rate',type=int)
    parser.add_argument('--victim-rate',type=int,default=1)
    parser.add_argument('--noise-rate',type=int,default=0)
    parser.add_argument('--noise-mode',choices=['R','W'],default='W')
    parser.add_argument('--no-monitor',action='store_true',help='Diagnostic run without periodic Docker/PostgreSQL/FPM collection; boundary captures remain')
    parser.add_argument('--phase',choices=PHASES,help='Campaign label stored in new sessions and manifests; must match an existing session. Omit for historical behaviour (pilot)')
    args=parser.parse_args()
    if args.mode == 'interference' and args.rate is not None:
        parser.error('Interference uses victim-rate and noise-rate')
    if args.rate is not None and args.rate<=0 or args.victim_rate<=0 or args.noise_rate<0:
        parser.error('Rates must be positive (noise may be zero)')
    os.umask(0o077)
    harness=Harness(args.strategy,args.session,args.phase)
    if args.command=='init': harness.init(args.profile)
    elif args.command=='preflight': print(json.dumps({'strategy':args.strategy,'databases':harness.guard(),'safe':True}))
    elif args.command=='restore': harness.restore()
    elif args.command=='capture': harness.guard(); harness.capture(harness.folder)
    else: harness.run(args)


if __name__=='__main__': main()
