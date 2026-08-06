<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('ForgotPassword');
    }

    /**
     * Accept a reset request.
     *
     * The prototype has no reset-password screen to land on, so no mail is
     * dispatched and the response is always a success — which is also how a
     * real implementation should behave, since telling the caller whether an
     * address exists leaks account information.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        return back()->with('resetLinkSentTo', mb_strtolower($validated['email']));
    }
}
