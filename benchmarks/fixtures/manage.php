<?php
require __DIR__.'/data.php';
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// This standalone entry point is outside Artisan's command error boundary.
// Laravel renders uncaught exceptions here but may terminate with status zero.
// Return a failing process status so automation cannot mistake a refusal for success.
set_exception_handler(static function (Throwable $error): void {
    $message = get_class($error) === RuntimeException::class ? $error->getMessage() : get_class($error);
    fwrite(STDERR, "Benchmark fixture failed: $message\n");
    exit(1);
});
use Illuminate\Support\Facades\DB;
use App\Models\{Tenant, User, Staff, Record};
use App\Tenancy\{TenantContext, TenantProvisioner};

function guard(): void
{
    $db = DB::connection();
    $identity = $db->selectOne('select current_database() as db, current_user as role');
    if (!app()->environment('benchmark') || config('database.connections.pgsql.host') !== 'db'
        || $identity->db !== 'benchmark' || !in_array($identity->role, ['bench_admin', 'bench_runner'], true)
        || !preg_match('/^[a-f0-9]{32}$/D', getenv('BENCH_MARKER') ?: '')
        || DB::table('benchmark_marker')->value('marker') !== getenv('BENCH_MARKER')) {
        throw new RuntimeException('Benchmark destination/marker mismatch');
    }
}
guard();
$command = $argv[1] ?? 'state';
$ctx = app(TenantContext::class);
$strategy = config('tenancy.strategy');
$seq = ['shared_tables'=>'records_id_seq', 'separate_schemas'=>'tenant_records_id_seq', 'separate_databases'=>'database_records_id_seq'][$strategy];
if ($command === 'seed') {
    $tenants = (int)($argv[2] ?? 0); $rows = (int)($argv[3] ?? 0);
    if ($tenants < 2 || $tenants > 100 || $rows < 1 || $rows > 10000 || Tenant::count()) {
        throw new RuntimeException('Seed requires empty dedicated dataset and bounded dimensions');
    }
    $hash = Illuminate\Support\Facades\Hash::make('Benchmark-only-2026!');
    for ($t = 1; $t <= $tenants; $t++) {
        $tenant = Tenant::create(['name'=>"Tenant $t", 'slug'=>"bench-$t"]);
        foreach ([User::class, Staff::class] as $class) {
            $actor = new $class(['name'=>'Benchmark actor','email'=>'actor@example.test','password'=>$hash]);
            $actor->tenant_id = $tenant->id; $actor->save();
        }
        if ($strategy !== 'shared_tables') app(TenantProvisioner::class)->provision($tenant);
        $ctx->set($tenant->id);
        try {
            $model = new Record; $db = $model->getConnection(); $table = $model->getTable();
            // Fixture import is outside measurement; runtime CRUD keeps its original ID allocator.
            for ($start=1; $start <= $rows; $start+=250) {
                $batch=[];
                for ($i=$start; $i<=min($rows,$start+249); $i++) {
                    $id=DB::selectOne("select nextval('public.$seq') as id")->id;
                    $batch[]=array_merge(fixturePayload($t,$i), ['id'=>$id,'tenant_id'=>$t,'created_at'=>'2026-01-01 00:00:00','updated_at'=>'2026-01-01 00:00:00']);
                }
                $db->table($table)->insert($batch);
            }
        } finally { $ctx->clear(); }
    }
    // Identical runtime privileges over every existing experimental object.
    $connections=[DB::connection()];
    foreach (DB::table('tenant_databases')->get() as $entry) {
        $alias=app(App\Tenancy\DatabaseRegistry::class)->connect($entry->database_name);
        $connections[]=DB::connection($alias);
    }
    foreach ($connections as $db) {
        foreach ($db->select("select nspname from pg_namespace where nspname='public' or nspname ~ '^tenant_[1-9][0-9]*$'") as $s) {
            $schema='"'.$s->nspname.'"';
            $db->statement("GRANT USAGE ON SCHEMA $schema TO bench_runner");
            $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA $schema TO bench_runner");
            $db->statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA $schema TO bench_runner");
            $db->statement("ANALYZE");
        }
    }
    echo json_encode(['seed'=>20261102,'tenants'=>$tenants,'records_per_tenant'=>$rows], JSON_THROW_ON_ERROR); exit;
}
if ($command === 'tokens') {
    $slots=(int)($argv[2] ?? 128);
    if ($slots<1 || $slots>512) throw new RuntimeException('Invalid token slots');
    $out=['tenants'=>[], 'expires_at'=>now()->addHour()->toIso8601String()];
    foreach (Tenant::orderBy('id')->get() as $tenant) {
        $ctx->set($tenant->id);
        try {
            $entry=['id'=>$tenant->id,'slug'=>$tenant->slug,'ids'=>Record::orderBy('id')->pluck('id')->all(),'user'=>[],'staff'=>[]];
            foreach (['user'=>User::class,'staff'=>Staff::class] as $type=>$class) {
                $actor=$class::where('tenant_id',$tenant->id)->firstOrFail();
                for ($i=0;$i<$slots;$i++) $entry[$type][]=$actor->createToken('benchmark',['*'],now()->addHour())->plainTextToken;
            }
            $out['tenants'][]=$entry;
        } finally { $ctx->clear(); }
    }
    echo json_encode($out, JSON_THROW_ON_ERROR); exit;
}
if ($command !== 'state') throw new RuntimeException('Unknown command');
$out=['strategy'=>$strategy,'tenants'=>[], 'sequences'=>[], 'audit_total'=>0];
foreach (Tenant::orderBy('id')->get() as $tenant) {
    $ctx->set($tenant->id);
    try {
        $record=new Record; $db=$record->getConnection();
        $hash=hash_init('sha256'); $count=0; $residuals=0;
        foreach ($db->table($record->getTable())->where('tenant_id',$tenant->id)->orderBy('id')->cursor() as $r) {
            hash_update($hash,json_encode([$r->title,$r->content],JSON_THROW_ON_ERROR)."\n"); $count++;
            if (str_starts_with($r->title,'bench-write-')) $residuals++;
        }
        $audit=new App\Models\AuditLog;
        $actions=$db->table($audit->getTable())->where('tenant_id',$tenant->id)->selectRaw('action, count(*) as n')->groupBy('action')->orderBy('action')->get()->all();
        $out['tenants'][]=['id'=>$tenant->id,'records'=>$count,'hash'=>hash_final($hash),'residuals'=>$residuals,'audit'=>$actions];
        foreach ($actions as $a) $out['audit_total']+=(int)$a->n;
        $out['sequences'][$tenant->id]=$db->select("select schemaname,sequencename,last_value,increment_by,cache_size from pg_sequences where schemaname !~ '^pg_' order by schemaname,sequencename");
    } finally { $ctx->clear(); }
}
$out['record_sequence']=DB::selectOne("select last_value,is_called from public.$seq");
$out['central_auth_audit']=DB::table('public.audit_logs')->where('action','like','auth.%')->count();
$out['token_count']=DB::table('personal_access_tokens')->count();
$out['central_sequences']=DB::select("select schemaname,sequencename,last_value,increment_by,cache_size from pg_sequences where schemaname='public' order by schemaname,sequencename");
echo json_encode($out,JSON_THROW_ON_ERROR);
