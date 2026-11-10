<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Point de contrôle de santé, pour la supervision (Uptime Kuma, etc.).
 *
 * GET /healthcheck
 *  - public : statut global uniquement — 200 { "status": "ok" } ou 503 { "status": "down" }.
 *  - avec ?token=<HEALTHCHECK_TOKEN> : détail par brique (base, redis, cache, queue, config).
 *
 * Aucune donnée sensible n'est exposée : pas de secret, pas de version, pas de chemin.
 */
class HealthcheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'cache' => $this->checkCache(),
            'queue' => $this->checkQueue(),
            'config' => $this->checkConfig(),
        ];

        $healthy = ! collect($checks)->contains(fn ($c) => $c['ok'] === false);
        $status = $healthy ? 200 : 503;

        // Détail réservé au porteur du token ; sinon, statut global seulement.
        $token = config('ebp.healthcheck_token');
        $authorized = $token && hash_equals($token, (string) $request->query('token'));

        $payload = $authorized
            ? ['status' => $healthy ? 'ok' : 'down', 'checks' => $checks, 'time' => now()->toIso8601String()]
            : ['status' => $healthy ? 'ok' : 'down'];

        // Jamais mis en cache : la supervision doit toujours mesurer l'état réel (et le
        // navigateur ne doit pas resservir une ancienne réponse avec/sans token).
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    private function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1');

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->short($e)];
        }
    }

    private function checkRedis(): array
    {
        try {
            $pong = Redis::connection()->ping();
            // phpredis renvoie true ; predis renvoie un objet Status qui vaut "PONG" en chaîne.
            $ok = $pong === true || in_array(strtoupper(trim((string) $pong)), ['PONG', '+PONG'], true);

            return ['ok' => $ok];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->short($e)];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'healthcheck:'.uniqid();
            Cache::put($key, '1', 5);
            $ok = Cache::get($key) === '1';
            Cache::forget($key);

            return ['ok' => $ok];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->short($e)];
        }
    }

    private function checkQueue(): array
    {
        try {
            $connection = config('queue.default');
            // Taille de la file par défaut (tolérant selon le driver).
            $size = null;
            try {
                $size = app('queue')->connection()->size();
            } catch (\Throwable) {
                // size() non supporté par tous les drivers : on reste à null.
            }

            return ['ok' => true, 'connection' => $connection, 'pending' => $size];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->short($e)];
        }
    }

    private function checkConfig(): array
    {
        $issues = [];

        if (empty(config('app.key'))) {
            $issues[] = 'APP_KEY manquante';
        }

        // En production, le faux portail EBP doit être désactivé.
        if (app()->environment('production') && config('ebp.fake_portal')) {
            $issues[] = 'faux portail EBP actif en production';
        }

        return ['ok' => $issues === [], 'issues' => $issues ?: null];
    }

    /** Message d'erreur tronqué (jamais de trace ni de chemin complet). */
    private function short(\Throwable $e): string
    {
        return substr(class_basename($e).': '.$e->getMessage(), 0, 120);
    }
}
