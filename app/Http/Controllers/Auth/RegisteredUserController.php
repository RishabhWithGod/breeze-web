<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Signup');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->lower()->toString(),
            'password' => Hash::make((string) $request->input('password')),
        ]);

        // Every new account describes its company, accepts the terms and sets up payment before it does anything else.
        $user->forceFill(['needs_company_setup' => true, 'needs_terms_acceptance' => true, 'needs_payment_setup' => true])->save();

        Auth::login($user);

        return redirect()->route('home');
    }
}
