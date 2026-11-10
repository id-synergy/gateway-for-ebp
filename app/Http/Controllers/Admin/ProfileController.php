<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profil de l'admin connecté : gère notamment l'activation de la double authentification (2FA).
 * Les actions 2FA elles-mêmes sont servies par Fortify (enable, qr-code, confirm, recovery-codes).
 */
class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Admin/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
                'twoFactorPending' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
            ],
        ]);
    }
}
