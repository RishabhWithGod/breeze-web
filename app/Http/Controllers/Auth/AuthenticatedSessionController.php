<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /** Name of the cookie that pre-fills the email after "Remember me". */
    private const REMEMBERED_EMAIL = 'breeze_remembered_email';

    public function create(Request $request): Response
    {
        return Inertia::render('Login', [
            'rememberedEmail' => $request->cookie(self::REMEMBERED_EMAIL),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $response = redirect()->intended(route('home'));

        // "Remember me" both extends the session and pre-fills the address next
        // time, matching the prototype's behaviour.
        return $request->boolean('remember')
            ? $response->withCookie(cookie()->forever(self::REMEMBERED_EMAIL, $request->string('email')->lower()->toString()))
            : $response->withCookie(cookie()->forget(self::REMEMBERED_EMAIL));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
