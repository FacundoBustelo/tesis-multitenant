<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Record;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class TenantRuntimeTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        Auth::forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }

    private function login(string $slug = 'alpha'): array
    {
        return ['tenant' => $slug, 'email' => 'staff@example.test', 'password' => 'Poc-password-2026!'];
    }

    private function assertContextMissing(): void
    {
        try {
            app(TenantContext::class)->id();
            $this->fail('Context leaked.');
        } catch (\LogicException $exception) {
            $this->assertSame('Tenant context required.', $exception->getMessage());
        }
    }

    public function test_connection_and_search_path_survive_alternation_errors_and_exceptions(): void
    {
        $state = DB::selectOne('select pg_backend_pid() as pid, current_setting(\'search_path\') as path');
        foreach (['alpha', 'beta', 'gamma', 'alpha'] as $slug) {
            $token = $this->postJson('/api/v1/auth/staff/login', $this->login($slug))->assertOk()->json('token');
            $headers = ['Authorization' => 'Bearer '.$token];
            $this->getJson('/api/v1/records', $headers)->assertOk()->assertJsonCount(5, 'data');
            $this->getJson('/api/v1/records/9223372036854775807', $headers)->assertNotFound();
            $this->postJson('/api/v1/records', [], $headers)->assertUnprocessable();
            $this->assertContextMissing();
        }
        $this->mock(AuditLogger::class)->shouldReceive('write')->andThrow(new \RuntimeException('Unavailable'));
        $this->postJson('/api/v1/auth/logout', [], $headers)->assertStatus(500);
        $this->assertContextMissing();
        $this->app->instance(AuditLogger::class, new AuditLogger);
        $this->postJson('/api/v1/auth/staff/login', $this->login('beta'))->assertOk();
        $this->assertContextMissing();
        $this->assertEquals($state, DB::selectOne('select pg_backend_pid() as pid, current_setting(\'search_path\') as path'));
    }

    public function test_bound_models_and_executed_or_unexecuted_queries_cannot_cross_tenants(): void
    {
        $context = app(TenantContext::class);
        $context->set(Tenant::where('slug', 'alpha')->value('id'));
        $model = Record::firstOrFail();
        $lazy = Record::query();
        $executed = Record::query()->toBase();
        $this->assertSame(5, $executed->count());
        $context->set(Tenant::where('slug', 'beta')->value('id'));
        foreach ([fn () => $model->refresh(), fn () => $model->update(['title' => 'Denied']), fn () => $model->delete(), fn () => $lazy->count(), fn () => $executed->count(), fn () => $executed->update(['title' => 'Denied'])] as $operation) {
            try {
                $operation();
                $this->fail('Cross-tenant reuse was accepted.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('cannot be reused', $exception->getMessage());
            }
        }
        $context->clear();
    }

    public function test_context_is_required_for_both_tenant_models(): void
    {
        foreach ([Record::class, AuditLog::class] as $class) {
            try {
                $class::query()->count();
                $this->fail('Missing context was accepted.');
            } catch (\LogicException $exception) {
                $this->assertSame('Tenant context required.', $exception->getMessage());
            }
        }
    }

    public function test_mutations_and_written_audit_events_roll_back_together(): void
    {
        $token = $this->postJson('/api/v1/auth/staff/login', $this->login())->assertOk()->json('token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $context = app(TenantContext::class);
        $id = Tenant::where('slug', 'alpha')->value('id');
        $context->set($id);
        $record = Record::firstOrFail();
        $recordTable = $record->getTable();
        $auditTable = (new AuditLog)->getTable();
        $auditCount = $this->tenantTable('audit_logs')->count();
        $context->clear();
        $logger = new AuditLogger;
        $this->mock(AuditLogger::class)->shouldReceive('write')->andReturnUsing(function (...$arguments) use ($logger): void {
            $logger->write(...$arguments);
            throw new \RuntimeException('Failure after writing audit');
        });
        $this->postJson('/api/v1/records', ['title' => 'Roll back'], $headers)->assertStatus(500);
        $this->patchJson('/api/v1/records/'.$record->id, ['title' => 'Roll back'], $headers)->assertStatus(500);
        $this->deleteJson('/api/v1/records/'.$record->id, [], $headers)->assertStatus(500);
        $this->assertDatabaseCount($recordTable, config('tenancy.strategy') === 'shared_tables' ? 15 : 5, $this->tenantConnection());
        $this->assertDatabaseHas($recordTable, ['id' => $record->id, 'title' => 'Record 1'], $this->tenantConnection());
        $this->assertDatabaseCount($auditTable, $auditCount, $this->tenantConnection());
        $this->assertContextMissing();
    }

    public function test_login_and_logout_roll_back_tokens_when_audit_fails(): void
    {
        $token = $this->postJson('/api/v1/auth/staff/login', $this->login())->assertOk()->json('token');
        $count = DB::table('public.personal_access_tokens')->count();
        $this->mock(AuditLogger::class)->shouldReceive('write')->andThrow(new \RuntimeException('Unavailable'));
        $this->postJson('/api/v1/auth/staff/login', $this->login('beta'))->assertStatus(500);
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertStatus(500);
        $this->assertDatabaseCount('public.personal_access_tokens', $count);
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->assertContextMissing();
    }
}
