<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Port of legacy `Component\GeoProfiles\Model\GeoProfile`
 * (application/Component/GeoProfiles/Model/GeoProfile.php). Table
 * `country_profiles` (legacy table name, no "geo_profiles" alias exists).
 */
class GeoProfile extends Model
{
    protected $table = 'country_profiles';

    public $timestamps = false;

    protected $fillable = ['name', 'countries'];

    /** @return array<int, string> */
    public function countryCodes(): array
    {
        $countries = $this->countries;

        if (empty($countries)) {
            return [];
        }

        return is_array($countries) ? $countries : preg_split('/\s+/', trim($countries));
    }
}
