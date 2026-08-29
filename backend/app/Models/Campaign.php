<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'alias', 'name', 'type', 'uniqueness_method', 'cookies_ttl',
        'action_type', 'action_payload', 'action_for_bots', 'bot_redirect_url',
        'bot_text', 'action_tracking_disabled', 'position', 'state', 'mode',
        'cost_type', 'cost_value', 'cost_currency', 'group_id', 'bind_visitors',
        'traffic_source_id', 'token', 'cost_auto', 'domain_id', 'notes',
        'parameters', 'uniqueness_use_cookies', 'traffic_loss',
    ];

    protected $casts = [
        'parameters' => 'array',
        'action_tracking_disabled' => 'boolean',
        'cost_auto' => 'boolean',
        'uniqueness_use_cookies' => 'boolean',
    ];
}
