<?php

namespace App\Services\Ebp;

use App\Exceptions\Ebp\EbpApiException;
use App\Models\EbpAccount;
use App\Models\EbpFolder;
use App\Services\Logging\RequestLogger;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client HTTP vers l'API EBP SaaS Gestion.
 *
 * Ajoute les en-têtes requis (Authorization: Bearer <access token du compte>,
 * ebp-subscription-key: <clé du compte>, Accept-Language), cible le dossier,
 * passe TOUJOURS par le QuotaRegulator, et normalise les réponses (wrappers, décimaux préservés).
 */
class EbpClient
{
    /** Nombre de tentatives sur 429 (plus la tentative initiale). */
    private const MAX_RETRIES_429 = 2;

    public function __construct(
        protected EbpTokenService $tokens,
        protected QuotaRegulator $quota,
        protected RequestLogger $logger,
    ) {}

    /**
     * GET sur un endpoint EBP (relatif, ex. "gescom/api/v1/...") pour un compte + dossier.
     * Raccourci de {@see send()} en lecture.
     *
     * @param  array<string,mixed>  $query
     * @return array{data: mixed, paging: array<string,mixed>|null, raw: string}
     */
    public function get(EbpAccount $account, ?EbpFolder $folder, string $endpoint, array $query = []): array
    {
        return $this->send($account, $folder, 'GET', $endpoint, $query);
    }

