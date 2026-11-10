<?php

namespace App\Services\Ebp;

use App\Exceptions\Ebp\DailyQuotaExceededException;
use Illuminate\Support\Facades\Cache;

/**
 * Garantit le respect du quota EBP : <= 1 appel/seconde AU GLOBAL (tous comptes, toutes apps),
 * et le plafond journalier (10 000). Point d'étranglement unique de tous les appels sortants EBP.
 *
 * Implémentation portable (Cache + Cache::lock), compatible Redis en prod et array en test.
 * En prod, le store de cache doit être PARTAGÉ (Redis) pour que la limite soit réellement globale.
 */
class QuotaRegulator
{
    private const LAST_CALL_KEY = 'ebp:quota:last-call-at';
    private const DAILY_KEY_PREFIX = 'ebp:quota:daily:';
    private const LOCK_KEY = 'ebp:quota:lock';

    /**
     * Exécute $callback en respectant le quota (attend si nécessaire pour tenir 1/s).
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     *
     * @throws DailyQuotaExceededException
     */
    public function run(callable $callback)
    {
        $this->reserveSlot();

        return $callback();
    }

    /**
     * Réserve un créneau d'appel : vérifie le quota journalier, puis espace à >= 1 s du précédent.
     */
    protected function reserveSlot(): void
    {
        $lock = Cache::lock(self::LOCK_KEY, 10);
        $lock->block(10); // attend le verrou jusqu'à 10 s

        try {
            $this->assertDailyQuota();

            $minIntervalMs = (int) (1000 / max(1, (int) config('ebp.quota.per_second')));
            $now = $this->nowMs();
            $last = (int) Cache::get(self::LAST_CALL_KEY, 0);
            $elapsed = $now - $last;

            if ($last > 0 && $elapsed < $minIntervalMs) {
                $this->sleepMs($minIntervalMs - $elapsed);
            }

            Cache::put(self::LAST_CALL_KEY, $this->nowMs(), 60);
            $this->incrementDaily();
        } finally {
            $lock->release();
        }
    }

    protected function assertDailyQuota(): void
    {
        $max = (int) config('ebp.quota.per_day');
        $used = (int) Cache::get($this->dailyKey(), 0);

        if ($used >= $max) {
            throw new DailyQuotaExceededException($used, $max);
        }
    }

    protected function incrementDaily(): void
    {
        $key = $this->dailyKey();

        if (Cache::get($key) === null) {
            // Expire à la fin de la journée (fenêtre glissante simple de 24 h).
            Cache::put($key, 1, now()->addDay());
        } else {
            Cache::increment($key);
        }
    }

    /** Nombre d'appels EBP consommés aujourd'hui. */
    public function usedToday(): int
    {
        return (int) Cache::get($this->dailyKey(), 0);
    }

    protected function dailyKey(): string
    {
        return self::DAILY_KEY_PREFIX.now()->format('Y-m-d');
    }

    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    protected function sleepMs(int $ms): void
    {
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
