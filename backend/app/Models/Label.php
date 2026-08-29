<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Label extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['campaign_id', 'label_name', 'ref_name', 'ref_id', 'ref_value'];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }
}
