<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrafficSource extends Model
{
    use HasFactory;

    protected $table = 'traffic_sources';

    protected $fillable = [
        'name', 'postback_url', 'postback_statuses', 'template_name',
        'accept_parameters', 'parameters', 'state', 'notes', 'traffic_loss',
    ];

    protected $casts = [
        'accept_parameters' => 'boolean',
    ];
}
