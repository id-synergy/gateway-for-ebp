<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Acceptation d'une invitation (public) : la personne définit son mot de passe et son
 * compte admin est créé, puis elle est connectée. La 2FA n'est pas imposée ici : elle
 * l'activera depuis son profil une fois dans le back-office.
 */
class AcceptInvitationController extends Controller
{
    /** Affiche la page de création de compte, ou une page « lien invalide ». */
    public function show(string $token): Response
    {
        $invitation = $this->resolve($token);

        if (! $invitation) {
            return Inertia::render('Auth/InvitationInvalid');
        }

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'name' => $invitation->name,
            'email' => $invitation->email,
        ]);
    }

    /** Crée le compte à partir de l'invitation et connecte la personne. */
    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolve($token);

        if (! $invitation) {
            return redirect('/login')->with('error', 'Cette invitation n\'est plus valide.');
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        // Garde-fou : l'e-mail a pu être pris entre l'envoi et l'acceptation.
        if (User::where('email', $invitation->email)->exists()) {
            return redirect('/login')->with('error', 'Un compte existe déjà avec cette adresse.');
        }

        $user = DB::transaction(function () use ($invitation, $request) {
            $user = User::create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => Hash::make($request->string('password')),
                'is_active' => true,
                'is_default_contact' => $invitation->is_default_contact,
            ]);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/admin')->with('success', 'Bienvenue ! Pensez à activer la double authentification depuis votre profil.');
    }

    /** Retourne l'invitation valide correspondant au token en clair, ou null. */
    private function resolve(string $plainToken): ?Invitation
    {
        $invitation = Invitation::where('token', Invitation::hashToken($plainToken))->first();

        return $invitation && $invitation->isValid() ? $invitation : null;
    }
}
