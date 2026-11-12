<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchemaRegistry
{
    /** @return array<string, string> */
    public function migrations(): array
    {
        $files = glob(database_path('tenant-migrations/*.php'));
        sort($files);

        return collect($files)->mapWithKeys(fn (string $file) => [basename($file, '.php') => $file])->all();
    }

    public function readySchema(int $id): string
    {
        $entry = DB::table('public.tenant_schemas')->where('tenant_id', $id)->first();
        if (! $entry || $entry->status !== 'ready') {
            throw new \LogicException('Tenant schema is not provisioned.');
        }
        $schema = SchemaName::validate($entry->schema_name, $id);
        $this->assertComplete($schema);

        return $schema;
    }

    public function assertComplete(string $schema): void
    {
        foreach ([
            'records' => ['id', 'tenant_id', 'title', 'content', 'created_at', 'updated_at'],
            'audit_logs' => ['id', 'tenant_id', 'actor_id', 'actor_type', 'user_id', 'staff_id', 'action', 'resource_type', 'resource_id', 'created_at'],
            'migrations' => ['id', 'migration', 'batch'],
        ] as $table => $columns) {
            if (! Schema::hasColumns($schema.'.'.$table, $columns)) {
                throw new \LogicException('Tenant schema is incomplete.');
            }
        }
        $applied = DB::table($schema.'.migrations')->pluck('migration')->all();
        if (array_diff(array_keys($this->migrations()), $applied)) {
            throw new \LogicException('Tenant schema has pending migrations.');
        }
    }
}
