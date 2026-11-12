<?php

namespace App\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

class DatabaseRegistry
{
    public function migrations(): array
    {
        $files = glob(database_path('tenant-database-migrations/*.php'));
        sort($files);

        return collect($files)->mapWithKeys(fn ($f) => [basename($f, '.php') => $f])->all();
    }

    public function connect(string $name): string
    {
        $alias = 'tenant_'.bin2hex(random_bytes(12));
        $config = config('database.connections.pgsql');
        $config['url'] = null;
        $config['database'] = $name;
        $config['options'] = [\PDO::ATTR_PERSISTENT => false];
        config(['database.connections.'.$alias => $config]);

        return $alias;
    }

    public function close(string $alias): void
    {
        $connection = DB::connection($alias);
        try {
            if ($connection->transactionLevel()) {
                $connection->rollBack(0);
            }
        } finally {
            $connection->setReconnector(fn () => throw new \LogicException('Closed tenant connection.'));
            DB::purge($alias);
            $connections = config('database.connections');
            unset($connections[$alias]);
            config(['database.connections' => $connections]);
        }
    }

    public function assertComplete(Connection $db, int $id, string $marker): void
    {
        $meta = $db->table('public.tenant_metadata')->first();
        if (! $meta || (int) $meta->tenant_id !== $id || $meta->marker !== $marker) {
            throw new \LogicException('Tenant database identity mismatch.');
        }
        foreach (['records' => ['id', 'tenant_id', 'title', 'content', 'created_at', 'updated_at'], 'audit_logs' => ['id', 'tenant_id', 'actor_id', 'actor_type', 'user_id', 'staff_id', 'action', 'resource_type', 'resource_id', 'created_at'], 'migrations' => ['migration', 'batch']] as $table => $columns) {
            if (! $db->getSchemaBuilder()->hasColumns('public.'.$table, $columns)) {
                throw new \LogicException('Tenant database incomplete.');
            }
        }
        if (array_diff(array_keys($this->migrations()), $db->table('migrations')->pluck('migration')->all())) {
            throw new \LogicException('Tenant database has pending migrations.');
        }
    }

    public function ready(int $id): string
    {
        $entry = DB::table('public.tenant_databases')->where('tenant_id', $id)->first();
        if (! $entry || $entry->status !== 'ready') {
            throw new \LogicException('Tenant database is not provisioned.');
        }
        $name = DatabaseName::validate($entry->database_name, $id);
        $alias = $this->connect($name);
        try {
            $db = DB::connection($alias);
            if ($db->selectOne('select current_database() as name')->name !== $name) {
                throw new \LogicException('Effective tenant database mismatch.');
            }
            $this->assertComplete($db, $id, $entry->marker);

            return $alias;
        } catch (\Throwable $e) {
            $this->close($alias);
            throw $e;
        }
    }
}
