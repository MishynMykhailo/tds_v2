<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AffiliateNetwork extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'postback_url', 'offer_param', 'state', 'template_name',
        'notes', 'pull_api_options',
    ];
}
