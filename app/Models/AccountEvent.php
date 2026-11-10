<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un événement du cycle de vie d'un compte EBP (connexion, renouvellement de token,
 * refus, synchronisation de dossiers, action sur un jeton d'app…).
 */
class AccountEvent extends Model
{
    /** @use HasFactory<\Database\Factories\AccountEventFactory> */
    use HasFactory;

    protected $fillable = [
        'ebp_account_id',
        'type',
        'level',
        'message',
        'context',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EbpAccount::class, 'ebp_account_id');
    }
}
