<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\TenantProvisioner;
use Illuminate\Console\Command;

class TenancyProvision extends Command
{
    protected $signature = 'tenancy:provision {--tenant= : Existing tenant slug}';

    protected $description = 'Provision and migrate the schema for an existing tenant';

    public function handle(TenantProvisioner $provisioner): int
    {
        $tenant = Tenant::where('slug', $this->option('tenant'))->first();
        if (! $this->option('tenant') || ! $tenant) {
            $this->error('Specify an existing tenant with --tenant=slug.');

            return self::FAILURE;
        }
        $provisioner->provision($tenant);
        $this->info('Schema ready for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
