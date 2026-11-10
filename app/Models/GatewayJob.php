<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayJob extends Model
{
    protected $fillable = [
        'public_id',
        'type',
        'app_token_id',
        'ebp_account_id',
        'ebp_folder_id',
        'status',
        'progress_done',
        'progress_total',
        'result_path',
        'result_inline',
        'sub_errors',
        'laravel_batch_id',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'result_inline' => 'array',
            'sub_errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function appToken(): BelongsTo
    {
        return $this->belongsTo(AppToken::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(EbpAccount::class, 'ebp_account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EbpFolder::class, 'ebp_folder_id');
    }
}
