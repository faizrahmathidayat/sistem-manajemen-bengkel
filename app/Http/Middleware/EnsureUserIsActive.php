<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureUserIsActive
{
    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect('/login')->withErrors(['username' => 'Akun tidak aktif.']);
        }

        return $next($request);
    }
}
