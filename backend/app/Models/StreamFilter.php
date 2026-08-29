<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StreamFilter extends Model
{
    use HasFactory;

    /**
     * Legacy `stream_filters` (see database/migrations/
     * 2025_01_01_000008_create_stream_filters_table.php) has no
     * created_at/updated_at columns — Eloquent's default $timestamps=true
     * would otherwise inject them into every INSERT/UPDATE and fail with
     * "no such column: updated_at".
     */
    public $timestamps = false;

    protected $fillable = ['stream_id', 'name', 'mode', 'payload'];

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }
}
