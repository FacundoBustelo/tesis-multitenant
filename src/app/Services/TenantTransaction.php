<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class TenantTransaction
{
    public static function run(callable $callback): mixed
    {
        return DB::connection(app(TenantContext::class)->connection())->transaction($callback);
    }
}
