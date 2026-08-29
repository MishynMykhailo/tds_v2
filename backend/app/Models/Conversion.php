<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversion extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'conversion_id';

    protected $fillable = [
        'visitor_id', 'campaign_id', 'stream_id', 'ts_id', 'landing_id',
        'offer_id', 'affiliate_network_id', 'sub_id', 'click_id', 'tid',
        'click_datetime', 'postback_datetime', 'status', 'previous_status',
        'original_status', 'source_id', 'referrer_id', 'search_engine_id',
        'keyword_id', 'screen_id',
        'sub_id_1_id', 'sub_id_2_id', 'sub_id_3_id', 'sub_id_4_id', 'sub_id_5_id',
        'sub_id_6_id', 'sub_id_7_id', 'sub_id_8_id', 'sub_id_9_id', 'sub_id_10_id',
        'sub_id_11_id', 'sub_id_12_id', 'sub_id_13_id', 'sub_id_14_id', 'sub_id_15_id',
        'extra_param_1', 'extra_param_2', 'extra_param_3', 'extra_param_4', 'extra_param_5',
        'extra_param_6', 'extra_param_7', 'extra_param_8', 'extra_param_9', 'extra_param_10',
        'revenue', 'cost', 'params', 'is_processed', 'creative_id_id',
        'external_id_id', 'ad_campaign_id_id', 'sale_datetime',
        'x_requested_with_id', 'previous_conversion_id',
    ];

    protected $casts = [
        'is_processed' => 'boolean',
        'params' => 'array',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function click()
    {
        return $this->belongsTo(Click::class, 'click_id', 'click_id');
    }
}
