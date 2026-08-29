<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'group_id', 'action_payload', 'affiliate_network_id',
        'payout_value', 'payout_currency', 'payout_type', 'state',
        'payout_auto', 'payout_upsell', 'country', 'notes', 'action_options',
        'action_type', 'offer_type', 'url', 'conversion_cap_enabled',
        'daily_cap', 'conversion_timezone', 'alternative_offer_id',
    ];

    protected $casts = [
        'payout_auto' => 'boolean',
        'payout_upsell' => 'boolean',
        'conversion_cap_enabled' => 'boolean',
    ];
}
