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
    /** Libellés lisibles, par type d'action. Sert aussi à peupler le filtre. */
    public const ACTION_LABELS = [
        'account.created' => 'Compte EBP créé',
        'account.updated' => 'Compte EBP modifié',
        'account.deleted' => 'Compte EBP supprimé',
        'account.folders_synced' => 'Dossiers synchronisés',
        'token.created' => 'Jeton créé',
        'token.revoked' => 'Jeton révoqué',
        'token.regenerated' => 'Clé régénérée',
        'token.rules_updated' => "Règles d'accès modifiées",
        'token.paired' => 'Jeton appairé',
        'ip.blocked' => 'IP bloquée',
        'ip.allowed' => 'IP autorisée',
        'ip.unclassified' => 'IP déclassée',
        'ip.removed' => 'IP retirée',
        'admin.created' => 'Admin créé',
        'admin.deactivated' => 'Admin désactivé',
        'admin.reactivated' => 'Admin réactivé',
        'invitation.created' => 'Invitation envoyée',
        'invitation.revoked' => 'Invitation révoquée',
        'cache.cleared_account' => 'Cache compte vidé',
        'cache.cleared_all' => 'Cache global vidé',
    ];

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
                'actionLabel' => self::ACTION_LABELS[$a->action] ?? $a->action,
                'label' => $a->label,
                // Type de ressource lisible (dernier segment de la classe), sans exposer le FQCN.
                'subjectType' => $a->subject_type ? class_basename($a->subject_type) : null,
                'ip' => $a->ip,
                'properties' => $a->properties,
            ]),
            'filters' => $filters,
            'userOptions' => User::orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'actionOptions' => collect(self::ACTION_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }
}
