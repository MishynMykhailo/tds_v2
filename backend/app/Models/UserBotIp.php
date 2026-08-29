<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotIp extends Model
{
    public $timestamps = false;

    protected $fillable = ['min_ip', 'max_ip', 'raw_value'];
}
