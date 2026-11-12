<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantProvisioner
{
    public function __construct(private SchemaRegistry $registry) {}

    public function provision(Tenant $tenant, bool $create = true): void
    {
        if (TenancyStrategy::configured() === TenancyStrategy::SeparateDatabases) {
            app(DatabaseProvisioner::class)->provision($tenant, $create);

            return;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \LogicException('Tenancy requires PostgreSQL.');
        }
        DB::transaction(function () use ($tenant, $create): void {
            $id = (int) $tenant->id;
            $schema = SchemaName::forTenant($id);
            $quoted = SchemaName::quote($schema, $id);
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['tenancy:'.$id]);
            Tenant::whereKey($id)->lockForUpdate()->firstOrFail();
            $entry = DB::table('public.tenant_schemas')->where('tenant_id', $id)->first();
            if (! $entry) {
                if (! $create) {
                    throw new \LogicException('Tenant must be provisioned before migration.');
                }
                if (DB::selectOne('select oid from pg_namespace where nspname = ?', [$schema])) {
                    throw new \LogicException('Refusing to adopt an unregistered schema.');
                }
                DB::statement("CREATE SCHEMA $quoted");
                Schema::create($schema.'.migrations', function (Blueprint $table): void {
                    $table->id();
                    $table->string('migration')->unique();
                    $table->integer('batch');
                });
                DB::table('public.tenant_schemas')->insert(['tenant_id' => $id, 'schema_name' => $schema, 'status' => 'provisioning', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                SchemaName::validate($entry->schema_name, $id);
                DB::table('public.tenant_schemas')->where('tenant_id', $id)->update(['status' => 'provisioning', 'updated_at' => now()]);
            }
            $applied = DB::table($schema.'.migrations')->pluck('migration')->all();
            $batch = (int) DB::table($schema.'.migrations')->max('batch') + 1;
            foreach ($this->registry->migrations() as $name => $file) {
                if (! in_array($name, $applied, true)) {
                    (require $file)->up($schema, $id);
                    DB::table($schema.'.migrations')->insert(['migration' => $name, 'batch' => $batch]);
                }
            }
            $this->registry->assertComplete($schema);
            DB::table('public.tenant_schemas')->where('tenant_id', $id)->update(['status' => 'ready', 'updated_at' => now()]);
        });
    }
}
