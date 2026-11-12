<?php

namespace App\Tenancy;

final class DatabaseName
{
    public static function forTenant(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid tenant database ID.');
        }
        $prefix = app()->environment('testing') ? 'tesis_test_'.config('tenancy.test_run').'_tenant_' : 'tesis_tenant_';
        $name = $prefix.$id;
        if (! preg_match('/^tesis_(?:test_[a-f0-9]{12}_)?tenant_[1-9][0-9]*$/D', $name) || strlen($name) > 63) {
            throw new \InvalidArgumentException('Invalid tenant database name.');
        }

        return $name;
    }

    public static function validate(string $name, int $id): string
    {
        if ($name !== self::forTenant($id)) {
            throw new \InvalidArgumentException('Invalid tenant database name.');
        }

        return $name;
    }
}
