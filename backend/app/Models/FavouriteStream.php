<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FavouriteStream extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'stream_id'];
}
