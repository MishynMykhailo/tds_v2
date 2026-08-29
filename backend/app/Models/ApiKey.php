<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['key', 'user_id', 'datetime'];

    protected $casts = [
        'datetime' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
