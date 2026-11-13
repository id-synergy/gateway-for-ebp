<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pose les en-têtes HTTP de sécurité sur TOUTES les réponses de la gateway
 * (back-office Inertia, /api/v1, proxy EBP racine).
 *
 * Middleware « après » : il n'inspecte pas la requête pour la rejeter, il décore
 * seulement la réponse produite par la chaîne. Les en-têtes s'AJOUTENT — si une
 * réponse fixe déjà l'un d'eux (cas rare, ex. un contrôleur spécifique), on ne
 * l'écrase pas. Il ne touche donc ni au corps JSON des erreurs, ni au comportement
 * de block.ip / throttle / app.token.
 *
 * Tous les en-têtes sont configurables via config/ebp.php (section security.headers).
 * La CSP peut être déployée en mode report-only (observation sans blocage) avant
 * d'être passée en mode bloquant.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $config = (array) config('ebp.security.headers', []);

        $this->setIfAbsent($response, 'X-Content-Type-Options', 'nosniff');
        $this->setIfAbsent($response, 'X-Frame-Options', (string) ($config['frame_options'] ?? 'DENY'));
        $this->setIfAbsent($response, 'Referrer-Policy', (string) ($config['referrer_policy'] ?? 'strict-origin-when-cross-origin'));

        if (! empty($config['permissions_policy'])) {
            $this->setIfAbsent($response, 'Permissions-Policy', (string) $config['permissions_policy']);
        }

        // HSTS : uniquement en HTTPS. En clair, poser HSTS n'a pas de sens et peut
        // rendre un environnement HTTP inaccessible après un premier passage HTTPS.
        if ($request->isSecure()) {
            $this->setIfAbsent($response, 'Strict-Transport-Security', $this->buildHsts($config));
        }

        if (! empty($config['csp_enabled']) && ! empty($config['csp'])) {
            $header = ! empty($config['csp_report_only'])
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $this->setIfAbsent($response, $header, (string) $config['csp']);
        }

        return $response;
    }

    /**
     * Construit la valeur de l'en-tête Strict-Transport-Security.
     */
    private function buildHsts(array $config): string
    {
        $value = 'max-age='.(int) ($config['hsts_max_age'] ?? 31536000);

        if (! empty($config['hsts_include_subdomains'])) {
            $value .= '; includeSubDomains';
        }

        if (! empty($config['hsts_preload'])) {
            $value .= '; preload';
        }

        return $value;
    }

    /**
     * N'ajoute l'en-tête que s'il n'est pas déjà présent sur la réponse :
     * on décore, on ne remplace pas ce qu'un contrôleur aurait fixé exprès.
     */
    private function setIfAbsent(Response $response, string $name, string $value): void
    {
        if ($value === '' || $response->headers->has($name)) {
            return;
        }

        $response->headers->set($name, $value);
    }
}
