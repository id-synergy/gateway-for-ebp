<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertLog extends Model
{
    protected $fillable = [
        'alert_type',
        'subject_ref',
        'recipients',
        'sent_at',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'context' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
