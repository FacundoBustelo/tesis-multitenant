<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenancyInstall extends Command
{
    protected $signature = 'tenancy:install {--force : Run central migrations in production}';

    protected $description = 'Install central tables, schema registry and global sequence';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->error('Tenancy requires PostgreSQL.');

            return self::FAILURE;
        }

        return $this->call('migrate', ['--force' => $this->option('force')]);
    }
}
