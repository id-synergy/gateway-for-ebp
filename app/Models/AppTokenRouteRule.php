<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle d'accès d'un jeton d'app : un motif de route EBP + les verbes HTTP autorisés.
 *
 * Un jeton sans règle garde l'accès global ; dès qu'il a une règle, il est restreint à
 * l'union de ses règles (voir RouteRuleMatcher et AuthenticateAppToken).
 */
class AppTokenRouteRule extends Model
{
    protected $fillable = [
        'app_token_id',
        'methods',
        'pattern',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'methods' => 'array',
            'position' => 'integer',
        ];
    }

    public function appToken(): BelongsTo
    {
        return $this->belongsTo(AppToken::class);
    }
}
