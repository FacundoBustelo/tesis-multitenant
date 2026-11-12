<?php

use App\Tenancy\SchemaName;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class
{
    public function up(string $schema, int $tenantId): void
    {
        $quoted = SchemaName::quote($schema, $tenantId);
        Schema::create($schema.'.records', function (Blueprint $table): void {
            $table->bigInteger('id')->primary()->default(DB::raw("nextval('public.tenant_records_id_seq'::regclass)"));
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('public.tenants')->restrictOnDelete();
            $table->string('title', 200);
            $table->text('content')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create($schema.'.audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('public.tenants')->restrictOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_type', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('action', 40);
            $table->string('resource_type', 40);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'resource_type', 'resource_id']);
            $table->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('public.users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('public.staff')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE $quoted.records ADD CONSTRAINT records_tenant_matches CHECK (tenant_id = $tenantId)");
        DB::statement("ALTER TABLE $quoted.audit_logs ADD CONSTRAINT audit_tenant_matches CHECK (tenant_id = $tenantId)");
        DB::statement("ALTER TABLE $quoted.audit_logs ADD CONSTRAINT audit_actor_consistent CHECK ((actor_type = 'user' AND user_id = actor_id AND user_id IS NOT NULL AND staff_id IS NULL) OR (actor_type = 'staff' AND staff_id = actor_id AND staff_id IS NOT NULL AND user_id IS NULL))");
    }
};
