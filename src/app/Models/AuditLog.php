<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use BelongsToTenant;

    protected string $tenantTable = 'audit_logs';

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit logs are append only.'));
        static::deleting(fn () => throw new \LogicException('Audit logs are append only.'));
    }
}
