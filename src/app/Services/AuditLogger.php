<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\AuthAuditLog;
use App\Models\Staff;
use App\Models\User;
use App\Tenancy\TenancyStrategy;
use App\Tenancy\TenantContext;

class AuditLogger
{
    public function write(User|Staff $actor, string $action, string $resourceType, ?int $resourceId = null): void
    {
        $context = app(TenantContext::class);
        $context->assertTenant((int) $actor->tenant_id);
        if (! $actor->newQuery()->whereKey($actor->id)->where('tenant_id', $context->id())->exists()) {
            throw new \LogicException('Invalid audit actor.');
        }
        $type = $actor instanceof Staff ? 'staff' : 'user';
        $model = TenancyStrategy::configured() === TenancyStrategy::SeparateDatabases && str_starts_with($action, 'auth.') ? AuthAuditLog::class : AuditLog::class;
        $model::create(['actor_type' => $type, 'actor_id' => $actor->id, 'user_id' => $type === 'user' ? $actor->id : null, 'staff_id' => $type === 'staff' ? $actor->id : null, 'action' => $action, 'resource_type' => $resourceType, 'resource_id' => $resourceId, 'created_at' => now()]);
    }
}
