<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        abort_unless(($actor instanceof User || $actor instanceof Staff) && $actor->tenant_id, 401);
        $context = app(TenantContext::class);
        $context->set((int) $actor->tenant_id);
        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
