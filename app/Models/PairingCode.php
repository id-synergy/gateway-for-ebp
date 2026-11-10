<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Code d'appairage à usage unique : transmis par QR à une application, échangé contre le
 * vrai jeton d'app via l'endpoint public. Le QR ne contient que ce code (court-vivant,
 * 10 min, usage unique), jamais le jeton. Le jeton en clair est conservé chiffré, le temps
 * de l'appairage seulement, et détruit à la consommation ou à l'expiration.
 */
class PairingCode extends Model
{
    protected $fillable = [
        'app_token_id',
        'code',
        'encrypted_token',
        'created_by',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code',
        'encrypted_token',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_token' => 'encrypted',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function appToken(): BelongsTo
    {
        return $this->belongsTo(AppToken::class);
    }

    /** Hache un code en clair pour le comparer à la valeur stockée. */
    public static function hashCode(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Le code est-il encore réclamable (non consommé et non expiré) ? */
    public function isValid(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /** Codes encore réclamables. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', Carbon::now());
    }
}
