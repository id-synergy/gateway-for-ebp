<?php

namespace App\Models;

use Database\Factories\BlockedRequestEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Trace légère d'une requête refusée en 403 par BlockBannedIps (IP en liste noire).
 *
 * Distinct de RequestLog (trafic apps authentifié vers EBP) : ces tentatives sont
 * bloquées avant tout traitement. Aucun secret n'est stocké (IP + chemin + méthode).
 * Sert à détecter une IP bloquée qui insiste (alerte blocked_ip_hammering).
 */
class BlockedRequestEvent extends Model
{
    /** @use HasFactory<BlockedRequestEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'occurred_at',
        'ip',
        'path',
        'method',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }
}
