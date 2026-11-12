<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Tenancy requires PostgreSQL.');
        }
        Schema::create('public.tenant_schemas', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('public.tenants')->restrictOnDelete();
            $table->string('schema_name', 63)->unique();
            $table->string('status', 20);
            $table->timestamps();
        });
        DB::statement('CREATE SEQUENCE public.tenant_records_id_seq AS bigint INCREMENT BY 1 CACHE 1 OWNED BY public.tenant_schemas.tenant_id');
    }

    public function down(): void
    {
        Schema::dropIfExists('public.tenant_schemas');
        DB::statement('DROP SEQUENCE IF EXISTS public.tenant_records_id_seq');
    }
};
