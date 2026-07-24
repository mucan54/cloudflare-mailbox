<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingRule extends Model
{
    protected $fillable = [
        'cloudflare_account_id', 'domain_id', 'cf_id', 'name',
        'matcher', 'actions', 'enabled', 'priority', 'is_catch_all',
    ];

    protected function casts(): array
    {
        return [
            'actions' => 'array',
            'enabled' => 'boolean',
            'is_catch_all' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudflareAccount::class, 'cloudflare_account_id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
