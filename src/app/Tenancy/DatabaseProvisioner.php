<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class DatabaseProvisioner
{
    public function __construct(private DatabaseRegistry $registry) {}

    public function provision(Tenant $tenant, bool $create = true): void
    {
        $central = DB::connection();
        $id = (int) $tenant->id;
        $name = DatabaseName::forTenant($id);
        if ($central->transactionLevel()) {
            throw new \LogicException('Database provisioning requires no central transaction.');
        }
        $central->select('select pg_advisory_lock(hashtextextended(?,0))', ['database:'.$id]);
        $alias = null;
        try {
            $entry = $central->table('tenant_databases')->where('tenant_id', $id)->first();
            if (! $entry) {
                if (! $create) {
                    throw new \LogicException('Tenant must be provisioned before migration.');
                }
                if ($central->selectOne('select 1 from pg_database where datname=?', [$name])) {
                    throw new \LogicException('Refusing to adopt unregistered database.');
                }
                $central->table('tenant_databases')->insert(['tenant_id' => $id, 'database_name' => $name, 'marker' => bin2hex(random_bytes(16)), 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
                $entry = $central->table('tenant_databases')->where('tenant_id', $id)->first();
                $central->statement('CREATE DATABASE "'.$name.'" TEMPLATE template0');
                $alias = $this->registry->connect($name);
                $db = DB::connection($alias);
                $db->transaction(function () use ($db, $id, $entry) {
                    $db->statement('CREATE TABLE public.tenant_metadata (tenant_id bigint PRIMARY KEY, marker varchar(32) NOT NULL)');
                    $db->table('tenant_metadata')->insert(['tenant_id' => $id, 'marker' => $entry->marker]);
                    $db->statement('CREATE TABLE public.migrations (id bigserial PRIMARY KEY, migration varchar(255) UNIQUE NOT NULL, batch integer NOT NULL)');
                });
            } else {
                DatabaseName::validate($entry->database_name, $id);
                $alias = $this->registry->connect($name);
                $db = DB::connection($alias);
                $meta = $db->table('tenant_metadata')->first();
                if (! $meta || (int) $meta->tenant_id !== $id || $meta->marker !== $entry->marker) {
                    throw new \LogicException('Database requires administrative reconciliation.');
                }
            }
            $central->table('tenant_databases')->where('tenant_id', $id)->update(['status' => 'migrating', 'updated_at' => now()]);
            $db->transaction(function () use ($db, $id, $entry) {
                $applied = $db->table('migrations')->pluck('migration')->all();
                $batch = (int) $db->table('migrations')->max('batch') + 1;
                foreach ($this->registry->migrations() as $migration => $file) {
                    if (! in_array($migration, $applied, true)) {
                        (require $file)->up($db, $id);
                        $db->table('migrations')->insert(['migration' => $migration, 'batch' => $batch]);
                    }
                }
                $this->registry->assertComplete($db, $id, $entry->marker);
            });
            $central->table('tenant_databases')->where('tenant_id', $id)->update(['status' => 'ready', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            $central->table('tenant_databases')->where('tenant_id', $id)->update(['status' => 'failed', 'updated_at' => now()]);
            throw $e;
        } finally {
            try {
                if ($alias) {
                    $this->registry->close($alias);
                }
            } finally {
                $central->select('select pg_advisory_unlock(hashtextextended(?,0))', ['database:'.$id]);
            }
        }
    }
}
