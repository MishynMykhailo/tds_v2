<?php

namespace Database\Factories;

use App\Models\AffiliateNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateNetwork>
 *
 * Mirrors legacy `tds_affiliate_networks` defaults (see
 * `application/data/schema.sql` and the
 * `2025_01_01_000015_create_affiliate_networks_table` migration this
 * factory is built against). Style matches
 * database/factories/TrafficSourceFactory.php.
 */
class AffiliateNetworkFactory extends Factory
{
    protected $model = AffiliateNetwork::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'postback_url' => fake()->url(),
            'offer_param' => 'clickid',
            'state' => 'active',
            'template_name' => null,
            'notes' => null,
            // JSON-encoded string column (not a native JSON cast on
            // App\Models\AffiliateNetwork) — see
            // AffiliateNetworksController's decodeJsonField()/
            // encodeJsonFieldForWrite().
            'pull_api_options' => json_encode([]),
        ];
    }

    /**
     * Archived affiliate network (soft "trash" state, not physically
     * deleted — same convention as Campaign/Stream/Offer/Landing/
     * TrafficSource).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }
}
