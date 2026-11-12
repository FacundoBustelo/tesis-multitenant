<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

return new class
{
    public function up(Connection $db, int $tenantId): void
    {
        $schema = 'public';
        $quoted = '"public"';
        $db->getSchemaBuilder()->create($schema.'.records', function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title', 200);
            $table->text('content')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
        });
        $db->getSchemaBuilder()->create($schema.'.audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
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
        });
        $db->statement("ALTER TABLE $quoted.records ADD CONSTRAINT records_tenant_matches CHECK (tenant_id = $tenantId)");
        $db->statement("ALTER TABLE $quoted.audit_logs ADD CONSTRAINT audit_tenant_matches CHECK (tenant_id = $tenantId)");
        $db->statement("ALTER TABLE $quoted.audit_logs ADD CONSTRAINT audit_actor_consistent CHECK ((actor_type = 'user' AND user_id = actor_id AND user_id IS NOT NULL AND staff_id IS NULL) OR (actor_type = 'staff' AND staff_id = actor_id AND staff_id IS NOT NULL AND user_id IS NULL))");
    }
};
