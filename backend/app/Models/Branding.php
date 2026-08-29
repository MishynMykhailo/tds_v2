<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branding extends Model
{
    // Eloquent's default naming would pluralize this to "brandings" — the
    // real table (mirroring legacy `tds_branding`) is singular.
    protected $table = 'branding';

    public $timestamps = false;

    protected $fillable = ['logo', 'favicon'];
}
