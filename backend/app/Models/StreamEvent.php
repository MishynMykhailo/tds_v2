<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Maps to table `monitoring_history`, NOT `stream_events` — see the
 * migration's docblock. Table name kept faithful to legacy, not to the API
 * object name.
 */
class StreamEvent extends Model
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const READ = 'read';

    public const UNREAD = 'unread';

    public $timestamps = false;

    protected $table = 'monitoring_history';

    protected $fillable = ['level', 'stream_id', 'trigger_id', 'message', 'date', 'state'];

    protected $casts = [
        'date' => 'datetime',
    ];
}
