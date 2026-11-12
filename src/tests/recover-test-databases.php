<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantDatabaseCleanup;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance('env', 'testing');
config(['database.connections.pgsql.database' => 'tesis_test', 'database.connections.pgsql.username' => 'tesis_test_runner', 'database.connections.pgsql.password' => 'test_only_local', 'database.connections.pgsql.url' => null]);
DB::purge();
$entry = DB::table('tenant_databases')->first();
if ($entry && preg_match('/^tesis_test_([a-f0-9]{12})_tenant_[1-9][0-9]*$/D', $entry->database_name, $m)) {
    config(['tenancy.test_run' => $m[1]]);
    TenantDatabaseCleanup::clean();
}
