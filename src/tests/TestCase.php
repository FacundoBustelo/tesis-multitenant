<?php

namespace Tests;

use App\Models\Tenant;
use App\Tenancy\DatabaseRegistry;
use App\Tenancy\SchemaName;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    protected static ?string $databaseRun = null;

    private array $inspectionConnections = [];

    protected function tenantConnection(string $slug = 'alpha'): ?string
    {
        if (config('tenancy.strategy') !== 'separate_databases') {
            return null;
        }

        return $this->inspectionConnections[$slug] ??= app(DatabaseRegistry::class)->ready((int) Tenant::where('slug', $slug)->value('id'));
    }

    protected function closeInspectionConnections(): void
    {
        foreach ($this->inspectionConnections as $alias) {
            app(DatabaseRegistry::class)->close($alias);
        }
        $this->inspectionConnections = [];
    }

    protected function tenantTable(string $table, string $slug = 'alpha')
    {
        $id = Tenant::where('slug', $slug)->value('id');
        $schema = config('tenancy.strategy') === 'separate_schemas' ? SchemaName::forTenant($id) : 'public';

        return DB::connection($this->tenantConnection($slug))->table($schema.'.'.$table);
    }

    protected function beforeRefreshingDatabase(): void
    {
        TestDatabaseGuard::assertSafe(DB::connection(), app()->environment());
        if (DB::connection()->getSchemaBuilder()->hasTable('tenant_databases') && DB::table('tenant_databases')->exists()) {
            throw new \RuntimeException('Recover registered test databases before refreshing central tables.');
        }
        if (! RefreshDatabaseState::$migrated) {
            TestDatabaseGuard::cleanRegisteredSchemas(DB::connection(), app()->environment());
        }
    }
}
