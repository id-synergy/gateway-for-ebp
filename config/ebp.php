<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Endpoints EBP (OAuth + API)
    |--------------------------------------------------------------------------
    | Les identifiants (ClientId/Secret, subscription keys) ne sont PAS ici :
    | ils sont stockés chiffrés en base, par compte EBP (table ebp_accounts).
    */
    'redirect_uri' => env('EBP_REDIRECT_URI', 'https://gateway.example.com/oauth/ebp/callback'),
    // redirect_uri « localhost » whitelisté d'origine chez EBP (avant validation de notre domaine).
    // Sert au mode de redirection « Localhost EBP » d'un compte, avec saisie manuelle du retour.
    'localhost_redirect_uri' => env('EBP_LOCALHOST_REDIRECT', 'http://localhost:3333/callback'),
    // Endpoint d'autorisation OAuth : /connect/authorize (SANS /callback — confirmé par le
    // discovery OpenID https://api-login.ebp.com/.well-known/openid-configuration ; la doc PDF
    // d'EBP montre à tort /connect/authorize/callback dans certains exemples).
    'authorize_url' => env('EBP_AUTHORIZE_URL', 'https://api-login.ebp.com/connect/authorize'),
    'token_url' => env('EBP_TOKEN_URL', 'https://api-login.ebp.com/connect/token'),
    'api_base_url' => env('EBP_API_BASE_URL', 'https://api-developpeurs.ebp.com'),
    'scope' => 'openid profile offline_access',

    // Faux portail EBP global (dev/test) : quand vrai, TOUT le flux OAuth et les appels
    // API de TOUS les comptes sont routés vers le faux serveur EBP intégré (routes fake-ebp),
    // quel que soit l'environnement du compte. Permet un environnement de test complet en local.
    //
    // GARDE-FOU : impossible en production — forcé à false si APP_ENV=production, même si
    // la variable est à true. Ne jamais activer sur un serveur de production.
    'fake_portal' => env('APP_ENV') !== 'production'
        && filter_var(env('EBP_FAKE_PORTAL', false), FILTER_VALIDATE_BOOLEAN),

    // Jeton pour obtenir le détail de /healthcheck (sinon, statut global seulement).
    'healthcheck_token' => env('HEALTHCHECK_TOKEN'),

    // URLs de base de l'API EBP selon l'environnement ciblé par chaque compte.
    // 'demo' pointe sur le serveur EBP simulé interne (voir routes 'fake-ebp'), pour tester
    // tout le flux de la gateway sans identifiants EBP réels.
    'api_base_urls' => [
        'production' => env('EBP_API_BASE_URL', 'https://api-developpeurs.ebp.com'),
        'preproduction' => env('EBP_API_BASE_URL_PREPROD', 'https://api-developpeurs-preprod.ebp.com'),
        'demo' => env('APP_URL', 'http://127.0.0.1:8000').'/fake-ebp',
    ],

    // URLs d'identité (OAuth) : surchargées en démo pour pointer sur le faux serveur.
    'token_urls' => [
        'default' => env('EBP_TOKEN_URL', 'https://api-login.ebp.com/connect/token'),
        'demo' => env('APP_URL', 'http://127.0.0.1:8000').'/fake-ebp/connect/token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota EBP (contrainte imposée par EBP)
    |--------------------------------------------------------------------------
    */
    'quota' => [
        'per_second' => 1,
        'per_day' => 10000,
        'max_page_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Comportement de la gateway
    |--------------------------------------------------------------------------
    */
    'gateway' => [
        // Au-delà de ce nombre de requêtes en attente, on bascule en job ID + polling.
        'queue_switch_threshold' => (int) env('GATEWAY_QUEUE_SWITCH_THRESHOLD', 10),
        // Plafond d'une requête synchrone avant conversion en job (secondes).
        'sync_timeout' => (int) env('GATEWAY_SYNC_TIMEOUT', 120),
        // Indication de fréquence de polling renvoyée aux apps (secondes).
        'poll_interval_hint' => (int) env('GATEWAY_POLL_INTERVAL_HINT', 3),
        // Gros rate-limit appliqué aux IP NON autorisées (req/min).
        'rate_limit_per_min' => (int) env('GATEWAY_RATE_LIMIT_PER_MIN', 30),

        // Cache des réponses de lecture EBP. Réglé PAR COMPTE dans le back-office
        // (activation + TTL) ; cette valeur n'est que le repli quand un compte ne fixe pas son TTL.
        // GET uniquement. Clé = compte + dossier + endpoint + query (signature complète de la requête).
        // Contournable par ?noCache=1 ou Cache-Control: no-cache. Une écriture invalide le cache du dossier.
        'cache' => [
            'default_ttl' => (int) env('GATEWAY_CACHE_TTL', 60),
        ],

        // Proxy transparent vers l'API EBP : toute requête (tout verbe) dont le chemin
        // commence par l'un de ces préfixes est relayée telle quelle vers EBP (auth, quota,
        // cache en lecture, logs ajoutés). Tout chemin hors liste -> 404.
        //
        // Un préfixe inclut le segment de version (…/v1 ou …/public/v1) : l'injection du
        // FolderId suppose le format <prefixe>/Folders/{id}/…. Les 7 modules EBP sont couverts
        // par défaut ; surchargeable via EBP_PROXY_PREFIXES (liste séparée par des virgules).
        'proxy' => [
            'allowed_prefixes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('EBP_PROXY_PREFIXES', implode(',', [
                    'gescom/api/v1',            // SAAS API Gestion
                    'hubbix-gctpe/api/public/v1', // HUBBIX Gestion Commerciale TPE
                    'hubbix-cpttpe/api/v1',     // HUBBIX Comptabilité TPE
                    'batiment/api/v1',          // SAAS API Bâtiment
                    'import/api/v1',            // SAAS API Import
                    'export/api/v1',            // SAAS API Export
                    'maintenance/api/v1',       // SAAS API Maintenance
                ])))
            ))),

            // Préfixes dont les ressources vivent DANS un dossier (chemin …/Folders/{FolderId}/…).
            // Pour EUX seulement, la gateway injecte le FolderId dans l'URL. Les autres modules
            // (gctpe, cpttpe, import, export) ne suivent pas ce modèle : le dossier passe par le
            // header (DomainId/tenantid) ou le corps (import/export), jamais dans l'URL.
            'folder_url_prefixes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('EBP_FOLDER_URL_PREFIXES', implode(',', [
                    'gescom/api/v1',
                    'batiment/api/v1',
                    'maintenance/api/v1',
                ])))
            ))),

            // Réécritures de compatibilité appliquées au chemin AVANT relai vers EBP.
            // L'API EBP expose certains endpoints sous une orthographe fautive qui ne
            // correspond pas à sa propre spec OpenAPI : l'URL « correcte » renvoie alors un
            // 404 "Resource not found". On accepte l'orthographe documentée côté apps clientes
            // et on la corrige ici vers ce qu'EBP attend réellement. Table {motif regex => remplacement}
            // (preg_replace, appliqué dans l'ordre). Garder ces contournements documentés et datés :
            // à retirer si EBP corrige son API.
            // Réécritures de compatibilité {motif regex => remplacement} appliquées au chemin
            // avant relai vers EBP. Vide : nos specs/catalogues utilisent désormais l'orthographe
            // réellement servie par EBP (ex. ItemsExtentedQuery), donc aucune correction n'est due.
            'path_rewrites' => [
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rétention (jours) — purge par le scheduler
    |--------------------------------------------------------------------------
    */
    'retention' => [
        'logs_days' => (int) env('GATEWAY_LOG_RETENTION_DAYS', 360),
        'job_results_days' => (int) env('GATEWAY_JOB_RESULT_RETENTION_DAYS', 360),
    ],

    /*
    |--------------------------------------------------------------------------
    | Renouvellement des tokens
    |--------------------------------------------------------------------------
    */
    'token_refresh' => [
        // Intervalle du job de renouvellement (heures). Large marge sur les 30 j.
        'interval_hours' => 24,
        // Seuil d'alerte "compte bientôt expiré" (jours restants).
        'expiring_alert_days' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertes — adresse de repli si aucun admin abonné / aucun contact par défaut
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'fallback_email' => env('GATEWAY_ALERT_FALLBACK_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Détection d'activité anormale (sécurité / charge)
    |--------------------------------------------------------------------------
    | Évaluée par la commande gateway:detect-anomalies (planifiée, voir
    | routes/console.php). Chaque règle compte des événements sur une FENÊTRE
    | GLISSANTE (en minutes) et alerte au-delà du seuil. Un même signal ne
    | ré-alerte pas avant l'expiration d'un délai de silence (anti-spam, via cache).
    |
    | Mettre un seuil à 0 désactive la règle correspondante.
    */
    'anomaly' => [
        // Fenêtre d'observation commune, en minutes.
        'window_minutes' => (int) env('ANOMALY_WINDOW_MINUTES', 15),

        // Délai de silence anti-spam, en minutes : pas de 2e alerte du même signal
        // (même type + même clé) avant ce délai. Par défaut = la fenêtre.
        'cooldown_minutes' => (int) env('ANOMALY_COOLDOWN_MINUTES', 15),

        // Pic de 429 relayés par EBP (quota amont) dans request_logs sur la fenêtre.
        // NB : les 429 de rate-limit gateway (bridage d'IP non autorisée) et les 403
        // de blocage ne passent pas par request_logs (court-circuit middleware) ;
        // ils sont couverts par les règles blocked_ip et login à part.
        'rate_limit_spike' => [
            'threshold' => (int) env('ANOMALY_RATE_LIMIT_THRESHOLD', 20),
        ],

        // Rafale de 403 ip_blocked (IP en liste noire qui insiste), journalisée a
        // minima dans blocked_request_events par le middleware BlockBannedIps.
        'blocked_ip' => [
            // Seuil GLOBAL (toutes IP confondues) sur la fenêtre.
            'global_threshold' => (int) env('ANOMALY_BLOCKED_IP_GLOBAL_THRESHOLD', 50),
            // Seuil PAR IP : une seule IP qui martèle au-delà de ce nombre.
            'per_ip_threshold' => (int) env('ANOMALY_BLOCKED_IP_PER_IP_THRESHOLD', 20),
        ],

        // Échecs de login admin (admin_auth_events event=failed) sur la fenêtre.
        'login_failures' => [
            // Seuil PAR IP source.
            'per_ip_threshold' => (int) env('ANOMALY_LOGIN_PER_IP_THRESHOLD', 5),
            // Seuil PAR e-mail visé (bourrage ciblé d'un compte).
            'per_email_threshold' => (int) env('ANOMALY_LOGIN_PER_EMAIL_THRESHOLD', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sécurité
    |--------------------------------------------------------------------------
    | enforce_2fa : impose la 2FA pour accéder au back-office (login fort).
    | Laissé désactivable pour le tout premier admin / le développement.
    */
    'security' => [
        'enforce_2fa' => (bool) env('GATEWAY_ENFORCE_2FA', false),
    ],

];
