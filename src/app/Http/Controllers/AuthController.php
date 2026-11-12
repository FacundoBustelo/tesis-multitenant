<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request, string $actorType): JsonResponse
    {
        $data = $request->validate(['tenant' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $tenant = Tenant::where('slug', $data['tenant'])->first();
        $class = $actorType === 'staff' ? Staff::class : User::class;
        $actor = $tenant ? $class::where('tenant_id', $tenant->id)->where('email', $data['email'])->first() : null;
        $valid = Hash::check($data['password'], $actor?->password ?? '$2y$12$ENpSwIgYADUYXfNo.XWDE.42Qy6rzwVFL9dMHHwgOuC6kM5tYZtdS');
        abort_unless($actor && $valid, 401, 'Invalid credentials.');
        $context = app(TenantContext::class);
        $context->set((int) $actor->tenant_id);
        try {
            return DB::transaction(function () use ($actor) {
                $token = $actor->createToken('api', ['*'], now()->addHour());
                app(AuditLogger::class)->write($actor, 'auth.login', 'actor', $actor->id);

                return response()->json(['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $token->accessToken->expires_at]);
            });
        } finally {
            $context->clear();
        }
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user(), 'actor_type' => $request->user() instanceof Staff ? 'staff' : 'user']);
    }

    public function logout(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            app(AuditLogger::class)->write($request->user(), 'auth.logout', 'actor', $request->user()->id);
            $request->user()->currentAccessToken()->delete();

            return response()->json(null, 204);
        });
    }
}
