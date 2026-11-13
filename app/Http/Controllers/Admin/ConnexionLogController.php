<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuthEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Journal de connexion des admins du back-office : qui se connecte, quand, d'où,
 * et quand il se déconnecte (table admin_auth_events).
 */
class ConnexionLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'user' => $request->integer('user') ?: null,
            'event' => $request->string('event')->toString(), // '' | login | logout | failed | …
            'ip' => $request->string('ip')->toString(),
        ];

        $query = AdminAuthEvent::with('user')->orderByDesc('occurred_at');

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }
        if ($filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }
        if ($filters['ip'] !== '') {
            $query->where('ip', 'like', "%{$filters['ip']}%");
        }

        $events = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/Connexions', [
            'events' => $events->through(fn (AdminAuthEvent $e) => [
                'id' => $e->id,
                'at' => $e->occurred_at->format('d/m/Y H:i:s'),
                'adminName' => $e->user?->name,
                // Pour un échec sans user, on affiche l'e-mail saisi.
                'adminEmail' => $e->user?->email ?? $e->email,
                'event' => $e->event,
                'ip' => $e->ip,
                'userAgent' => $e->user_agent,
            ]),
            'filters' => $filters,
            'userOptions' => User::orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'eventOptions' => [
                ['value' => AdminAuthEvent::EVENT_LOGIN, 'label' => 'Connexion'],
                ['value' => AdminAuthEvent::EVENT_LOGOUT, 'label' => 'Déconnexion'],
                ['value' => AdminAuthEvent::EVENT_FAILED, 'label' => 'Échec'],
                ['value' => AdminAuthEvent::EVENT_TWO_FACTOR_PASSED, 'label' => '2FA validée'],
                ['value' => AdminAuthEvent::EVENT_TWO_FACTOR_FAILED, 'label' => '2FA échouée'],
                ['value' => AdminAuthEvent::EVENT_SESSION_EXPIRED, 'label' => 'Session expirée'],
            ],
        ]);
    }
}
