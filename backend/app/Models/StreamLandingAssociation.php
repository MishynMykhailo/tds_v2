<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Legacy `Traffic\Model\StreamLandingAssociation` /
 * `stream_landing_associations` (see database/migrations/
 * 2025_01_01_000027_create_stream_landing_associations_table.php).
 */
class StreamLandingAssociation extends Model
{
    protected $fillable = ['stream_id', 'landing_id', 'state', 'share'];

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }

    public function landing()
    {
        return $this->belongsTo(Landing::class);
    }
}
