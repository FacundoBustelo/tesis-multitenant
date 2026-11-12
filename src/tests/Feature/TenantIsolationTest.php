<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AuthAuditLog;
use App\Models\Record;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\SchemaName;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshTenantDatabase;

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        Auth::forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function token(string $type = 'staff', string $tenant = 'alpha'): string
    {
        return $this->postJson("/api/v1/auth/$type/login", ['tenant' => $tenant, 'email' => $type === 'staff' ? 'staff@example.test' : 'user@example.test', 'password' => 'Poc-password-2026!'])->assertOk()->json('token');
    }

    private function table(string $table, string $slug = 'alpha'): string
    {
        $id = Tenant::where('slug', $slug)->value('id');

        return (config('tenancy.strategy') === 'separate_schemas' ? SchemaName::forTenant($id) : 'public').'.'.$table;
    }

    private function id(string $tenant): int
    {
        return $this->tenantTable('records', $tenant)->where('tenant_id', Tenant::where('slug', $tenant)->value('id'))->value('id');
    }

    public function test_staff_crud_and_audit(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $this->getJson('/api/v1/records', $headers)->assertOk()->assertJsonCount(5, 'data');
        $id = $this->postJson('/api/v1/records', ['title' => 'New', 'content' => 'Body'], $headers)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/records/$id", $headers)->assertOk()->assertJsonPath('data.title', 'New');
        $this->patchJson("/api/v1/records/$id", ['title' => 'Updated'], $headers)->assertOk();
        $this->deleteJson("/api/v1/records/$id", [], $headers)->assertNoContent();
        foreach (['record.create', 'record.read', 'record.update', 'record.delete'] as $action) {
            $this->assertDatabaseHas($this->table('audit_logs'), ['action' => $action, 'resource_id' => $id, 'actor_type' => 'staff', 'tenant_id' => Tenant::where('slug', 'alpha')->value('id')], $this->tenantConnection('alpha'));
        }
        $this->assertDatabaseMissing($this->table('audit_logs'), ['action' => 'record.create', 'resource_id' => $id, 'actor_type' => 'user'], $this->tenantConnection('alpha'));
    }

    public function test_all_three_tenants_and_both_actor_types_are_isolated(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $tenant) {
            foreach (['users', 'staff'] as $type) {
                $headers = ['Authorization' => 'Bearer '.$this->token($type, $tenant), 'X-Tenant-ID' => '999'];
                $tenantId = Tenant::where('slug', $tenant)->value('id');
                $response = $this->getJson('/api/v1/records?tenant_id=999', $headers)->assertOk()->assertJsonCount(5, 'data');
                foreach ($response->json('data') as $record) {
                    $this->assertSame($tenantId, $record['tenant_id']);
                }
                $this->getJson('/api/v1/records/'.$this->id($tenant), $headers)->assertOk();
                foreach (array_diff(['alpha', 'beta', 'gamma'], [$tenant]) as $other) {
                    $id = $this->id($other);
                    $this->getJson("/api/v1/records/$id", $headers)->assertNotFound();
                    $this->patchJson("/api/v1/records/$id", ['title' => 'Hacked'], $headers)->assertNotFound();
                    $this->deleteJson("/api/v1/records/$id", [], $headers)->assertNotFound();
                    $this->assertDatabaseHas($this->table('records', $other), ['id' => $id, 'title' => 'Record 1'], $this->tenantConnection($other));
                }
            }
        }
    }

    public function test_users_are_read_only_and_cannot_impersonate_staff(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token('users')];
        $id = $this->id('alpha');
        $this->postJson('/api/v1/records', ['title' => 'Denied'], $headers)->assertForbidden();
        $this->patchJson("/api/v1/records/$id", ['title' => 'Denied'], $headers)->assertForbidden();
        $this->deleteJson("/api/v1/records/$id", [], $headers)->assertForbidden();
        $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJsonPath('actor_type', 'user');
        $this->postJson('/api/v1/auth/staff/login', ['tenant' => 'alpha', 'email' => 'user@example.test', 'password' => 'Poc-password-2026!'])->assertUnauthorized();
    }

    public function test_tenant_payload_injection_and_validation(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $id = $this->id('alpha');
        $this->postJson('/api/v1/records', ['title' => 'Injected', 'tenant_id' => 2], $headers)->assertUnprocessable();
        $this->patchJson("/api/v1/records/$id", ['tenant_id' => 2], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/records', [], $headers)->assertUnprocessable();
        $this->getJson('/api/v1/records?per_page=1000', $headers)->assertUnprocessable();
    }

    public function test_authentication_expiration_and_revocation(): void
    {
        $this->getJson('/api/v1/records')->assertUnauthorized();
        $this->getJson('/api/v1/records', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
        foreach (['missing', 'alpha'] as $tenant) {
            $this->postJson('/api/v1/auth/users/login', ['tenant' => $tenant, 'email' => 'user@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();
        $this->getJson('/api/v1/records', $headers)->assertUnauthorized();
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/records', $headers)->assertUnauthorized();
    }

    public function test_credentials_are_verified_within_selected_tenant(): void
    {
        $actor = User::where('tenant_id', Tenant::where('slug', 'beta')->value('id'))->first();
        $actor->password = 'Different-password!';
        $actor->save();
        $this->postJson('/api/v1/auth/users/login', ['tenant' => 'beta', 'email' => 'user@example.test', 'password' => 'Poc-password-2026!'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/users/login', ['tenant' => 'beta', 'email' => 'user@example.test', 'password' => 'Different-password!'])->assertOk();
    }

    public function test_scope_fails_closed_and_context_is_cleared(): void
    {
        $this->getJson('/api/v1/records', ['Authorization' => 'Bearer '.$this->token()])->assertOk();
        $this->expectException(\LogicException::class);
        Record::query()->count();
    }

    public function test_model_rejects_reassignment_and_policy_rejects_foreign_model(): void
    {
        $context = app(TenantContext::class);
        $context->set(Tenant::where('slug', 'alpha')->value('id'));
        $record = Record::first();
        $staff = Staff::where('tenant_id', Tenant::where('slug', 'beta')->value('id'))->first();
        $this->assertFalse(Gate::forUser($staff)->allows('update', $record));
        $record->tenant_id = $staff->tenant_id;
        $this->expectException(\LogicException::class);
        $record->save();
    }

    public function test_audit_actor_foreign_key_rejects_cross_tenant_reference(): void
    {
        $alpha = Tenant::where('slug', 'alpha')->value('id');
        $beta = Tenant::where('slug', 'beta')->value('id');
        $actor = Staff::where('tenant_id', $beta)->first();
        if (config('tenancy.strategy') === 'separate_databases') {
            app(TenantContext::class)->set($alpha);
            $this->expectException(\LogicException::class);
            app(AuditLogger::class)->write($actor, 'record.create', 'record');

            return;
        }
        $this->expectException(QueryException::class);
        $this->tenantTable('audit_logs')->insert(['tenant_id' => $alpha, 'actor_id' => $actor->id, 'actor_type' => 'staff', 'staff_id' => $actor->id, 'action' => 'test', 'resource_type' => 'record']);
    }

    public function test_audit_failure_rolls_back_mutation(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $this->mock(AuditLogger::class)->shouldReceive('write')->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson('/api/v1/records', ['title' => 'Must roll back'], $headers)->assertStatus(500);
        $this->assertDatabaseMissing($this->table('records'), ['title' => 'Must roll back'], $this->tenantConnection('alpha'));
    }

    public function test_seeder_is_repeatable(): void
    {
        $this->seed();
        $this->assertDatabaseCount('tenants', 3);
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('staff', 3);
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $this->assertSame(5, $this->tenantTable('records', $slug)->where('tenant_id', Tenant::where('slug', $slug)->value('id'))->count());
        }
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/users/login', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/users/login', [])->assertStatus(429);
    }

    public function test_database_rejects_missing_tenant(): void
    {
        $this->expectException(QueryException::class);
        $this->tenantTable('records')->insert(['title' => 'No tenant']);
    }

    public function test_database_rejects_duplicate_email_within_tenant(): void
    {
        $actor = User::first();
        $this->expectException(QueryException::class);
        DB::table('users')->insert(['tenant_id' => $actor->tenant_id, 'name' => 'Duplicate', 'email' => $actor->email, 'password' => 'irrelevant']);
    }

    public function test_audit_model_rejects_modification(): void
    {
        $this->token();
        app(TenantContext::class)->set(Tenant::where('slug', 'alpha')->value('id'));
        $log = config('tenancy.strategy') === 'separate_databases' ? AuthAuditLog::first() : AuditLog::first();
        $log->action = 'tampered';
        $this->expectException(\LogicException::class);
        $log->save();
    }

    public function test_direct_sql_bypasses_application_scope_documented_limit(): void
    {
        if (config('tenancy.strategy') === 'shared_tables') {
            $this->assertSame(15, DB::table('public.records')->count());
        } else {
            $count = 0;
            foreach (['alpha', 'beta', 'gamma'] as $slug) {
                $count += $this->tenantTable('records', $slug)->count();
            }
            $this->assertSame(15, $count);
        }
    }

    public function test_audit_omits_payload_and_secrets(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->token()];
        $this->postJson('/api/v1/records', ['title' => 'Private title', 'content' => 'Private content'], $headers)->assertCreated();
        $serialized = json_encode($this->tenantTable('audit_logs')->get());
        foreach (['Poc-password', 'Private title', 'Private content', $headers['Authorization']] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }
}
