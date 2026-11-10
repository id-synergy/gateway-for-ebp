<?php

namespace App\Exceptions\Ebp;

use RuntimeException;

/** Erreur renvoyée par l'API EBP (status HTTP + corps). */
class EbpApiException extends RuntimeException
{
    public function __construct(
        public int $status,
        public string $body = '',
    ) {
        parent::__construct("Erreur API EBP (HTTP {$status}).");
    }

    public function isRateLimited(): bool
    {
        return $this->status === 429;
    }
}
