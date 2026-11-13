<?php

namespace App\Console\Commands;

use App\Services\Alerts\AnomalyDetector;
use Illuminate\Console\Command;

/**
 * Évalue les règles de détection d'activité anormale (pic de 429, IP bloquées
 * insistantes, échecs de login admin répétés) sur la fenêtre glissante configurée
 * et déclenche les alertes e-mail aux admins abonnés.
 *
 * Planifiée toutes les 5 minutes (voir routes/console.php). L'anti-spam (cooldown
 * en cache) évite les alertes en boucle entre deux exécutions rapprochées.
 */
class DetectAnomalies extends Command
{
    protected $signature = 'gateway:detect-anomalies';

    protected $description = 'Détecte une activité anormale (429, IP bloquées, échecs de login) et alerte';

    public function handle(AnomalyDetector $detector): int
    {
        $emitted = $detector->run();

        if (empty($emitted)) {
            $this->info('Aucune anomalie détectée (ou signaux déjà alertés — anti-spam).');

            return self::SUCCESS;
        }

        $this->warn(count($emitted).' alerte(s) émise(s) : '.implode(', ', array_unique($emitted)).'.');

        return self::SUCCESS;
    }
}
