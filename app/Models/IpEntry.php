<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrée unique de gestion d'IP (nommage + liste blanche + liste noire).
 *  - allowed : exemptée du rate-limit applicatif ;
 *  - blocked : rejetée en 403 par BlockBannedIps ;
 *  - neutral : seulement nommée.
 * Le label survit aux changements de statut.
 */
class IpEntry extends Model
{
    public const STATUS_ALLOWED = 'allowed';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NEUTRAL = 'neutral';

    public const STATUSES = [
        self::STATUS_ALLOWED,
        self::STATUS_BLOCKED,
        self::STATUS_NEUTRAL,
    ];

    protected $fillable = [
        'ip',
        'label',
        'status',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
