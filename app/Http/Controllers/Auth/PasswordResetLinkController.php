<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('ForgotPassword');
    }

    /**
     * Dispatch a real reset link via Laravel's password broker. The response
     * is always a success regardless of the broker's actual result — telling
     * the caller whether an address exists would leak account information.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($validated);

        return back()->with('resetLinkSentTo', mb_strtolower($validated['email']));
    }
}