    /**
     * Relaie une requête de n'importe quel verbe vers EBP (proxy transparent).
     * Journalise l'appel (méta + corps) quel que soit son issue.
     *
     * @param  array<string,mixed>  $query  Paramètres de query string.
     * @param  mixed  $body  Corps JSON pour les écritures (POST/PUT/PATCH). null en lecture.
     * @return array{data: mixed, paging: array<string,mixed>|null, raw: string, status: int}
     */
    public function send(EbpAccount $account, ?EbpFolder $folder, string $method, string $endpoint, array $query = [], mixed $body = null): array
    {
        $method = strtoupper($method);
        $startedAt = microtime(true);

        try {
            $response = $this->request($account, $method, $endpoint, $query, $body);
            $this->log($account, $folder, $method, $endpoint, $query, $response->status(), true, $response->body(), $startedAt);

            return $this->normalize($response) + ['status' => $response->status()];
        } catch (EbpApiException $e) {
            $this->log($account, $folder, $method, $endpoint, $query, $e->status, false, $e->body, $startedAt);
            throw $e;
        } catch (\Throwable $e) {
            $this->log($account, $folder, $method, $endpoint, $query, 0, false, null, $startedAt);
            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $query
     */
    protected function log(EbpAccount $account, ?EbpFolder $folder, string $method, string $endpoint, array $query, int $status, bool $success, ?string $body, float $startedAt): void
    {
        // Contexte de la requête entrante (app, IP, route gateway) quand on est en HTTP.
        $request = request();
        $appToken = $request?->attributes->get('app_token');

        $this->logger->record([
            'app_token_id' => $appToken?->id,
            'ebp_account_id' => $account->id,
            'ebp_folder_id' => $folder?->id,
            'ip' => $request?->ip(),
            'gateway_route' => $request?->path(),
            'method' => $method,
            'ebp_endpoint' => $endpoint,
            'params' => $query,
            'http_status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'success' => $success,
        ], $body);
    }

    /**
     * Exécute la requête via le régulateur de quota, avec gestion du 429 (backoff + retry).
     *
     * @param  array<string,mixed>  $query
     */
    protected function request(EbpAccount $account, string $method, string $endpoint, array $query, mixed $body): Response
    {
        // Mode démo : réponse simulée, sans appel réseau (ni quota consommé).
        if ($account->usesFakeEbp()) {
            return $this->demoResponse($method, $endpoint, $query, $body);
        }

        $url = rtrim($account->apiBaseUrl(), '/').'/'.ltrim($endpoint, '/');
        $attempt = 0;

        while (true) {
            $response = $this->quota->run(function () use ($account, $method, $url, $query, $body) {
                $pending = Http::withHeaders([
                    'Authorization' => 'Bearer '.$this->tokens->validAccessToken($account),
                    'ebp-subscription-key' => $account->activeSubscriptionKey(),
                    'Accept-Language' => 'fr-FR',
                ]);

                // Écritures : corps JSON. Lectures : paramètres en query string.
                return match ($method) {
                    'GET' => $pending->get($url, $query),
                    'DELETE' => $pending->delete($url, $query),
                    'POST' => $pending->withQueryParameters($query)->post($url, $body ?? []),
                    'PUT' => $pending->withQueryParameters($query)->put($url, $body ?? []),
                    'PATCH' => $pending->withQueryParameters($query)->patch($url, $body ?? []),
                    default => $pending->send($method, $url, ['query' => $query, 'json' => $body]),
                };
            });

            if ($response->status() === 429 && $attempt < self::MAX_RETRIES_429) {
                $this->backoff($response, $attempt);
                $attempt++;

                continue;
            }

            if ($response->failed()) {
                throw new EbpApiException($response->status(), $response->body());
            }

            return $response;
        }
    }

    /**
     * Normalise la réponse EBP en enveloppe stable.
     * Gère les wrappers hétérogènes (results / data / folders / tableau nu) et préserve
     * la précision des décimaux (décodage avec JSON_BIGINT_AS_STRING, corps brut conservé).
     *
     * @return array{data: mixed, paging: array<string,mixed>|null, raw: string}
     */
    protected function normalize(Response $response): array
    {
        $raw = $response->body();
        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);

        $paging = null;
        $data = $decoded;

        if (is_array($decoded)) {
            foreach (['results', 'data', 'folders', 'elements', 'linesEntries'] as $key) {
                if (array_key_exists($key, $decoded)) {
                    $data = $decoded[$key];
                    break;
                }
            }

            if (isset($decoded['paging']) && is_array($decoded['paging'])) {
                $paging = $decoded['paging'];
            }
        }

        return ['data' => $data, 'paging' => $paging, 'raw' => $raw];
    }

    protected function backoff(Response $response, int $attempt): void
    {
        $retryAfter = (int) $response->header('Retry-After');
        $seconds = $retryAfter > 0 ? $retryAfter : (2 ** $attempt);
        usleep(min($seconds, 5) * 1_000_000);
    }

    /**
     * Construit une réponse simulée (mode démo) selon le verbe et l'endpoint EBP visé,
     * sans aucun appel réseau. Préserve le format exact (wrappers, décimaux) de l'API réelle.
     */
    protected function demoResponse(string $method, string $endpoint, array $query, mixed $body): Response
    {
        // Écritures : on renvoie une création/acceptation simulée, en écho du corps reçu.
        if ($method !== 'GET') {
            $status = $method === 'POST' ? 201 : 200;
            $payload = ['id' => 'DEMO_'.strtoupper(bin2hex(random_bytes(3))), 'echo' => $body];

            return $this->jsonResponse($status, $payload);
        }

        $payload = match (true) {
            str_contains($endpoint, '/Clients') => FakeEbpData::clients(),
            str_contains($endpoint, '/Items') => FakeEbpData::items($query),
            str_contains($endpoint, '/Documents') => FakeEbpData::documents(),
            str_contains($endpoint, '/Folders') => FakeEbpData::folders(),
            default => ['results' => [], 'paging' => ['offset' => 0, 'limit' => 100, 'total' => 0]],
        };

        return $this->jsonResponse(200, $payload);
    }

    /** Fabrique une réponse HTTP cliente à partir d'un payload JSON (mode démo). */
    protected function jsonResponse(int $status, array $payload): Response
    {
        return new Response(new \GuzzleHttp\Psr7\Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE),
        ));
    }
}
