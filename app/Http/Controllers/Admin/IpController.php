<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpEntry;
use App\Models\RequestLog;
use App\Services\Security\IpAccessControl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page « IP » unifiée : liste blanche, liste noire, et IP vues (request_logs).
 * Le nommage (label) est éditable pour n'importe quelle IP, quel que soit son statut.
 */
class IpController extends Controller
{
    public function __construct(private IpAccessControl $ipAccess) {}

    public function index(): Response
    {
        $entries = IpEntry::with('creator')->orderByDesc('updated_at')->get();

        $map = fn ($e) => [
            'id' => $e->id,
            'ip' => $e->ip,
            'label' => $e->label,
            'status' => $e->status,
            'by' => $e->creator?->name ?? '—',
            'at' => $e->created_at->format('d/m/Y'),
        ];

        // IP distinctes vues dans les logs : fréquence, première/dernière vue, app associée.
        $seen = RequestLog::query()
            ->whereNotNull('ip')
            ->selectRaw('ip, COUNT(*) as hits, MIN(occurred_at) as first_seen, MAX(occurred_at) as last_seen')
            ->groupBy('ip')
            ->orderByDesc('hits')
            ->limit(200)
            ->get();

        // App la plus récente par IP (nom du jeton), pour contextualiser.
        $appByIp = RequestLog::query()
            ->whereNotNull('ip')
            ->with('appToken:id,name')
            ->orderByDesc('occurred_at')
            ->get(['ip', 'app_token_id', 'occurred_at'])
            ->groupBy('ip')
            ->map(fn ($rows) => $rows->first()->appToken?->name);

        // Statut/label déjà classés, indexés par IP.
        $byIp = $entries->keyBy('ip');

        return Inertia::render('Admin/Ip', [
            'allowed' => $entries->where('status', IpEntry::STATUS_ALLOWED)->values()->map($map),
            'blocked' => $entries->where('status', IpEntry::STATUS_BLOCKED)->values()->map($map),
            'seen' => $seen->map(fn ($r) => [
                'ip' => $r->ip,
                'hits' => (int) $r->hits,
                'firstSeen' => optional($r->first_seen)->format('d/m/Y H:i'),
                'lastSeen' => optional($r->last_seen)->format('d/m/Y H:i'),
                'app' => $appByIp[$r->ip] ?? null,
                'status' => $byIp[$r->ip]->status ?? IpEntry::STATUS_NEUTRAL,
                'label' => $byIp[$r->ip]->label ?? null,
                'id' => $byIp[$r->ip]->id ?? null,
            ]),
        ]);
    }

    /**
     * Crée ou met à jour une entrée IP (upsert sur l'IP) : pose le statut et/ou le label.
     * Le label survit aux changements de statut (on ne l'écrase pas s'il n'est pas fourni).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
            'label' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(IpEntry::STATUSES)],
        ]);

        $entry = IpEntry::firstOrNew(['ip' => $data['ip']]);
        $entry->status = $data['status'];
        if (array_key_exists('label', $data)) {
            $entry->label = $data['label'];
        }
        $entry->created_by ??= $request->user()->id;
        $entry->save();

        $this->ipAccess->forget($entry->ip);

        return back()->with('success', $this->message($entry));
    }

    /**
     * Met à jour une entrée existante (label et/ou statut). Sert aux actions rapides
     * (Nommer / Autoriser / Bloquer) depuis n'importe quel bloc de la page.
     */
    public function update(Request $request, IpEntry $ip): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(IpEntry::STATUSES)],
        ]);

        if (array_key_exists('label', $data)) {
            $ip->label = $data['label'];
        }
        if (array_key_exists('status', $data)) {
            $ip->status = $data['status'];
        }
        $ip->save();

        $this->ipAccess->forget($ip->ip);

        return back()->with('success', $this->message($ip));
    }

    /**
     * Retire le classement d'une IP (repasse en neutral) sans perdre son label,
     * ou supprime l'entrée si elle n'a pas de label.
     */
    public function destroy(IpEntry $ip): RedirectResponse
    {
        $ipAddr = $ip->ip;

        if ($ip->label !== null && $ip->label !== '') {
            $ip->status = IpEntry::STATUS_NEUTRAL;
            $ip->save();
            $message = "L'IP {$ipAddr} n'est plus classée (son libellé est conservé).";
        } else {
            $ip->delete();
            $message = "L'IP {$ipAddr} a été retirée.";
        }

        $this->ipAccess->forget($ipAddr);

        return back()->with('success', $message);
    }

    private function message(IpEntry $entry): string
    {
        return match ($entry->status) {
            IpEntry::STATUS_ALLOWED => "L'IP {$entry->ip} est autorisée (débit illimité).",
            IpEntry::STATUS_BLOCKED => "L'IP {$entry->ip} est bloquée (403).",
            default => "L'IP {$entry->ip} est enregistrée.",
        };
    }
}
