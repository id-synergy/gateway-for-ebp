<?php

namespace App\Support;

/**
 * Résume le corps d'erreur renvoyé par l'API EBP pour le relayer à l'app cliente.
 *
 * But : donner un message exploitable (ce qu'EBP reproche réellement) sans exposer de détail
 * interne — en particulier la StackTrace .NET d'EBP, qui révèle des chemins serveur et n'aide
 * pas l'app. Gère un corps JSON structuré (errorType/errors[].errorMessage/message/title),
 * un corps JSON simple ({message}), ou un corps texte/HTML (ex. page 414 « Request URL Too Long »).
 */
class EbpErrorFormatter
{
    /** Longueur maximale du résumé renvoyé à l'app. */
    private const MAX = 500;

    /** Codes d'erreur EBP à ne jamais relayer (bruit ou détail interne). */
    private const SKIP_CODES = ['StackTrace'];

    public static function summarize(?string $body): ?string
    {
        $body = trim((string) $body);
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            return self::fromJson($decoded);
        }

        // Corps non-JSON (texte brut, page HTML d'erreur serveur) : on nettoie et on tronque.
        return self::truncate(self::stripHtml($body));
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private static function fromJson(array $decoded): ?string
    {
        $messages = [];

        // Forme structurée EBP : errors: [{errorCode, errorMessage}, ...]
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $err) {
                if (! is_array($err)) {
                    continue;
                }
                $code = (string) ($err['errorCode'] ?? '');
                if (in_array($code, self::SKIP_CODES, true)) {
                    continue;
                }
                $msg = trim((string) ($err['errorMessage'] ?? ''));
                if ($msg !== '') {
                    $messages[] = $code !== '' && $code !== 'ERROR' ? "{$code}: {$msg}" : $msg;
                }
            }
        }

        // Fallback : message / title, seulement si errors[] n'a rien donné d'exploitable.
        // (title/message d'EBP sont souvent des clés i18n brutes, ex. EBP_..._TITLE.)
        if ($messages === []) {
            foreach (['message', 'title'] as $key) {
                $val = trim((string) ($decoded[$key] ?? ''));
                if ($val !== '') {
                    $messages[] = $val;
                }
            }
        }

        $messages = array_values(array_unique(array_filter($messages)));

        return $messages === [] ? null : self::truncate(implode(' | ', $messages));
    }

    private static function stripHtml(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', strip_tags($s)) ?? $s;

        return trim($s);
    }

    private static function truncate(string $s): string
    {
        return mb_strlen($s) > self::MAX ? mb_substr($s, 0, self::MAX - 1).'…' : $s;
    }
}
