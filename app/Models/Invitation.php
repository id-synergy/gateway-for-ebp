<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitation à créer un compte administrateur : lien à durée limitée (24 h), usage unique.
 * Le token est stocké haché ; sa valeur en clair ne vit que dans le lien envoyé par mail.
 */
class Invitation extends Model
{
    protected $fillable = [
        'name',
        'email',
        'token',
        'is_default_contact',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default_contact' => 'boolean',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Hache un token en clair pour le comparer à la valeur stockée. */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** L'invitation est-elle encore utilisable (non acceptée et non expirée) ? */
    public function isValid(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    /** Invitations en attente : ni acceptées, ni expirées. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', Carbon::now());
    }
}
