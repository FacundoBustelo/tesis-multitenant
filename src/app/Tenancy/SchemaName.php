<?php

namespace App\Tenancy;

final class SchemaName
{
    public static function forTenant(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid tenant ID.');
        }

        return 'tenant_'.$id;
    }

    public static function validate(string $name, int $id): string
    {
        if (! preg_match('/\Atenant_[1-9][0-9]*\z/D', $name) || $name !== self::forTenant($id)) {
            throw new \InvalidArgumentException('Invalid tenant schema identifier.');
        }

        return $name;
    }

    public static function quote(string $name, int $id): string
    {
        return '"'.self::validate($name, $id).'"';
    }
}
