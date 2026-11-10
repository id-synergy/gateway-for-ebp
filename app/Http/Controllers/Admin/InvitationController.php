<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitations à créer un compte admin (back-office, réservé aux admins).
 */
class InvitationController extends Controller
{
    /** Durée de validité d'une invitation. */
    private const TTL_HOURS = 24;

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'is_default_contact' => 'boolean',
        ]);

        if (User::where('email', $data['email'])->exists()) {
            return back()->with('error', 'Un compte existe déjà avec cette adresse.');
        }

        // Une seule invitation en attente par e-mail : on remplace la précédente.
        Invitation::where('email', $data['email'])->whereNull('accepted_at')->delete();

        $plainToken = Str::random(48);

        $invitation = Invitation::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'token' => Invitation::hashToken($plainToken),
            'is_default_contact' => $data['is_default_contact'] ?? false,
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        $acceptUrl = url('/invitation/'.$plainToken);

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $acceptUrl));

        return back()->with('success', "Invitation envoyée à {$invitation->email}.");
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        $invitation->delete();

        return back()->with('success', 'Invitation révoquée.');
    }
}
