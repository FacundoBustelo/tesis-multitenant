<?php

namespace App\Tenancy;

final class TenantTableResolver
{
    public function table(string $table): string
    {
        if (! in_array($table, ['records', 'audit_logs'], true)) {
            throw new \InvalidArgumentException('Unknown tenant table.');
        }
        $context = app(TenantContext::class);
        $context->id();

        return $context->schema().'.'.$table;
    }
}
