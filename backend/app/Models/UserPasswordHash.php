<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPasswordHash extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'password_hash', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
