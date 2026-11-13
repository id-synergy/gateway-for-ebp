<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Détermine la langue de l'interface, dans cet ordre de priorité :
 *   1. le cookie `locale` posé par le sélecteur FR/EN (choix explicite de l'utilisateur) ;
 *   2. l'en-tête Accept-Language du navigateur ;
 *   3. le fallback de config.
 *
 * Seules les langues réellement traduites sont acceptées ; toute autre valeur
 * (cookie trafiqué, navigateur exotique) retombe sur le fallback.
 */
class SetLocale
{
    /** Langues effectivement disponibles dans l'interface. */
    public const SUPPORTED = ['fr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        // 1. Choix explicite via le sélecteur (cookie).
        $cookie = $request->cookie('locale');
        if (is_string($cookie) && in_array($cookie, self::SUPPORTED, true)) {
            return $cookie;
        }

        // 2. Préférence du navigateur — uniquement si l'en-tête Accept-Language est
        //    réellement envoyé. Sans en-tête, Symfony renverrait « en » par défaut,
        //    ce qui basculerait à tort en anglais : on ne lit donc getLanguages()
        //    que lorsque le navigateur a vraiment exprimé une préférence.
        if ($request->headers->has('Accept-Language')) {
            foreach ($request->getLanguages() as $language) {
                // Normalise « en-US », « fr_FR » → « en », « fr ».
                $base = strtolower(substr(str_replace('_', '-', $language), 0, 2));
                if (in_array($base, self::SUPPORTED, true)) {
                    return $base;
                }
            }
        }

        // 3. Aucun indice : on sert la locale par défaut de l'application (app.locale,
        //    « fr » — l'UI historique). On ne prend PAS app.fallback_locale ici : le
        //    fallback sert à combler une traduction manquante, pas à choisir la langue
        //    d'un visiteur qui n'a rien demandé.
        $default = config('app.locale', 'fr');

        return in_array($default, self::SUPPORTED, true) ? $default : 'fr';
    }
}
