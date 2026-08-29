<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Landing extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'action_payload', 'group_id', 'offer_count', 'state',
        'landing_type', 'notes', 'action_options', 'action_type', 'url',
    ];
}
