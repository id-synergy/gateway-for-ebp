<?php

namespace App\Models;

use Database\Factories\AdminAuthEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un événement d'authentification d'un ADMIN du back-office :
 * connexion, déconnexion, échec, passage/échec 2FA, expiration de session.
 *
 * À ne pas confondre avec RequestLog, qui trace le trafic des apps clientes vers EBP.
 * On ne stocke JAMAIS de secret (mot de passe, code 2FA) : au plus l'e-mail saisi.
 */
class AdminAuthEvent extends Model
{
    /** @use HasFactory<AdminAuthEventFactory> */
    use HasFactory;

    public const EVENT_LOGIN = 'login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_FAILED = 'failed';

    public const EVENT_TWO_FACTOR_PASSED = 'two_factor_passed';

    public const EVENT_TWO_FACTOR_FAILED = 'two_factor_failed';

    public const EVENT_SESSION_EXPIRED = 'session_expired';

    protected $fillable = [
        'user_id',
        'email',
        'event',
        'ip',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
