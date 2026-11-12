<?php

namespace Tests\Support;

use App\Tenancy\DatabaseName;
use App\Tenancy\DatabaseRegistry;
use Illuminate\Support\Facades\DB;

final class TenantDatabaseCleanup
{
    public static function clean(): void
    {
        $central = DB::connection();
        TestDatabaseGuard::assertSafe($central, app()->environment());
        $role = $central->selectOne('select current_user as name')->name;
        if ($role !== 'tesis_test_runner') {
            throw new \RuntimeException('Dedicated test role required.');
        }
        if (! $central->getSchemaBuilder()->hasTable('tenant_databases')) {
            return;
        }
        $entries = $central->table('tenant_databases')->get();
        // Validate the entire set before any destructive statement.
        foreach ($entries as $entry) {
            DatabaseName::validate($entry->database_name, (int) $entry->tenant_id);
            $owner = $central->selectOne('select pg_get_userbyid(datdba) as owner from pg_database where datname=?', [$entry->database_name]);
            if (! $owner || $owner->owner !== $role) {
                throw new \RuntimeException('Unsafe database owner.');
            }
            $registry = new DatabaseRegistry;
            $alias = $registry->connect($entry->database_name);
            try {
                $db = DB::connection($alias);
                $meta = $db->table('tenant_metadata')->first();
                if ($db->selectOne('select current_database() as name')->name !== $entry->database_name || ! $meta || (int) $meta->tenant_id !== (int) $entry->tenant_id || $meta->marker !== $entry->marker) {
                    throw new \RuntimeException('Unsafe test database marker.');
                }
            } finally {
                $registry->close($alias);
            }
        }
        foreach ($entries as $entry) {
            $central->statement('DROP DATABASE "'.$entry->database_name.'"');
        }
        $central->table('tenant_databases')->delete();
    }
}
