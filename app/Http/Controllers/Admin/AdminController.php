<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountEvent;
use App\Models\AdminAction;
use App\Models\AdminAuthEvent;
use App\Models\AlertLog;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\GatewayJob;
use App\Models\Invitation;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\Alerts\AlertType;
use App\Services\Apps\RoutePatternCatalog;
use App\Services\Ebp\QuotaRegulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écrans du back-office (Inertia) alimentés par les vraies données.
 */
class AdminController extends Controller
{
    public function dashboard(): Response
    {
        // Activité des 7 derniers jours (appels par jour).
        $activity = collect(range(6, 0))->map(function ($d) {
            $day = now()->subDays($d);

            return [
                'label' => $day->isoFormat('dd'),
                'count' => RequestLog::whereDate('occurred_at', $day)->count(),
            ];
        });

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'accounts' => EbpAccount::where('status', 'connected')->count(),
                'tokens' => AppToken::where('is_active', true)->count(),
                'jobs' => GatewayJob::whereIn('status', ['pending', 'running'])->count(),
                'calls24h' => RequestLog::where('occurred_at', '>=', now()->subDay())->count(),
            ],
            'accounts' => EbpAccount::with('token')->get()->map(fn ($a) => [
                'label' => $a->label,
                'state' => $a->token?->state ?? 'broken',
                'expires' => $this->expiresLabel($a),
            ]),
            'activity' => $activity,
            'recentCalls' => RequestLog::with(['appToken'])->orderByDesc('occurred_at')->limit(6)->get()->map(fn ($l) => [
                'at' => $l->occurred_at->format('H:i'),
                'app' => $l->appToken?->name ?? '—',
                'route' => $l->ebp_endpoint,
                'status' => $l->http_status,
            ]),
            'errorRate' => $this->errorRate(),
        ]);
    }

    private function errorRate(): int
    {
        $total = RequestLog::where('occurred_at', '>=', now()->subDay())->count();
        if ($total === 0) {
            return 0;
        }
        $errors = RequestLog::where('occurred_at', '>=', now()->subDay())->where('success', false)->count();

        return (int) round($errors / $total * 100);
    }

    public function comptes(): Response
    {
        return Inertia::render('Admin/Comptes', [
            'accounts' => EbpAccount::with(['token', 'folders'])->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'state' => $a->token?->state ?? 'broken',
                'key' => $a->active_subscription_key === 'secondary' ? 'secondaire' : 'primaire',
                'expires' => $this->expiresLabel($a),
                'lastRefresh' => optional($a->token?->last_refreshed_at)->format('d/m/Y H:i') ?? '—',
                'folders' => $a->folders->map(fn ($f) => [
                    'id' => $f->folder_id, 'short' => $f->short_name, 'label' => $f->label,
                ]),
            ]),
        ]);
    }

    public function supervision(QuotaRegulator $quota): Response
    {
        $alertLabels = AlertType::labels();

        return Inertia::render('Admin/Supervision', [
            'queue' => [
                'pending' => GatewayJob::whereIn('status', ['pending', 'running'])->count(),
                'done24h' => GatewayJob::where('status', 'done')->where('finished_at', '>=', now()->subDay())->count(),
                'failed24h' => GatewayJob::whereIn('status', ['failed', 'partial'])->where('finished_at', '>=', now()->subDay())->count(),
            ],
            'quota' => [
                'usedToday' => $quota->usedToday(),
                'perDay' => (int) config('ebp.quota.per_day'),
                'perSecond' => (int) config('ebp.quota.per_second'),
            ],
            'alerts' => AlertLog::orderByDesc('sent_at')->limit(30)->get()->map(fn ($a) => [
                'type' => $alertLabels[$a->alert_type] ?? $a->alert_type,
                'subject' => $a->subject_ref,
                'recipients' => $a->recipients,
                'sentAt' => $a->sent_at->format('d/m/Y H:i'),
            ]),
        ]);
    }

    public function compte(EbpAccount $account): Response
    {
        $account->load(['token', 'folders', 'appTokens']);

        return Inertia::render('Admin/CompteDetail', [
            'account' => [
                'id' => $account->id,
                'label' => $account->label,
                'status' => $account->status,
                'environment' => $account->environment,
                'key' => $account->active_subscription_key === 'secondary' ? 'secondaire' : 'primaire',
                'tokenState' => $account->token?->state ?? 'broken',
                'expires' => $this->expiresLabel($account),
                'lastRefresh' => optional($account->token?->last_refreshed_at)->format('d/m/Y H:i') ?? '—',
                'accessExpires' => optional($account->token?->access_token_expires_at)->format('d/m/Y H:i') ?? '—',
                'lastError' => $account->token?->last_refresh_error,
                'cacheEnabled' => $account->cacheEnabled(),
                'cacheTtl' => $account->cache_ttl,
                'redirectMode' => $account->redirect_mode,
                'customRedirectUri' => $account->custom_redirect_uri,
                'manualCallback' => $account->usesManualCallback(),
                'localhostRedirect' => config('ebp.localhost_redirect_uri'),
            ],
            'folders' => $account->folders->map(fn ($f) => [
                'id' => $f->folder_id, 'short' => $f->short_name, 'label' => $f->label, 'active' => $f->is_active,
            ]),
            'tokens' => $account->appTokens->map(fn ($t) => [
                'name' => $t->name, 'active' => $t->isUsable(),
                'lastUsed' => optional($t->last_used_at)->diffForHumans() ?? 'jamais',
            ]),
            'callCount' => RequestLog::where('ebp_account_id', $account->id)->count(),
            'events' => $this->eventsPage($account),
        ]);
    }

    /** Taille d'une page du journal d'activité (scroll infini). */
    private const EVENTS_PER_PAGE = 20;

    /**
     * Page du journal d'activité d'un compte, pour le chargement initial et le scroll infini.
     * Pagination par curseur : `before` = id du plus ancien événement déjà affiché.
     */
    private function eventsPage(EbpAccount $account, ?int $before = null): array
    {
        $query = $account->events()->getQuery(); // déjà trié par occurred_at desc

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        $rows = $query->limit(self::EVENTS_PER_PAGE + 1)->get();
        $hasMore = $rows->count() > self::EVENTS_PER_PAGE;
        $rows = $rows->take(self::EVENTS_PER_PAGE);

        return [
            'items' => $rows->map(fn (AccountEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'level' => $e->level,
                'message' => $e->message,
                'context' => $e->context,
                'at' => $e->occurred_at->format('d/m/Y H:i:s'),
                'ago' => $e->occurred_at->diffForHumans(),
            ])->values(),
            'hasMore' => $hasMore,
            'nextCursor' => $hasMore ? $rows->last()?->id : null,
        ];
    }

    /** GET /admin/comptes/{account}/events?before=... — JSON pour le scroll infini et l'actualisation. */
    public function accountEvents(Request $request, EbpAccount $account): JsonResponse
    {
        $before = $request->integer('before') ?: null;

        return response()->json($this->eventsPage($account, $before));
    }

    public function jetons(RoutePatternCatalog $catalog): Response
    {
        return Inertia::render('Admin/Jetons', [
            'tokens' => AppToken::with(['account', 'defaultFolder', 'routeRules'])->orderByDesc('created_at')->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'account' => $t->account?->label ?? '—',
                'folder' => $t->defaultFolder ? ($t->defaultFolder->short_name.' #'.$t->defaultFolder->folder_id) : '— (par requête)',
                'lastUsed' => optional($t->last_used_at)->diffForHumans() ?? 'jamais',
                'active' => $t->isUsable(),
                // Règles d'accès : liste vide = jeton « ouvert » (périmètre global).
                'rules' => $t->routeRules->map(fn ($r) => [
                    'methods' => $r->methods,
                    'pattern' => $r->pattern,
                ])->values(),
            ]),
            'accounts' => EbpAccount::with('folders')->get()->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label,
                'folders' => $a->folders->map(fn ($f) => ['id' => $f->id, 'label' => $f->label ?? $f->folder_id]),
            ]),
            // Endpoint de base de la gateway, pour le QR code de connexion des apps.
            'endpoint' => rtrim(url('/'), '/'),
            // Catalogue de patterns connus (groupés par module) pour l'autocomplétion des règles.
            'routeCatalog' => $catalog->suggestions(),
        ]);
    }

    public function admins(): Response
    {
        $types = AlertType::configurable();
        $labels = AlertType::labels();

        $users = User::with('notificationPreferences')->get();

        // Dernière connexion par admin (dernier event login), en une requête groupée.
        $lastLogins = AdminAuthEvent::where('event', AdminAuthEvent::EVENT_LOGIN)
            ->whereNotNull('user_id')
            ->selectRaw('user_id, MAX(occurred_at) as last_login')
            ->groupBy('user_id')
            ->pluck('last_login', 'user_id');

        return Inertia::render('Admin/Admins', [
            'admins' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'twoFa' => $u->two_factor_confirmed_at !== null,
                'defaultContact' => $u->is_default_contact,
                'active' => $u->is_active,
                'lastLoginAt' => isset($lastLogins[$u->id])
                    ? Carbon::parse($lastLogins[$u->id])->format('d/m/Y H:i')
                    : null,
            ]),
            'preferences' => collect($types)->map(fn ($type) => [
                'type' => $type,
                'label' => $labels[$type],
                'byUser' => $users->mapWithKeys(fn ($u) => [
                    $u->id => (bool) optional($u->notificationPreferences->firstWhere('alert_type', $type))->enabled,
                ]),
            ]),
            'invitations' => Invitation::pending()->latest()->get()->map(fn ($i) => [
                'id' => $i->id,
                'name' => $i->name,
                'email' => $i->email,
                'expiresIn' => $i->expires_at->diffForHumans(),
            ]),
        ]);
    }

    /** Nombre de connexions/événements d'auth récents remontés sur la fiche d'un admin. */
    private const ADMIN_AUTH_EVENTS = 30;

    /** Nombre d'actions d'audit récentes remontées sur la fiche d'un admin. */
    private const ADMIN_ACTIONS = 30;

    /**
     * Fiche détail d'un administrateur : profil, ses connexions (admin_auth_events),
     * ses actions (admin_actions) et ses préférences d'alertes — le tout filtré sur
     * ce seul utilisateur. Vue complémentaire des pages globales /admin/connexions
     * et /admin/audit, qui restent en place.
     */
    public function admin(User $user): Response
    {
        $user->load('notificationPreferences');

        $events = AdminAuthEvent::where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->limit(self::ADMIN_AUTH_EVENTS)
            ->get();

        $actions = AdminAction::where('user_id', $user->id)
            ->orderByDesc('occurred_at')
            ->limit(self::ADMIN_ACTIONS)
            ->get();

        $types = AlertType::configurable();
        $labels = AlertType::labels();

        // Résumé en tête : dernière connexion réussie, nombre de connexions récentes, dernière action.
        $lastLogin = AdminAuthEvent::where('user_id', $user->id)
            ->where('event', AdminAuthEvent::EVENT_LOGIN)
            ->orderByDesc('occurred_at')
            ->first();
        $loginCount = AdminAuthEvent::where('user_id', $user->id)
            ->where('event', AdminAuthEvent::EVENT_LOGIN)
            ->count();

        return Inertia::render('Admin/AdminDetail', [
            'admin' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'twoFa' => $user->two_factor_confirmed_at !== null,
                'defaultContact' => $user->is_default_contact,
                'active' => $user->is_active,
                'createdAt' => optional($user->created_at)->format('d/m/Y H:i') ?? '—',
            ],
            'summary' => [
                'lastLoginAt' => optional($lastLogin?->occurred_at)->format('d/m/Y H:i'),
                'lastLoginAgo' => optional($lastLogin?->occurred_at)->diffForHumans(),
                'loginCount' => $loginCount,
                'lastActionLabel' => $actions->first()?->label,
                'lastActionAt' => optional($actions->first()?->occurred_at)->format('d/m/Y H:i'),
            ],
            // Connexions : mêmes types/badges que /admin/connexions.
            'events' => $events->map(fn (AdminAuthEvent $e) => [
                'id' => $e->id,
                'at' => $e->occurred_at->format('d/m/Y H:i:s'),
                'event' => $e->event,
                'ip' => $e->ip,
                'userAgent' => $e->user_agent,
            ])->values(),
            // Actions : mêmes libellés que /admin/audit (source unique AuditController::ACTION_LABELS).
            'actions' => $actions->map(fn (AdminAction $a) => [
                'id' => $a->id,
                'at' => $a->occurred_at->format('d/m/Y H:i:s'),
                'action' => $a->action,
                'actionLabel' => AuditController::ACTION_LABELS[$a->action] ?? $a->action,
                'label' => $a->label,
                'subjectType' => $a->subject_type ? class_basename($a->subject_type) : null,
                'ip' => $a->ip,
                'properties' => $a->properties,
            ])->values(),
            // Préférences d'alertes de cet admin (toggles réutilisant admin.preferences.toggle).
            'preferences' => collect($types)->map(fn ($type) => [
                'type' => $type,
                'label' => $labels[$type],
                'enabled' => (bool) optional($user->notificationPreferences->firstWhere('alert_type', $type))->enabled,
            ])->values(),
        ]);
    }

    public function logs(Request $request): Response
    {
        $filters = [
            'search' => $request->string('search')->toString(),
            'status' => $request->string('status')->toString(), // '', 'success', 'error'
            'account' => $request->integer('account') ?: null,
        ];

        $query = RequestLog::with(['appToken', 'account'])->orderByDesc('occurred_at');

        if ($filters['search'] !== '') {
            $s = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('ebp_endpoint', 'like', "%{$s}%")
                ->orWhere('ip', 'like', "%{$s}%")
                ->orWhereHas('appToken', fn ($t) => $t->where('name', 'like', "%{$s}%")));
        }
        if ($filters['status'] === 'success') {
            $query->where('success', true);
        } elseif ($filters['status'] === 'error') {
            $query->where('success', false);
        }
        if ($filters['account']) {
            $query->where('ebp_account_id', $filters['account']);
        }

        $logs = $query->paginate(25)->withQueryString();

        return Inertia::render('Admin/Logs', [
            'logs' => $logs->through(fn ($l) => [
                'id' => $l->id,
                'at' => $l->occurred_at->format('d/m H:i:s'),
                'app' => $l->appToken?->name ?? '—',
                'account' => $l->account?->label ?? '—',
                'route' => $l->method.' '.$l->ebp_endpoint,
                'status' => $l->http_status,
                'ms' => $l->duration_ms,
                'ip' => $l->ip ?? '—',
            ]),
            'filters' => $filters,
            'accountOptions' => EbpAccount::get(['id', 'label']),
        ]);
    }

    public function jobs(): Response
    {
        return Inertia::render('Admin/Jobs', [
            'jobs' => GatewayJob::with('appToken')->orderByDesc('created_at')->limit(100)->get()->map(fn ($j) => [
                'id' => substr($j->public_id, 0, 8).'…',
                'publicId' => $j->public_id,
                'type' => $j->type,
                'app' => $j->appToken?->name ?? '—',
                'status' => $j->status,
                'done' => $j->progress_done,
                'total' => max(1, $j->progress_total),
                'desc' => $j->type === 'compose' ? 'Requête composite' : ($j->type === 'batch' ? 'Lot de requêtes' : 'Lecture'),
            ]),
        ]);
    }

    public function log(RequestLog $log): Response
    {
        $log->load(['appToken', 'account', 'folder']);

        $body = null;
        if ($log->response_body_path && Storage::disk('local')->exists($log->response_body_path)) {
            $raw = Storage::disk('local')->get($log->response_body_path);
            $decoded = json_decode($raw, true);
            $body = $decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $raw;
        }

        return Inertia::render('Admin/LogDetail', [
            'log' => [
                'at' => $log->occurred_at->format('d/m/Y H:i:s'),
                'app' => $log->appToken?->name ?? '—',
                'account' => $log->account?->label ?? '—',
                'folder' => $log->folder?->folder_id ?? '—',
                'method' => $log->method,
                'endpoint' => $log->ebp_endpoint,
                'status' => $log->http_status,
                'success' => $log->success,
                'durationMs' => $log->duration_ms,
                'ip' => $log->ip,
                'size' => $log->response_size,
                'params' => $log->params,
                // Pour le bouton « Rejouer » : identifiants bruts à repasser au testeur.
                'accountId' => $log->ebp_account_id,
                'folderId' => $log->ebp_folder_id,
            ],
            'body' => $body,
            // Le rejeu n'a de sens que si le compte source existe encore.
            'canReplay' => $log->ebp_account_id !== null && $log->account !== null,
        ]);
    }

    /**
     * Testeur de requêtes EBP : émet de vraies requêtes authentifiées vers EBP (via EbpClient)
     * et affiche la réponse brute, pour le débogage. Le formulaire peut être pré-rempli par
     * les query params (bouton « Rejouer » depuis le détail d'un log).
     */
    public function testeur(Request $request): Response
    {
        return Inertia::render('Admin/Testeur', [
            'accounts' => EbpAccount::with('folders')->orderBy('label')->get()->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'folders' => $a->folders->map(fn ($f) => [
                    'id' => $f->id,
                    'label' => $f->label ? $f->label.' #'.$f->folder_id : $f->folder_id,
                ])->values(),
            ]),
            // Valeurs de pré-remplissage (rejeu). Toutes optionnelles.
            'prefill' => [
                'accountId' => $request->integer('account') ?: null,
                'folderId' => $request->integer('folder') ?: null,
                'method' => $request->string('method')->upper()->toString() ?: null,
                'endpoint' => $request->string('endpoint')->toString() ?: null,
                'query' => $request->has('params') ? (array) $request->input('params') : null,
            ],
        ]);
    }

    public function job(GatewayJob $job): Response
    {
        $job->load(['appToken', 'account', 'folder']);

        return Inertia::render('Admin/JobDetail', [
            'job' => [
                'id' => $job->public_id,
                'type' => $job->type,
                'status' => $job->status,
                'app' => $job->appToken?->name ?? '—',
                'account' => $job->account?->label ?? '—',
                'folder' => $job->folder?->folder_id ?? '—',
                'done' => $job->progress_done,
                'total' => max(1, $job->progress_total),
                'createdAt' => $job->created_at->format('d/m/Y H:i:s'),
                'finishedAt' => optional($job->finished_at)->format('d/m/Y H:i:s'),
                'result' => $this->jobResult($job),
                'errors' => $job->sub_errors,
            ],
        ]);
    }

    private function jobResult(GatewayJob $job): mixed
    {
        if ($job->result_path && Storage::disk('local')->exists($job->result_path)) {
            return json_decode(Storage::disk('local')->get($job->result_path), true);
        }

        return $job->result_inline;
    }

    private function expiresLabel(EbpAccount $account): string
    {
        $exp = $account->token?->refresh_token_expires_at;
        if (! $exp) {
            return '—';
        }
        $days = (int) now()->diffInDays($exp, false);

        return $days >= 0 ? "dans {$days} j" : 'expiré';
    }
}
