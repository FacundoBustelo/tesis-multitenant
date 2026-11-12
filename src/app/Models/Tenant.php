<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    public function getConnectionName()
    {
        return config('database.default');
    }

    protected $table = 'public.tenants';

    protected $fillable = ['slug', 'name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }
}
