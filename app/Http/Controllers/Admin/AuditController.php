<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAction;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Journal d'actions (audit trail) des admins du back-office : qui a fait quoi
 * (création, modification, suppression) sur les ressources sensibles (table admin_actions).
 *
 * À ne pas confondre avec les Connexions (login/logout, admin_auth_events) ni avec les
 * Logs (trafic des apps vers EBP, request_logs).
 */
class AuditController extends Controller
{
    /** Types d'actions connus, dans l'ordre d'affichage du filtre. Les libellés
     *  lisibles sont traduits via actionLabels() (lang/<locale>/messages.php → audit). */
    public const ACTION_TYPES = [
        'account.created', 'account.updated', 'account.deleted', 'account.folders_synced',
        'token.created', 'token.revoked', 'token.regenerated', 'token.rules_updated', 'token.paired',
        'ip.blocked', 'ip.allowed', 'ip.unclassified', 'ip.removed',
        'admin.created', 'admin.deactivated', 'admin.reactivated',
        'invitation.created', 'invitation.revoked',
        'cache.cleared_account', 'cache.cleared_all',
    ];

    /**
     * Libellés lisibles par type d'action, dans la langue courante. Construit à la
     * volée pour suivre la locale (cookie/navigateur) à chaque requête.
     *
     * @return array<string, string>
     */
    public static function actionLabels(): array
    {
        // Les types contiennent un point (« account.created ») : on récupère tout le
        // tableau « audit » d'un coup plutôt que clé par clé, sinon __() interprète le
        // point comme un niveau imbriqué et ne trouve pas la traduction.
        $audit = (array) __('messages.audit');

        $labels = [];
        foreach (self::ACTION_TYPES as $type) {
            $labels[$type] = $audit[$type] ?? $type;
        }

        return $labels;
    }

    public function index(Request $request): Response
    {
        $filters = [
            'user' => $request->integer('user') ?: null,
            'action' => $request->string('action')->toString(),
            'search' => $request->string('search')->toString(),
        ];

        $query = AdminAction::with('user')->orderByDesc('occurred_at');

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }
        if ($filters['action'] !== '') {
            $query->where('action', $filters['action']);
        }
        if ($filters['search'] !== '') {
            // Recherche texte sur le libellé figé et l'IP.
            $query->where(function ($q) use ($filters) {
                $q->where('label', 'like', "%{$filters['search']}%")
                    ->orWhere('ip', 'like', "%{$filters['search']}%");
            });
        }

        $actions = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/Audit', [
            'actions' => $actions->through(fn (AdminAction $a) => [
                'id' => $a->id,
                'at' => $a->occurred_at->format('d/m/Y H:i:s'),
                'adminName' => $a->user?->name,
                'adminEmail' => $a->user?->email,
                'action' => $a->action,
                'actionLabel' => self::actionLabels()[$a->action] ?? $a->action,
                'label' => $a->label,
                // Type de ressource lisible (dernier segment de la classe), sans exposer le FQCN.
                'subjectType' => $a->subject_type ? class_basename($a->subject_type) : null,
                'ip' => $a->ip,
                'properties' => $a->properties,
            ]),
            'filters' => $filters,
            'userOptions' => User::orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'actionOptions' => collect(self::actionLabels())
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }
}
