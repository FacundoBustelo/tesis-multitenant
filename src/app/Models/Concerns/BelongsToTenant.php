<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantQueryBuilder;
use App\Tenancy\TenantTableResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    private ?int $boundTenantId = null;

    private ?string $boundConnection = null;

    public function getConnectionName()
    {
        $this->tenantBinding();

        return $this->boundConnection;
    }

    protected function tenantBinding(): int
    {
        $context = app(TenantContext::class);
        $this->boundTenantId ??= $context->id();
        $context->assertTenant($this->boundTenantId);
        $this->boundConnection ??= $context->connection();
        if ($this->boundConnection !== $context->connection()) {
            throw new \LogicException('Tenant-bound model cannot be reused across tenants or contexts.');
        }

        return $this->boundTenantId;
    }

    public function getTable(): string
    {
        $this->tenantBinding();

        return app(TenantTableResolver::class)->table($this->tenantTable);
    }

    protected function newBaseQueryBuilder(): TenantQueryBuilder
    {
        return new TenantQueryBuilder($this->getConnection(), $this->tenantBinding());
    }

    public function newInstance($attributes = [], $exists = false)
    {
        $model = parent::newInstance($attributes, $exists);
        $model->boundTenantId = $this->tenantBinding();
        $model->boundConnection = $this->boundConnection;

        return $model;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
        });
        static::saving(function ($model): void {
            $model->tenantBinding();
            $id = app(TenantContext::class)->id();
            if ($model->exists && (int) $model->getOriginal('tenant_id') !== $id) {
                throw new \LogicException('Foreign tenant write.');
            }
            if ($model->tenant_id !== null && (int) $model->tenant_id !== $id) {
                throw new \LogicException('Tenant reassignment denied.');
            }
            $model->tenant_id = $id;
        });
        static::deleting(function ($model): void {
            $model->tenantBinding();
            if ((int) $model->tenant_id !== app(TenantContext::class)->id()) {
                throw new \LogicException('Foreign tenant delete.');
            }
        });
    }
}
