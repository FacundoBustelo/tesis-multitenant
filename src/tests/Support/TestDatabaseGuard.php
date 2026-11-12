<?php

namespace Tests\Support;

use App\Tenancy\SchemaName;
use Illuminate\Database\Connection;

final class TestDatabaseGuard
{
    public static function assertSafe(Connection $connection, string $environment): void
    {
        if ($environment !== 'testing' || $connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'tesis_test') {
            throw new \RuntimeException('Tests require testing environment and PostgreSQL tesis_test.');
        }
        if ($connection->selectOne('select current_database() as name', [], false)->name !== 'tesis_test') {
            throw new \RuntimeException('Effective test database must be exactly tesis_test.');
        }
    }

    public static function cleanRegisteredSchemas(Connection $connection, string $environment): void
    {
        self::assertSafe($connection, $environment);
        $hasRegistry = $connection->getSchemaBuilder()->hasTable('public.tenant_schemas');
        $entries = $hasRegistry ? $connection->table('public.tenant_schemas')->get() : collect();
        foreach ($entries as $entry) {
            SchemaName::validate($entry->schema_name, (int) $entry->tenant_id);
        }
        $connection->transaction(function () use ($connection, $entries, $hasRegistry): void {
            foreach ($entries as $entry) {
                $quoted = SchemaName::quote($entry->schema_name, (int) $entry->tenant_id);
                $connection->statement("DROP SCHEMA IF EXISTS $quoted CASCADE");
            }
            if ($hasRegistry) {
                $connection->table('public.tenant_schemas')->delete();
            }
            $connection->statement('DROP SEQUENCE IF EXISTS public.tenant_records_id_seq');
        });
    }
}
