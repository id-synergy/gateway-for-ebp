<?php

namespace App\Exceptions\Ebp;

use RuntimeException;

/**
 * Échec d'authentification OAuth EBP côté gateway : le compte n'a pas pu obtenir ou
 * renouveler son token (refresh expiré/révoqué, identifiants invalides…).
 *
 * Ce n'est pas une faute de l'app cliente : le compte EBP doit être reconnecté par un
 * administrateur dans le back-office. L'API répond 503 (service indisponible pour ce compte).
 */
class EbpAuthException extends RuntimeException
{
    public function __construct(
        public int $ebpStatus = 0,
        string $message = "Compte EBP non authentifié.",
    ) {
        parent::__construct($message);
    }
}
