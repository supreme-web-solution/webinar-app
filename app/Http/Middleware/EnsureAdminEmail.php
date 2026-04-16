<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $email = strtolower(trim((string) ($user?->email ?? '')));
        $adminEmails = config('app.admin_emails', []);

        abort_unless($email !== '' && in_array($email, $adminEmails, true), 403);

        return $next($request);
    }
}

