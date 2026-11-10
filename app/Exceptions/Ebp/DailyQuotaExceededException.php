<?php

namespace App\Exceptions\Ebp;

use RuntimeException;

/** Plafond journalier d'appels EBP atteint (10 000/jour). */
class DailyQuotaExceededException extends RuntimeException
{
    public function __construct(public int $used, public int $max)
    {
        parent::__construct("Quota journalier EBP atteint ({$used}/{$max}).");
    }
}
