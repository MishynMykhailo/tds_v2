<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClickLink extends Model
{
    public $timestamps = false;

    protected $fillable = ['sub_id', 'parent_sub_id'];
}
