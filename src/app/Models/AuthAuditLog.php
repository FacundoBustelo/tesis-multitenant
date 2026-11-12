<?php

namespace App\Models;

class AuthAuditLog extends AuditLog
{
    public function getConnectionName()
    {
        $this->tenantBinding();

        return config('database.default');
    }

    public function getTable(): string
    {
        $this->tenantBinding();

        return 'public.audit_logs';
    }
}
