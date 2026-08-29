<?php

namespace App\Models\RefDictionary;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared base for the 9 identically-shaped `ref_*` dictionary tables (see
 * migration 2025_01_01_000017_create_ref_dictionary_tables.php).
 */
abstract class AbstractRefValue extends Model
{
    public $timestamps = false;

    protected $fillable = ['value'];

    /** Find-or-create by string value — the normal way these get written (string interning). */
    public static function idFor(string $value): int
    {
        return static::query()->firstOrCreate(['value' => $value])->id;
    }
}
