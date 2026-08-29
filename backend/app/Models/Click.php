<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Click extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'click_id';

    protected $fillable = [
        'visitor_id', 'sub_id', 'ts_id', 'landing_id', 'landing_clicked',
        'landing_clicked_datetime', 'offer_id', 'affiliate_network_id',
        'datetime', 'campaign_id', 'parent_campaign_id', 'stream_id',
        'is_unique_stream', 'is_unique_campaign', 'is_unique_global',
        'is_bot', 'is_using_proxy', 'is_empty_referrer', 'source_id',
        'referrer_id', 'search_engine_id', 'keyword_id',
        'sub_id_1_id', 'sub_id_2_id', 'sub_id_3_id', 'sub_id_4_id', 'sub_id_5_id',
        'sub_id_6_id', 'sub_id_7_id', 'sub_id_8_id', 'sub_id_9_id', 'sub_id_10_id',
        'sub_id_11_id', 'sub_id_12_id', 'sub_id_13_id', 'sub_id_14_id', 'sub_id_15_id',
        'extra_param_1', 'extra_param_2', 'extra_param_3', 'extra_param_4', 'extra_param_5',
        'extra_param_6', 'extra_param_7', 'extra_param_8', 'extra_param_9', 'extra_param_10',
        'lead_revenue', 'rejected_revenue', 'sale_revenue', 'cost', 'is_lead',
        'is_sale', 'is_rejected', 'rebills', 'destination_id', 'creative_id_id',
        'external_id_id', 'goal_1', 'goal_1_datetime', 'goal_2', 'goal_2_datetime',
        'goal_3', 'goal_3_datetime', 'goal_4', 'goal_4_datetime',
        'ad_campaign_id_id', 'x_requested_with_id',
    ];

    protected $casts = [
        'landing_clicked' => 'boolean',
        'is_unique_stream' => 'boolean',
        'is_unique_campaign' => 'boolean',
        'is_unique_global' => 'boolean',
        'is_bot' => 'boolean',
        'is_using_proxy' => 'boolean',
        'is_empty_referrer' => 'boolean',
        'is_lead' => 'boolean',
        'is_sale' => 'boolean',
        'is_rejected' => 'boolean',
        'goal_1' => 'boolean',
        'goal_2' => 'boolean',
        'goal_3' => 'boolean',
        'goal_4' => 'boolean',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }
}
