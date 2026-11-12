<?php

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public static function unsafeConnections(): array
    {
        return [
            'development' => ['local', 'pgsql', 'tesis_test', 'tesis_test', false],
            'wrong driver' => ['testing', 'sqlite', 'tesis_test', 'tesis_test', false],
            'wrong configured database' => ['testing', 'pgsql', 'tesis', 'tesis_test', false],
            'URL overrides database' => ['testing', 'pgsql', 'tesis_test', 'tesis', true],
        ];
    }

    #[DataProvider('unsafeConnections')]
    public function test_cleanup_rejects_unsafe_connections_before_any_ddl(string $environment, string $driver, string $configured, string $effective, bool $connects): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->allows('getDriverName')->andReturn($driver);
        $connection->allows('getDatabaseName')->andReturn($configured);
        if ($connects) {
            $connection->shouldReceive('selectOne')->once()->with('select current_database() as name', [], false)->andReturn((object) ['name' => $effective]);
        } else {
            $connection->shouldNotReceive('selectOne');
        }
        $connection->shouldNotReceive('statement');
        $connection->shouldNotReceive('getSchemaBuilder');
        try {
            TestDatabaseGuard::cleanRegisteredSchemas($connection, $environment);
            $this->fail('Unsafe cleanup was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('tesis_test', $exception->getMessage());
        } finally {
            Mockery::close();
        }
    }
}
