<?php

namespace Tests\Concerns;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantDatabaseCleanup;
use Tests\Support\TestDatabaseGuard;

trait RefreshTenantDatabase
{
    use RefreshDatabase { refreshDatabase as refreshOriginalDatabase; }

    public function refreshDatabase(): void
    {
        if (config('tenancy.strategy') !== 'separate_databases') {
            $this->refreshOriginalDatabase();

            return;
        }
        self::$databaseRun ??= bin2hex(random_bytes(6));
        config(['tenancy.test_run' => self::$databaseRun]);
        TenantDatabaseCleanup::clean();
        TestDatabaseGuard::cleanRegisteredSchemas(DB::connection(), app()->environment());
        $this->artisan('migrate:fresh')->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            app(TenantContext::class)->clear();
            $this->closeInspectionConnections();
            TenantDatabaseCleanup::clean();
        });
    }

    protected function beforeRefreshingDatabase(): void
    {
        parent::beforeRefreshingDatabase();
    }
}
