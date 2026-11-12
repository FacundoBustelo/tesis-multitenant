<?php

namespace App\Providers;

use App\Contracts\RecordRepository;
use App\Models\PersonalAccessToken;
use App\Models\Record;
use App\Models\Staff;
use App\Models\User;
use App\Policies\RecordPolicy;
use App\Repositories\SeparateDatabaseRecordRepository;
use App\Repositories\SeparateSchemaRecordRepository;
use App\Repositories\SharedTableRecordRepository;
use App\Tenancy\TenancyStrategy;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(RecordRepository::class, fn ($app) => $app->make(
            match (TenancyStrategy::configured()) {
                TenancyStrategy::SharedTables => SharedTableRecordRepository::class,
                TenancyStrategy::SeparateSchemas => SeparateSchemaRecordRepository::class,
                TenancyStrategy::SeparateDatabases => SeparateDatabaseRecordRepository::class,
            }
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TenancyStrategy::configured();
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Relation::enforceMorphMap(['user' => User::class, 'staff' => Staff::class]);
        Gate::policy(Record::class, RecordPolicy::class);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
