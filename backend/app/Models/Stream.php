<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stream extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'name', 'campaign_id', 'group_id', 'position', 'action_options',
        'comments', 'state', 'action_type', 'action_payload', 'schema',
        'collect_clicks', 'filter_or', 'weight', 'chance',
    ];

    protected $casts = [
        'collect_clicks' => 'boolean',
        'filter_or' => 'boolean',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * `stream_filters` rows (App\Models\StreamFilter) — legacy
     * `Traffic\Model\StreamFilter` (see docs/legacy-reference/frontend/api/
     * 10.2_streams.md, "StreamFilters"). Serialized by
     * StreamsController::serializeStream() / assigned by
     * StreamsController::assignStreamFilters().
     */
    public function filters()
    {
        return $this->hasMany(StreamFilter::class);
    }

    /**
     * `triggers` rows (App\Models\Trigger) — legacy
     * `Component\Triggers\Model\TriggerAssociation`. Serialized by
     * StreamsController::serializeStream() / assigned by
     * TriggersController::assignTriggers().
     */
    public function triggers()
    {
        return $this->hasMany(Trigger::class);
    }

    /**
     * `stream_landing_associations` rows (App\Models\StreamLandingAssociation)
     * — legacy `Traffic\Model\StreamLandingAssociation`. Serialized by
     * StreamsController::serializeStream() / assigned by
     * StreamsController::assignStreamLandings().
     */
    public function landings()
    {
        return $this->hasMany(StreamLandingAssociation::class);
    }

    /**
     * `stream_offer_associations` rows (App\Models\StreamOfferAssociation) —
     * legacy `Traffic\Model\StreamOfferAssociation`. Serialized by
     * StreamsController::serializeStream() / assigned by
     * StreamsController::assignStreamOffers().
     */
    public function offers()
    {
        return $this->hasMany(StreamOfferAssociation::class);
    }
}
