<?php

namespace App\Tenancy;

final class TenantContext
{
    private ?int $id = null;

    private ?string $connection = null;

    public function connection(): ?string
    {
        $this->id();

        return $this->connection;
    }

    private ?string $schema = null;

    public function set(int $id): void
    {
        $this->clear();
        SchemaName::forTenant($id);
        $schema = TenancyStrategy::configured() === TenancyStrategy::SeparateSchemas
            ? app(SchemaRegistry::class)->readySchema($id) : 'public';
        if (TenancyStrategy::configured() === TenancyStrategy::SeparateDatabases) {
            $this->connection = app(DatabaseRegistry::class)->ready($id);
        }
        $this->id = $id;
        $this->schema = $schema;
    }

    public function clear(): void
    {
        $alias = $this->connection;
        $this->connection = null;
        $this->id = null;
        if ($alias) {
            app(DatabaseRegistry::class)->close($alias);
        }
        $this->schema = null;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('Tenant context required.');
    }

    public function schema(): string
    {
        $this->id();

        return $this->schema;
    }

    public function assertTenant(int $id): void
    {
        if ($this->id() !== $id) {
            throw new \LogicException('Tenant-bound model or query cannot be reused across tenants.');
        }
    }
}
