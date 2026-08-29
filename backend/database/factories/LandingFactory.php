<?php

namespace Database\Factories;

use App\Models\Landing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Landing>
 *
 * Mirrors legacy `tds_landings` defaults (see
 * docs/legacy-reference/frontend/backend_api_reference.md §10.4 and the
 * `2025_01_01_000005_create_landings_table` migration this factory is built
 * against). Style matches database/factories/CampaignFactory.php /
 * StreamFactory.php / OfferFactory.php.
 */
class LandingFactory extends Factory
{
    protected $model = Landing::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'action_payload' => null,
            'group_id' => 0,
            'offer_count' => 1,
            'state' => 'active',
            'landing_type' => 'external',
            'notes' => null,
            // action_options is a JSON-encoded string column (not a native
            // JSON cast on App\Models\Landing) — see §10.4 "на модели
            // остаётся action_options = {"folder": "..."}" for the
            // local-archive case.
            'action_options' => null,
            'action_type' => 'http',
            'url' => fake()->url(),
        ];
    }

    /**
     * Archived landing (soft "trash" state, not physically deleted — same
     * convention as Campaign/Stream/Offer).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }

    /**
     * Disabled/paused landing.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'disabled',
        ]);
    }

    /**
     * Local (uploaded ZIP archive) landing instead of an external
     * URL-redirect landing — see §10.4 (LocalFile / ActionableResourceTrait,
     * `action_type == "local_file"` branches of LandingSerializer).
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'landing_type' => 'local',
            'action_type' => 'local_file',
            'url' => null,
            'action_options' => json_encode(['folder' => fake()->regexify('[a-z0-9]{12}')]),
        ]);
    }
}
