<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The logged-in user's own profile: name plus the read-only, security-owned
 * fields (email, phone). Email/phone changes stay on the Security page since
 * those go through an OTP-verified challenge — this page only ever writes
 * the one field (`name`) that carries no verification requirement.
 */
class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile', [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'initials' => $user->initials,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Profile updated.');
    }
}
