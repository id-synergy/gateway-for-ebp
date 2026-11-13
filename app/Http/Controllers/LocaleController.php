<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Change explicite de langue via le sélecteur FR/EN. On pose un cookie de longue
 * durée, que SetLocale relira à chaque requête (il a priorité sur le navigateur),
 * puis on renvoie l'utilisateur d'où il vient.
 */
class LocaleController extends Controller
{
    public function update(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetLocale::SUPPORTED, true), 404);

        // Cookie un an, pas httpOnly (purement préférence d'affichage), lisible au besoin côté front.
        cookie()->queue(cookie(name: 'locale', value: $locale, minutes: 60 * 24 * 365));

        return back();
    }
}
