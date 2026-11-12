<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

class TenancyMigrate extends Command
{
    protected $signature = 'tenancy:migrate {--tenant= : Existing tenant slug} {--all : Migrate every registered tenant}';

    protected $description = 'Apply pending tenant migrations with an independent history per schema';

    public function handle(TenantProvisioner $provisioner): int
    {
        if ((bool) $this->option('tenant') === (bool) $this->option('all')) {
            $this->error('Specify exactly one of --tenant=slug or --all.');

            return self::FAILURE;
        }
        $tenants = $this->option('all')
            ? Tenant::whereIn('id', fn ($query) => $query->select('tenant_id')->from(config('tenancy.strategy') === 'separate_databases' ? 'public.tenant_databases' : 'public.tenant_schemas'))->get()
            : Tenant::where('slug', $this->option('tenant'))->get();
        if (! $this->option('all') && $tenants->isEmpty()) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        foreach ($tenants as $tenant) {
            $provisioner->provision($tenant, create: false);
            $this->info('Schema migrated for '.$tenant->slug.'.');
        }

        return self::SUCCESS;
    }
}
