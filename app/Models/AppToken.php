<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppToken extends Model
{
    protected $fillable = [
        'name',
        'token_hash',
        'ebp_account_id',
        'default_folder_id',
        'is_active',
        'revoked_at',
        'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EbpAccount::class, 'ebp_account_id');
    }

    public function defaultFolder(): BelongsTo
    {
        return $this->belongsTo(EbpFolder::class, 'default_folder_id');
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }
}
