<?php

namespace App\Services\Logging;

use App\Models\RequestLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Journalisation hybride d'un appel : métadonnées en base (request_logs) + corps de réponse en fichier.
 * Rétention 360 j (purge par ebp:logs:purge). Aucun secret dans les métas.
 */
class RequestLogger
{
    /** Disque de stockage des corps de réponse. */
    public const DISK = 'local';
    public const DIR = 'ebp-logs';

    /**
     * Enregistre un appel. $meta contient les champs de métadonnées (sans secret).
     * $body est le corps brut de la réponse (écrit en fichier si non vide).
     *
     * @param  array<string,mixed>  $meta
     */
    public function record(array $meta, ?string $body = null): RequestLog
    {
        $bodyPath = null;
        $size = null;

        if ($body !== null && $body !== '') {
            $bodyPath = $this->storeBody($body);
            $size = strlen($body);
        }

        return RequestLog::create(array_merge([
            'occurred_at' => now(),
            'success' => false,
        ], $meta, [
            'response_body_path' => $bodyPath,
            'response_size' => $size,
        ]));
    }

    protected function storeBody(string $body): string
    {
        $path = self::DIR.'/'.now()->format('Y/m/d').'/'.Str::uuid()->toString().'.json';
        Storage::disk(self::DISK)->put($path, $body);

        return $path;
    }
}
