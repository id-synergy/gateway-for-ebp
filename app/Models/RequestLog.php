<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestLog extends Model
{
    protected $fillable = [
        'occurred_at',
        'app_token_id',
        'ebp_account_id',
        'ebp_folder_id',
        'gateway_job_id',
        'ip',
        'method',
        'gateway_route',
        'ebp_endpoint',
        'params',
        'http_status',
        'duration_ms',
        'success',
        'response_size',
        'response_body_path',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'params' => 'array',
            'success' => 'boolean',
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
