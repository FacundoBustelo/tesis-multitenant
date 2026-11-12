<?php

namespace App\Tenancy;

enum TenancyStrategy: string
{
    case SeparateDatabases = 'separate_databases';

    case SharedTables = 'shared_tables';
    case SeparateSchemas = 'separate_schemas';

    public static function configured(): self
    {
        return self::from(config('tenancy.strategy'));
    }
}
