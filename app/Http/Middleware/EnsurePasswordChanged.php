<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->force_password_change) {
            return redirect()
                ->route('profile.edit')
                ->withErrors([
                    'password' => 'Ganti password awal sebelum menggunakan fitur lainnya.',
                ]);
        }

        return $next($request);
    }
}
