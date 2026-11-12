<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\Staff;
use App\Models\Tenant;
use App\Tenancy\SchemaName;
use App\Tenancy\SchemaRegistry;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\TestDatabaseGuard;
use Tests\TestCase;

class SeparateSchemasTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('tenancy.strategy') !== 'separate_schemas') {
            $this->markTestSkipped('Requires separate_schemas; run the second matrix command.');
        }
        $this->seed();
    }

    public function test_objects_are_separate_and_identity_stays_central(): void
    {
        $ids = [];
        $oids = [];
        foreach (Tenant::all() as $tenant) {
            $schema = SchemaName::forTenant($tenant->id);
            $this->assertSame($schema, app(SchemaRegistry::class)->readySchema($tenant->id));
            $this->assertSame(5, DB::table($schema.'.records')->count());
            $this->assertSame(1, DB::table($schema.'.migrations')->count());
            $this->assertFalse(Schema::hasTable($schema.'.users'));
            $this->assertFalse(Schema::hasTable($schema.'.personal_access_tokens'));
            $oids[] = DB::selectOne('select to_regclass(?)::oid as oid', [$schema.'.records'])->oid;
            foreach (['records', 'audit_logs'] as $table) {
                $indexColumns = fn (string $qualified) => collect(Schema::getIndexes($qualified))->map(fn (array $index) => $index['columns'])->sort()->values()->all();
                $this->assertSame($indexColumns('public.'.$table), $indexColumns($schema.'.'.$table));
            }
            $ids = array_merge($ids, DB::table($schema.'.records')->pluck('id')->all());
        }
        $this->assertCount(15, array_unique($ids));
        $this->assertCount(3, array_unique($oids));
        $this->assertDatabaseCount('public.records', 0);
        $this->assertDatabaseCount('public.audit_logs', 0);
        $this->assertDatabaseCount('public.users', 3);
        $sequences = DB::select("select sequencename, increment_by, cache_size from pg_sequences where schemaname = 'public' and sequencename in ('records_id_seq', 'tenant_records_id_seq') order by sequencename");
        $this->assertCount(2, $sequences);
        foreach ($sequences as $sequence) {
            $this->assertSame(1, $sequence->increment_by);
            $this->assertSame(1, $sequence->cache_size);
        }
        $this->assertFalse(DB::selectOne('select is_called from public.records_id_seq')->is_called);
    }

    public function test_administrative_commands_are_repeatable_and_require_explicit_selection(): void
    {
        $this->artisan('tenancy:install')->assertSuccessful();
        $this->artisan('tenancy:install')->assertSuccessful();
        $this->artisan('tenancy:provision', ['--tenant' => 'alpha'])->assertSuccessful();
        $this->artisan('tenancy:provision', ['--tenant' => 'alpha'])->assertSuccessful();
        $this->artisan('tenancy:migrate', ['--tenant' => 'alpha'])->assertSuccessful();
        $this->artisan('tenancy:migrate', ['--all' => true])->assertSuccessful();
        $this->artisan('tenancy:provision')->assertFailed();
        $this->artisan('tenancy:provision', ['--tenant' => 'missing'])->assertFailed();
        $this->artisan('tenancy:migrate')->assertFailed();
        $this->artisan('tenancy:migrate', ['--tenant' => 'alpha', '--all' => true])->assertFailed();
        $this->artisan('tenancy:migrate', ['--tenant' => 'missing'])->assertFailed();
        $this->seed();
        $this->assertDatabaseCount('public.tenant_schemas', 3);
        foreach (Tenant::all() as $tenant) {
            $schema = SchemaName::forTenant($tenant->id);
            $this->assertDatabaseCount($schema.'.records', 5);
            $this->assertDatabaseCount($schema.'.migrations', 1);
        }
    }

    public function test_provision_failure_rolls_back_ddl_and_registry_and_can_be_retried(): void
    {
        $tenant = Tenant::factory()->create();
        $registry = $this->partialMock(SchemaRegistry::class);
        $registry->shouldReceive('assertComplete')->once()->andThrow(new \RuntimeException('Injected failure'));
        try {
            (new TenantProvisioner($registry))->provision($tenant);
            $this->fail('Provisioning should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected failure', $exception->getMessage());
        }
        $schema = SchemaName::forTenant($tenant->id);
        $this->assertNull(DB::selectOne('select oid from pg_namespace where nspname = ?', [$schema]));
        $this->assertDatabaseMissing('public.tenant_schemas', ['tenant_id' => $tenant->id]);
        $this->app->instance(SchemaRegistry::class, new SchemaRegistry);
        (new TenantProvisioner(new SchemaRegistry))->provision($tenant);
        $this->assertSame($schema, app(SchemaRegistry::class)->readySchema($tenant->id));
    }

    public function test_pending_migration_is_independent_transactional_and_recoverable(): void
    {
        $tenant = Tenant::where('slug', 'alpha')->firstOrFail();
        $schema = SchemaName::forTenant($tenant->id);
        $files = (new SchemaRegistry)->migrations();
        $files['2026_10_31_000001_probe'] = base_path('tests/Fixtures/tenant_probe_migration.php');
        $registry = $this->partialMock(SchemaRegistry::class);
        $registry->shouldReceive('migrations')->andReturn($files);
        config(['testing.fail_tenant_migration' => true]);
        try {
            app(TenantContext::class)->set($tenant->id);
            $this->fail('Pending migration should block context.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('pending migrations', $exception->getMessage());
        }
        $provisioner = new TenantProvisioner($registry);
        try {
            $provisioner->provision($tenant, create: false);
            $this->fail('Migration should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected migration failure.', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable($schema.'.migration_probe'));
        $this->assertDatabaseCount($schema.'.migrations', 1);
        $this->assertDatabaseHas('public.tenant_schemas', ['tenant_id' => $tenant->id, 'status' => 'ready']);
        config(['testing.fail_tenant_migration' => false]);
        $provisioner->provision($tenant, create: false);
        $provisioner->provision($tenant, create: false);
        $this->assertTrue(Schema::hasTable($schema.'.migration_probe'));
        $this->assertDatabaseHas($schema.'.migrations', ['migration' => '2026_10_31_000001_probe', 'batch' => 2]);
        $beta = Tenant::where('slug', 'beta')->firstOrFail();
        $this->assertDatabaseCount(SchemaName::forTenant($beta->id).'.migrations', 1);
        $this->assertSame($schema, $registry->readySchema($tenant->id));
    }

    public function test_unprovisioned_schema_rejects_login_only_after_credentials_are_verified(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = new Staff(['name' => 'Unprovisioned', 'email' => 'staff@example.test', 'password' => 'Test-password!']);
        $actor->tenant_id = $tenant->id;
        $actor->save();
        $data = ['tenant' => $tenant->slug, 'email' => $actor->email, 'password' => 'wrong'];
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/staff/login', $data)->assertUnauthorized();
        Auth::forgetGuards();
        $data['password'] = 'Test-password!';
        $this->postJson('/api/v1/auth/staff/login', $data)->assertStatus(500);
        $this->assertDatabaseCount('public.personal_access_tokens', 0);
        $this->assertDatabaseMissing('public.tenant_schemas', ['tenant_id' => $tenant->id]);
        $this->assertNull(DB::selectOne('select oid from pg_namespace where nspname = ?', [SchemaName::forTenant($tenant->id)]));
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->id();
    }

    public function test_incomplete_schema_fails_closed_and_clears_previous_context(): void
    {
        TestDatabaseGuard::assertSafe(DB::connection(), app()->environment());
        $alpha = Tenant::where('slug', 'alpha')->value('id');
        $beta = Tenant::where('slug', 'beta')->value('id');
        app(TenantContext::class)->set($alpha);
        Schema::drop(SchemaName::forTenant($beta).'.audit_logs');
        try {
            app(TenantContext::class)->set($beta);
            $this->fail('Incomplete schema accepted.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        }
        $this->expectException(\LogicException::class);
        Record::query()->count();
    }

    public function test_invalid_registry_identifier_is_rejected_by_resolution_and_cleanup(): void
    {
        $gamma = Tenant::where('slug', 'gamma')->value('id');
        DB::table('public.tenant_schemas')->where('tenant_id', $gamma)->update(['schema_name' => 'public; DROP SCHEMA public CASCADE']);
        foreach ([fn () => app(TenantContext::class)->set($gamma), fn () => TestDatabaseGuard::cleanRegisteredSchemas(DB::connection(), app()->environment())] as $operation) {
            try {
                $operation();
                $this->fail('Invalid registry identifier accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Invalid tenant schema', $exception->getMessage());
            }
        }
        foreach (Tenant::all() as $tenant) {
            $this->assertTrue(Schema::hasTable(SchemaName::forTenant($tenant->id).'.records'));
        }
    }

    public function test_test_cleanup_only_removes_registered_validated_schemas(): void
    {
        $unregisteredId = 999999;
        $schema = SchemaName::forTenant($unregisteredId);
        $quoted = SchemaName::quote($schema, $unregisteredId);
        DB::statement("CREATE SCHEMA $quoted");
        TestDatabaseGuard::cleanRegisteredSchemas(DB::connection(), app()->environment());
        $this->assertNotNull(DB::selectOne('select oid from pg_namespace where nspname = ?', [$schema]));
        foreach (Tenant::all() as $tenant) {
            $this->assertNull(DB::selectOne('select oid from pg_namespace where nspname = ?', [SchemaName::forTenant($tenant->id)]));
        }
        $this->assertDatabaseCount('public.tenant_schemas', 0);
        $this->assertDatabaseCount('public.users', 3);
    }

    public function test_unregistered_schemas_are_not_adopted(): void
    {
        $tenant = Tenant::factory()->create();
        $quoted = SchemaName::quote(SchemaName::forTenant($tenant->id), $tenant->id);
        DB::statement("CREATE SCHEMA $quoted");
        $this->expectException(\LogicException::class);
        app(TenantProvisioner::class)->provision($tenant);
    }

    public function test_migration_requires_a_registered_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $this->expectException(\LogicException::class);
        app(TenantProvisioner::class)->provision($tenant, create: false);
    }

    public function test_unready_registry_entry_fails_closed(): void
    {
        $alpha = Tenant::where('slug', 'alpha')->value('id');
        DB::table('public.tenant_schemas')->where('tenant_id', $alpha)->update(['status' => 'provisioning']);
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->set($alpha);
    }

    public function test_physical_constraints_reject_wrong_tenant_and_inconsistent_actor(): void
    {
        $alpha = Tenant::where('slug', 'alpha')->value('id');
        $beta = Tenant::where('slug', 'beta')->value('id');
        $schema = SchemaName::forTenant($alpha);
        $actor = Staff::where('tenant_id', $alpha)->firstOrFail();
        foreach ([
            fn () => DB::table($schema.'.records')->insert(['tenant_id' => $beta, 'title' => 'Foreign tenant']),
            fn () => DB::table($schema.'.audit_logs')->insert(['tenant_id' => $alpha, 'actor_type' => 'user', 'actor_id' => $actor->id, 'staff_id' => $actor->id, 'action' => 'invalid', 'resource_type' => 'record']),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Constraint was not enforced.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        }
    }
}
