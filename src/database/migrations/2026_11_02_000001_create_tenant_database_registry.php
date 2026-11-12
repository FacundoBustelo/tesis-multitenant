<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_databases', function (Blueprint $t) {
            $t->foreignId('tenant_id')->primary()->constrained('tenants')->restrictOnDelete();
            $t->string('database_name', 63)->unique();
            $t->string('marker', 32);
            $t->string('status');
            $t->timestamps();
        });
        DB::statement('CREATE SEQUENCE public.database_records_id_seq INCREMENT 1 CACHE 1 OWNED BY public.tenant_databases.tenant_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_databases');
    }
};
