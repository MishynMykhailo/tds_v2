<?php

namespace Database\Factories;

use App\Models\TrafficSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrafficSource>
 *
 * Mirrors legacy `tds_traffic_sources` defaults (see
 * docs/legacy-reference/frontend/api/10.6_trafficsources.md and the
 * `2025_01_01_000006_create_traffic_sources_table` migration this factory is
 * built against). Style matches database/factories/OfferFactory.php /
 * LandingFactory.php.
 */
class TrafficSourceFactory extends Factory
{
    protected $model = TrafficSource::class;

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
            // JSON-encoded string column (not a native JSON cast on
            // App\Models\TrafficSource) — see TrafficSourcesController's
            // decodeJsonField()/encodeJsonFieldForWrite().
            'postback_statuses' => json_encode(['sale', 'lead', 'rejected', 'rebill']),
            'template_name' => null,
            'accept_parameters' => true,
            'parameters' => json_encode([]),
            'state' => 'active',
            'notes' => null,
            'traffic_loss' => 0,
        ];
    }

    /**
     * Archived traffic source (soft "trash" state, not physically deleted —
     * same convention as Campaign/Stream/Offer/Landing).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }
}
