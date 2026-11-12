<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('Existing users require explicit tenant assignment before migration.');
        }
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->dropUnique(['email']);
            $t->unique(['tenant_id', 'email']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->timestamps();
            $t->unique(['tenant_id', 'email']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('title', 200);
            $t->text('content')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('actor_id');
            $t->string('actor_type', 20);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('staff_id')->nullable();
            $t->string('action', 40);
            $t->string('resource_type', 40);
            $t->unsignedBigInteger('resource_id')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['tenant_id', 'created_at']);
            $t->index(['tenant_id', 'resource_type', 'resource_id']);
            $t->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $t->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_actor_consistent CHECK ((actor_type = 'user' AND user_id = actor_id AND user_id IS NOT NULL AND staff_id IS NULL) OR (actor_type = 'staff' AND staff_id = actor_id AND staff_id IS NOT NULL AND user_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('records');
        Schema::dropIfExists('staff');
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['tenant_id', 'email']);
            $t->dropUnique(['tenant_id', 'id']);
            $t->dropConstrainedForeignId('tenant_id');
            $t->unique('email');
        });
        Schema::dropIfExists('tenants');
    }
};
