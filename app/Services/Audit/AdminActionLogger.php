<?php

namespace App\Services\Audit;

use App\Models\AdminAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Journalise les ACTIONS d'administration du back-office (audit trail) : qui a fait quoi
 * sur les ressources sensibles — comptes EBP, jetons d'apps, IP, admins, invitations.
 *
 * À appeler APRÈS chaque écriture réussie, au plus près de son exécution dans les
 * contrôleurs d'action. Le journal est secondaire : une erreur d'écriture ne doit jamais
 * faire échouer l'opération métier.
 *
 * Règle absolue : ne JAMAIS passer de secret dans `$properties` (jeton en clair, mot de
 * passe, client_secret, subscription key). On loge le label/id et le contexte utile, pas
 * les credentials.
 */
class AdminActionLogger
{
    /**
     * @param  string  $action  Identifiant stable, ex. « account.created », « token.revoked », « ip.blocked ».
     * @param  Model|null  $subject  Ressource visée (relation polymorphe) ; null si déjà supprimée ou non pertinent.
     * @param  string  $label  Libellé lisible FIGÉ (nom du compte/jeton/IP au moment de l'action).
     * @param  array<string,mixed>  $properties  Contexte/diff utile — SANS AUCUN SECRET.
     */
    public function log(string $action, ?Model $subject, string $label, array $properties = []): void
    {
        try {
            AdminAction::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'label' => $label,
                'properties' => $properties ?: null,
                'ip' => Request::ip(),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // Audit secondaire : ne jamais casser l'opération métier pour un log.
        }
    }
}
