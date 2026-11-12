<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenancyStrategy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Record extends Model
{
    use BelongsToTenant;

    protected string $tenantTable = 'records';

    protected static function booted(): void
    {
        static::creating(function ($record) {
            if (TenancyStrategy::configured() === TenancyStrategy::SeparateDatabases) {
                $record->id = DB::selectOne("select nextval('public.database_records_id_seq') as id")->id;
            }
        });
    }

    protected $fillable = ['title', 'content'];
}
