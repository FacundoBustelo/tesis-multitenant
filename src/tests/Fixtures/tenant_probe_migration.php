<?php

use App\Tenancy\SchemaName;
use Illuminate\Support\Facades\DB;

return new class
{
    public function up(string $schema, int $tenantId): void
    {
        $quoted = SchemaName::quote($schema, $tenantId);
        DB::statement("CREATE TABLE $quoted.migration_probe (id bigint primary key)");
        if (config('testing.fail_tenant_migration')) {
            throw new RuntimeException('Injected migration failure.');
        }
    }
};
