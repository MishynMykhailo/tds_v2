<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'is_ssl', 'network_status', 'default_campaign_id', 'state',
        'wildcard', 'catch_not_found', 'notes', 'error_description',
        'ssl_status', 'redirect', 'ssl_data', 'is_robots_allowed',
        'next_check_at', 'ssl_redirect', 'allow_indexing', 'check_retries',
    ];

    protected $casts = [
        'is_ssl' => 'boolean',
        'wildcard' => 'boolean',
        'catch_not_found' => 'boolean',
        'is_robots_allowed' => 'boolean',
        'ssl_redirect' => 'boolean',
        'allow_indexing' => 'boolean',
        'next_check_at' => 'datetime',
    ];
}
