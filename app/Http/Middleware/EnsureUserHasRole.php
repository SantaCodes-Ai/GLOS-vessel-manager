<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        abort_unless($user && $user->canAccessApplication() && (in_array($role, ['admin', 'super_admin'], true) ? $user->isAdmin() : $user->role === $role), 403);

        return $next($request);
    }
}
