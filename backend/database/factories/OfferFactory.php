<?php

namespace Database\Factories;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 *
 * Mirrors legacy `tds_offers` defaults (see
 * docs/legacy-reference/frontend/backend_api_reference.md §10.3 and the
 * `2025_01_01_000004_create_offers_table` migration this factory is built
 * against). Style matches database/factories/CampaignFactory.php /
 * StreamFactory.php.
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'group_id' => 0,
            'action_payload' => null,
            'affiliate_network_id' => null,
            'payout_value' => fake()->randomFloat(4, 0, 100),
            'payout_currency' => 'USD',
            'payout_type' => fake()->randomElement(['CPA', 'CPC', 'RevShare']),
            'state' => 'active',
            'payout_auto' => false,
            'payout_upsell' => false,
            'country' => fake()->countryCode(),
            'notes' => null,
            // action_options is a JSON-encoded string column (not a native
            // JSON cast on App\Models\Offer) — see §10.4 "на модели остаётся
            // action_options = {"folder": "..."}" for the local_file case.
            'action_options' => null,
            'action_type' => 'http',
            'offer_type' => 'external',
            'url' => fake()->url(),
            'conversion_cap_enabled' => false,
            'daily_cap' => 0,
            'conversion_timezone' => 'UTC',
            'alternative_offer_id' => null,
        ];
    }

    /**
     * Archived offer (soft "trash" state, not physically deleted — same
     * convention as Campaign/Stream).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }

    /**
     * Disabled/paused offer.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'disabled',
        ]);
    }

    /**
     * Local (uploaded archive) offer instead of an external URL-redirect
     * offer — see §10.3/§10.4 ("если action_type == 'local_file'" branches
     * of OfferSerializer / ActionableResourceTrait).
     */
    public function local(): static
    {
        return $this->state(fn (array $attributes) => [
            'offer_type' => 'local',
            'action_type' => 'local_file',
            'url' => null,
            'action_options' => json_encode(['folder' => fake()->regexify('[a-z0-9]{12}')]),
        ]);
    }

    /**
     * Offer with conversion cap enabled, for exercising
     * OfferSerializer's `conversion_cap` field / daily-cap logic.
     */
    public function withConversionCap(): static
    {
        return $this->state(fn (array $attributes) => [
            'conversion_cap_enabled' => true,
            'daily_cap' => fake()->numberBetween(1, 500),
        ]);
    }
}
