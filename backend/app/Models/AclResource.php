<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AclResource extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'resources'];

    protected $casts = [
        'resources' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
