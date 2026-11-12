<?php

namespace Database\Seeders;

use App\Models\Record;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenancyStrategy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seed = function (): void {
            foreach (['alpha', 'beta', 'gamma'] as $slug) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
                if (TenancyStrategy::configured() !== TenancyStrategy::SharedTables) {
                    app(TenantProvisioner::class)->provision($tenant);
                }
                foreach ([User::class, Staff::class] as $class) {
                    $email = $class === User::class ? 'user@example.test' : 'staff@example.test';
                    if (! $class::where('tenant_id', $tenant->id)->where('email', $email)->exists()) {
                        $actor = new $class(['name' => ucfirst($slug).' actor', 'email' => $email, 'password' => 'Poc-password-2026!']);
                        $actor->tenant_id = $tenant->id;
                        $actor->save();
                    }
                }
                $context = app(TenantContext::class);
                $context->set($tenant->id);
                try {
                    for ($i = 1; $i <= 5; $i++) {
                        Record::firstOrCreate(['title' => "Record $i"], ['content' => "Fixture $slug / $i"]);
                    }
                } finally {
                    $context->clear();
                }
            }
        };
        if (TenancyStrategy::configured() === TenancyStrategy::SeparateDatabases) {
            $seed();
        } else {
            DB::transaction($seed);
        }
    }
}
