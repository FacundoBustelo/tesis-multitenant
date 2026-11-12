<?php

namespace App\Models;

class PersonalAccessToken extends \Laravel\Sanctum\PersonalAccessToken
{
    public function getConnectionName()
    {
        return config('database.default');
    }

    protected $table = 'public.personal_access_tokens';
}
