<?php

namespace App\Models;

use Database\Factories\AdminActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une action d'administration du back-office : création, modification ou suppression
 * d'une ressource sensible (compte EBP, jeton d'app, IP, admin, invitation…).
 *
 * 3e registre d'audit, distinct de :
 *  - RequestLog      (trafic des apps clientes vers EBP) ;
 *  - AdminAuthEvent  (connexions/déconnexions des admins).
 *
 * On ne stocke JAMAIS de secret dans `properties` (jeton en clair, mot de passe,
 * client_secret, subscription key). Le `label` est figé à l'instant de l'action,
 * car la ressource visée peut être supprimée ensuite.
 */
class AdminAction extends Model
{
    /** @use HasFactory<AdminActionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'label',
        'properties',
        'ip',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** L'admin auteur de l'action (null si le compte a été supprimé depuis). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** La ressource visée (polymorphe, nullable). */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
