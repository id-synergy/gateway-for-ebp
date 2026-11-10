<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EbpFolder extends Model
{
    /** @use HasFactory<\Database\Factories\EbpFolderFactory> */
    use HasFactory;

    protected $fillable = [
        'ebp_account_id',
        'folder_id',
        'short_name',
        'label',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EbpAccount::class, 'ebp_account_id');
    }
}
