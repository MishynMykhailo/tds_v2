<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    public const LANGUAGE = 'language';

    public $timestamps = false;

    protected $fillable = ['user_id', 'pref_name', 'pref_value'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
