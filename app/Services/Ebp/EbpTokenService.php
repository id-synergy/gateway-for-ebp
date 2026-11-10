<?php

namespace App\Services\Ebp;

use App\Exceptions\Ebp\EbpAuthException;
use App\Models\EbpAccount;
use App\Models\EbpToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// FakeEbpData est dans le même namespace App\Services\Ebp.

/**
 * Gère le cycle de vie des tokens OAuth EBP, PAR COMPTE.
 *
 * Flux : Authorization Code (échange initial) puis refresh token tournant (30 j, usage unique).
 * Verrou anti-concurrence au renouvellement : voir RefreshEbpTokens (job planifié).
 */
class EbpTokenService
{
    /** Durées de repli si EBP n'envoie pas expires_in / refresh_expires_in (doc : 1 h / 30 j). */
    private const ACCESS_TTL_FALLBACK = 3600;
    private const REFRESH_TTL_FALLBACK = 30 * 24 * 3600;

    /**
     * Construit l'URL d'autorisation EBP pour démarrer la connexion d'un compte.
     * Le `state` protège du CSRF et permet de retrouver le compte au retour.
     */
    public function buildAuthorizeUrl(EbpAccount $account, string $state): string
    {
        $params = http_build_query([
            'client_id' => $account->client_id,
            'redirect_uri' => $account->redirectUri(),
            'response_type' => 'code',
            'scope' => config('ebp.scope'),
            'state' => $state,
            'response_mode' => 'query',
        ]);

        $authorizeUrl = $account->isDemo()
            ? rtrim(url('/fake-ebp/connect/authorize'), '?')
            : config('ebp.authorize_url');

        return rtrim($authorizeUrl, '?').'?'.$params;
    }

    /**
     * Échange le code d'autorisation contre access + refresh token, et les stocke (chiffrés) pour le compte.
     */
    public function exchangeCode(EbpAccount $account, string $code): EbpToken
    {
        // Mode démo : tokens simulés, sans appel réseau (évite toute boucle HTTP vers le faux serveur).
        if ($account->isDemo()) {
            $token = $this->storeTokenResponse($account, FakeEbpData::tokenResponse());
            $account->update(['status' => 'connected']);

            return $token;
        }

        $response = Http::asForm()->post($account->tokenUrl(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $account->client_id,
            'client_secret' => $account->client_secret,
            'redirect_uri' => $account->redirectUri(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Échange du code OAuth EBP échoué : '.$response->status().' '.$response->body());
        }

        $token = $this->storeTokenResponse($account, $response->json());

        $account->update(['status' => 'connected']);

        return $token;
    }

    /**
     * Renouvelle le token d'un compte via son refresh token.
     * Remplace l'ancien refresh (invalidé côté EBP). À appeler sous verrou par compte.
     */
    public function refresh(EbpAccount $account): EbpToken
    {
        $token = $account->token;

        if (! $token || ! $token->refresh_token) {
            throw new EbpAuthException(message: "Compte EBP non connecté (aucun refresh token). Reconnectez le compte dans le back-office.");
        }

        // Mode démo : renouvellement simulé, sans appel réseau.
        if ($account->isDemo()) {
            $token = $this->storeTokenResponse($account, FakeEbpData::tokenResponse());
            $account->update(['status' => 'connected']);

            return $token;
        }

        $response = Http::asForm()->post($account->tokenUrl(), [
            'grant_type' => 'refresh_token',
            'client_id' => $account->client_id,
            'client_secret' => $account->client_secret,
            'refresh_token' => $token->refresh_token,
        ]);

        if ($response->failed()) {
            $token->update([
                'state' => 'broken',
                'last_refresh_error' => $response->status().' '.$response->body(),
            ]);
            $account->update(['status' => 'error']);

            throw new EbpAuthException($response->status(), 'Renouvellement du token OAuth EBP échoué. Reconnectez le compte dans le back-office.');
        }

        $token = $this->storeTokenResponse($account, $response->json());

        $account->update(['status' => 'connected']);

        return $token;
    }

    /**
     * Retourne un access token valide pour le compte (renouvelle si expiré ou proche de l'expiration).
     */
    public function validAccessToken(EbpAccount $account): string
    {
        $token = $account->token;

        if (! $token) {
            throw new EbpAuthException(message: 'Compte EBP non connecté. Reconnectez le compte dans le back-office.');
        }

        $expired = ! $token->access_token
            || ! $token->access_token_expires_at
            || $token->access_token_expires_at->subMinutes(2)->isPast();

        if ($expired) {
            $token = $this->refresh($account);
        }

        return $token->access_token;
    }

    /**
     * Persiste une réponse de token EBP (échange ou refresh) sur le compte.
     *
     * @param  array<string,mixed>  $data
     */
    private function storeTokenResponse(EbpAccount $account, array $data): EbpToken
    {
        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            throw new RuntimeException('Réponse de token EBP incomplète (access_token/refresh_token manquant).');
        }

        $accessTtl = (int) ($data['expires_in'] ?? self::ACCESS_TTL_FALLBACK);
        $refreshTtl = (int) ($data['refresh_expires_in'] ?? self::REFRESH_TTL_FALLBACK);

        return EbpToken::updateOrCreate(
            ['ebp_account_id' => $account->id],
            [
                'access_token' => $data['access_token'],
                'access_token_expires_at' => Carbon::now()->addSeconds($accessTtl),
                'refresh_token' => $data['refresh_token'],
                'refresh_token_expires_at' => Carbon::now()->addSeconds($refreshTtl),
                'last_refreshed_at' => Carbon::now(),
                'last_refresh_error' => null,
                'state' => 'healthy',
            ],
        );
    }
}
