<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminWebAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('web')->check() && Auth::user()?->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:120'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $login = trim($validated['login']);
        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->orWhere('username', $login)
            ->first();

        if ($user === null
            || $user->role !== 'admin'
            || ! Hash::check($validated['password'], $user->password)) {
            return back()
                ->withInput($request->only('login', 'remember'))
                ->withErrors(['login' => 'Las credenciales no son validas o no pertenecen a un administrador.']);
        }

        Auth::guard('web')->login($user, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
