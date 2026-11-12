<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\Tenant;
use App\Tenancy\DatabaseName;
use App\Tenancy\DatabaseProvisioner;
use App\Tenancy\DatabaseRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\TenantDatabaseCleanup;
use Tests\TestCase;

class SeparateDatabasesTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('tenancy.strategy') !== 'separate_databases') {
            $this->markTestSkipped('Requires separate_databases.');
        } $this->seed();
    }

    public function test_databases_identity_indexes_and_unique_ids(): void
    {
        $names = [];
        $ids = [];
        foreach (Tenant::all() as $tenant) {
            $db = DB::connection($this->tenantConnection($tenant->slug));
            $names[] = $db->selectOne('select current_database() as name')->name;
            $this->assertSame(DatabaseName::forTenant($tenant->id), end($names));
            $this->assertFalse($db->getSchemaBuilder()->hasTable('users'));
            $this->assertFalse($db->getSchemaBuilder()->hasTable('personal_access_tokens'));
            $this->assertSame(5, $db->table('records')->count());
            $this->assertSame(1, $db->table('migrations')->count());
            $ids = array_merge($ids, $db->table('records')->pluck('id')->all());
            foreach (['records', 'audit_logs'] as $table) {
                $columns = fn ($schema) => collect($schema->getIndexes('public.'.$table))->map(fn ($index) => $index['columns'])->sort()->values()->all();
                $this->assertSame($columns(DB::connection()->getSchemaBuilder()), $columns($db->getSchemaBuilder()));
            }
            $this->assertSame([], $db->select("select 1 from pg_constraint where contype='f'"));
        }
        $this->assertCount(3, array_unique($names));
        $this->assertCount(15, array_unique($ids));
        $this->assertDatabaseCount('public.records', 0);
        $this->assertDatabaseCount('users', 3);
    }

    public function test_closed_connections_models_and_queries_cannot_be_reused_even_for_same_tenant(): void
    {
        $context = app(TenantContext::class);
        $id = Tenant::where('slug', 'alpha')->value('id');
        $context->set($id);
        $alias = $context->connection();
        $db = DB::connection($alias);
        $model = Record::first();
        $query = Record::query()->toBase();
        $context->clear();
        $this->assertArrayNotHasKey($alias, DB::getConnections());
        $this->assertNull(config('database.connections.'.$alias));
        $context->set($id);
        foreach ([fn () => $model->refresh(), fn () => $query->count(), fn () => $db->select('select 1')] as $op) {
            try {
                $op();
                $this->fail('Closed resource accepted');
            } catch (\LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame(5, Record::count());
        $context->clear();
    }

    public function test_commands_are_repeatable(): void
    {
        $this->artisan('tenancy:provision', ['--tenant' => 'alpha'])->assertSuccessful();
        $this->artisan('tenancy:migrate', ['--all' => true])->assertSuccessful();
        $this->artisan('tenancy:migrate')->assertFailed();
        $this->seed();
        $this->assertDatabaseCount('tenant_databases', 3);
        $this->assertSame(5, $this->tenantTable('records')->count());
    }

    public function test_failure_after_local_migrations_rolls_back_and_retry_recovers(): void
    {
        $tenant = Tenant::factory()->create();
        $registry = new class extends DatabaseRegistry
        {
            public function assertComplete(Connection $db, int $id, string $marker): void
            {
                throw new \RuntimeException('Injected');
            }
        };
        try {
            (new DatabaseProvisioner($registry))->provision($tenant);
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected', $e->getMessage());
        }
        $this->assertDatabaseHas('tenant_databases', ['tenant_id' => $tenant->id, 'status' => 'failed']);
        $alias = app(DatabaseRegistry::class)->connect(DatabaseName::forTenant($tenant->id));
        try {
            $this->assertFalse(DB::connection($alias)->getSchemaBuilder()->hasTable('records'));
        } finally {
            app(DatabaseRegistry::class)->close($alias);
        }
        app(DatabaseProvisioner::class)->provision($tenant);
        app(TenantContext::class)->set($tenant->id);
        $this->assertSame(0, Record::count());
        app(TenantContext::class)->clear();
    }

    public function test_wrong_database_identifier_is_rejected_before_connection_and_cleanup(): void
    {
        $tenant = Tenant::first();
        $original = DatabaseName::forTenant($tenant->id);
        DB::table('tenant_databases')->where('tenant_id', $tenant->id)->update(['database_name' => 'tesis']);
        try {
            foreach ([fn () => app(TenantContext::class)->set($tenant->id), fn () => TenantDatabaseCleanup::clean()] as $op) {
                try {
                    $op();
                    $this->fail('Unsafe name accepted');
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('Invalid tenant database', $e->getMessage());
                }
            }
        } finally {
            DB::table('tenant_databases')->where('tenant_id', $tenant->id)->update(['database_name' => $original]);
        }
    }

    public function test_marker_mismatch_blocks_resolution_and_cleanup(): void
    {
        $tenant = Tenant::first();
        $entry = DB::table('tenant_databases')->where('tenant_id', $tenant->id)->first();
        DB::table('tenant_databases')->where('tenant_id', $tenant->id)->update(['marker' => 'invalid']);
        try {
            foreach ([fn () => app(TenantContext::class)->set($tenant->id), fn () => TenantDatabaseCleanup::clean()] as $op) {
                try {
                    $op();
                    $this->fail('Marker mismatch accepted');
                } catch (\RuntimeException|\LogicException $e) {
                    $this->assertNotEmpty($e->getMessage());
                }
            }
        } finally {
            DB::table('tenant_databases')->where('tenant_id', $tenant->id)->update(['marker' => $entry->marker]);
        }
    }

    public function test_test_role_cannot_drop_development_database(): void
    {
        $role = DB::selectOne('select rolsuper, rolcreaterole from pg_roles where rolname=current_user');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolcreaterole);
        $this->assertNotSame('tesis_test_runner', DB::selectOne("select pg_get_userbyid(datdba) as owner from pg_database where datname='tesis'")->owner);
    }

    public function test_local_checks_reject_wrong_tenant_and_actor_shape(): void
    {
        $tenant = Tenant::first();
        $db = DB::connection($this->tenantConnection($tenant->slug));
        foreach ([fn () => $db->table('records')->insert(['id' => 999, 'tenant_id' => 999, 'title' => 'x']), fn () => $db->table('audit_logs')->insert(['tenant_id' => $tenant->id, 'actor_type' => 'staff', 'actor_id' => 1, 'user_id' => 1, 'action' => 'test', 'resource_type' => 'record'])] as $op) {
            try {
                $db->transaction($op);
                $this->fail();
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }

    public function test_unready_and_pending_migrations_fail_closed(): void
    {
        $tenant = Tenant::first();
        $registry = app(DatabaseRegistry::class);
        $files = $registry->migrations();
        $mock = $this->partialMock(DatabaseRegistry::class);
        $mock->shouldReceive('migrations')->andReturn($files + ['pending' => __FILE__]);
        try {
            app(TenantContext::class)->set($tenant->id);
            $this->fail();
        } catch (\LogicException $e) {
            $this->assertStringContainsString('pending migrations', $e->getMessage());
        }
        $this->app->instance(DatabaseRegistry::class, $registry);
        DB::table('tenant_databases')->where('tenant_id', $tenant->id)->update(['status' => 'failed']);
        try {
            app(TenantContext::class)->set($tenant->id);
            $this->fail();
        } catch (\LogicException $e) {
            $this->assertStringContainsString('not provisioned', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        app(TenantContext::class)->id();
    }

    public function test_unregistered_database_is_never_adopted(): void
    {
        $tenant = Tenant::first();
        $entry = DB::table('tenant_databases')->where('tenant_id', $tenant->id)->first();
        DB::table('tenant_databases')->where('tenant_id', $tenant->id)->delete();
        try {
            try {
                app(DatabaseProvisioner::class)->provision($tenant);
                $this->fail();
            } catch (\LogicException $e) {
                $this->assertStringContainsString('unregistered', $e->getMessage());
            }
        } finally {
            DB::table('tenant_databases')->insert((array) $entry);
        }
    }

    public function test_provisioning_lock_excludes_a_second_postgresql_session(): void
    {
        $tenant = Tenant::first();
        $registry = new class extends DatabaseRegistry
        {
            public bool $observed = false;

            public function assertComplete(Connection $db, int $id, string $marker): void
            {
                $alias = $this->connect(DB::connection()->getDatabaseName());
                try {
                    $second = DB::connection($alias);
                    $locked = $second->selectOne('select pg_try_advisory_lock(hashtextextended(?,0)) as acquired', ['database:'.$id])->acquired;
                    if ($locked) {
                        throw new \RuntimeException('Provisioning lock absent.');
                    }
                    $this->observed = true;
                } finally {
                    $this->close($alias);
                }
                parent::assertComplete($db, $id, $marker);
            }
        };
        (new DatabaseProvisioner($registry))->provision($tenant);
        $this->assertTrue($registry->observed);
        $alias = $registry->connect(DB::connection()->getDatabaseName());
        try {
            $db = DB::connection($alias);
            $this->assertTrue($db->selectOne('select pg_try_advisory_lock(hashtextextended(?,0)) as acquired', ['database:'.$tenant->id])->acquired);
        } finally {
            $registry->close($alias);
        }
    }
}
