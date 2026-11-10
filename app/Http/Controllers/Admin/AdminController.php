<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertLog;
use App\Models\AllowedIp;
use App\Models\AppToken;
use App\Models\EbpAccount;
use App\Models\GatewayJob;
use App\Models\NotificationPreference;
use App\Models\RequestLog;
use App\Models\User;
use App\Services\Ebp\QuotaRegulator;
use Illuminate\Http\Request;
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
        $alertLabels = [
            'token_refresh_failed' => 'Échec renouvellement token',
            'logs_purge_failed' => 'Échec purge des logs',
            'account_expiring' => 'Compte bientôt expiré',
            'generic_incident' => 'Incident générique',
        ];

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
            ],
            'folders' => $account->folders->map(fn ($f) => [
                'id' => $f->folder_id, 'short' => $f->short_name, 'label' => $f->label, 'active' => $f->is_active,
            ]),
            'tokens' => $account->appTokens->map(fn ($t) => [
                'name' => $t->name, 'active' => $t->isUsable(),
                'lastUsed' => optional($t->last_used_at)->diffForHumans() ?? 'jamais',
            ]),
            'callCount' => RequestLog::where('ebp_account_id', $account->id)->count(),
        ]);
    }

    public function jetons(): Response
    {
        return Inertia::render('Admin/Jetons', [
            'tokens' => AppToken::with(['account', 'defaultFolder'])->orderByDesc('created_at')->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'account' => $t->account?->label ?? '—',
                'folder' => $t->defaultFolder ? ($t->defaultFolder->short_name.' #'.$t->defaultFolder->folder_id) : '— (par requête)',
                'lastUsed' => optional($t->last_used_at)->diffForHumans() ?? 'jamais',
                'active' => $t->isUsable(),
            ]),
            'accounts' => EbpAccount::with('folders')->get()->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label,
                'folders' => $a->folders->map(fn ($f) => ['id' => $f->id, 'label' => $f->label ?? $f->folder_id]),
            ]),
        ]);
    }

    public function admins(): Response
    {
        $types = ['token_refresh_failed', 'logs_purge_failed', 'account_expiring', 'generic_incident'];
        $labels = [
            'token_refresh_failed' => 'Échec renouvellement token',
            'logs_purge_failed' => 'Échec purge des logs',
            'account_expiring' => 'Compte bientôt expiré',
            'generic_incident' => 'Incident générique',
        ];

        $users = User::with('notificationPreferences')->get();

        return Inertia::render('Admin/Admins', [
            'admins' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'twoFa' => $u->two_factor_confirmed_at !== null,
                'defaultContact' => $u->is_default_contact,
                'active' => $u->is_active,
            ]),
            'preferences' => collect($types)->map(fn ($type) => [
                'type' => $type,
                'label' => $labels[$type],
                'byUser' => $users->mapWithKeys(fn ($u) => [
                    $u->id => (bool) optional($u->notificationPreferences->firstWhere('alert_type', $type))->enabled,
                ]),
            ]),
        ]);
    }

    public function ip(): Response
    {
        return Inertia::render('Admin/Ip', [
            'ips' => AllowedIp::with('creator')->orderByDesc('created_at')->get()->map(fn ($i) => [
                'id' => $i->id,
                'ip' => $i->ip,
                'label' => $i->label,
                'by' => $i->creator?->name ?? '—',
                'at' => $i->created_at->format('d/m/Y'),
            ]),
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
        if ($log->response_body_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($log->response_body_path)) {
            $raw = \Illuminate\Support\Facades\Storage::disk('local')->get($log->response_body_path);
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
            ],
            'body' => $body,
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
        if ($job->result_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($job->result_path)) {
            return json_decode(\Illuminate\Support\Facades\Storage::disk('local')->get($job->result_path), true);
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
