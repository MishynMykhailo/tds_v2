<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FavouriteReport extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['name', 'user_id', 'is_shared', 'payload'];

    protected $casts = [
        'is_shared' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
