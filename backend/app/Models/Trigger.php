<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Trigger extends Model
{
    use HasFactory;

    /**
     * Legacy `triggers` (see database/migrations/
     * 2025_01_01_000009_create_triggers_table.php) has no created_at/
     * updated_at columns — Eloquent's default $timestamps=true would
     * otherwise inject them into every INSERT/UPDATE and fail with "no
     * such column: updated_at".
     */
    public $timestamps = false;

    protected $fillable = [
        'stream_id', 'target', 'condition', 'selected_page', 'pattern',
        'action', 'interval', 'next_run_at', 'alternative_urls',
        'grab_from_page', 'av_settings', 'reverse', 'enabled', 'scan_page',
    ];

    protected $casts = [
        'reverse' => 'boolean',
        'enabled' => 'boolean',
        'scan_page' => 'boolean',
    ];

    public function stream()
    {
        return $this->belongsTo(Stream::class);
    }
}
