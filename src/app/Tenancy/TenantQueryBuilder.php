<?php

namespace App\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

class TenantQueryBuilder extends Builder
{
    private ?string $contextConnection;

    public function __construct(Connection $connection, private int $tenantId)
    {
        $this->contextConnection = app(TenantContext::class)->connection();
        parent::__construct($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }

    public function applyBeforeQueryCallbacks(): void
    {
        app(TenantContext::class)->assertTenant($this->tenantId);
        if (TenancyStrategy::configured() === TenancyStrategy::SeparateDatabases && $this->contextConnection !== app(TenantContext::class)->connection()) {
            throw new \LogicException('Tenant query cannot be reused across tenants or contexts.');
        }
        parent::applyBeforeQueryCallbacks();
    }

    public function newQuery(): static
    {
        $this->applyBeforeQueryCallbacks();

        return new static($this->connection, $this->tenantId);
    }
}
