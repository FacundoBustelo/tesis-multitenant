<?php

namespace Tests\Unit;

use App\Tenancy\SchemaName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SchemaNameTest extends TestCase
{
    public static function invalidNames(): array
    {
        return array_map(fn (string $name) => [$name], ['public', 'tenant_2', 'tenant_01', 'tenant_0', 'tenant_-1', 'tenant_1.extra', 'tenant_1"', "tenant_1\n", 'tenant_1; DROP SCHEMA public']);
    }

    #[DataProvider('invalidNames')]
    public function test_only_the_canonical_identifier_for_the_tenant_is_accepted(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SchemaName::quote($name, 1);
    }

    public function test_internal_identifiers_are_quoted_and_invalid_ids_rejected(): void
    {
        $this->assertSame('"tenant_42"', SchemaName::quote(SchemaName::forTenant(42), 42));
        $this->expectException(\InvalidArgumentException::class);
        SchemaName::forTenant(0);
    }
}
