<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy `Traffic\Model\StreamOfferAssociation` /
 * `stream_offer_associations` (see database/migrations/
 * 2025_01_01_000028_create_stream_offer_associations_table.php).
 */
class StreamOfferAssociation extends Model
{
    protected $fillable = ['stream_id', 'offer_id', 'state', 'share'];

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function offer()
    {
        return $this->belongsTo(Offer::class);
    }
}
