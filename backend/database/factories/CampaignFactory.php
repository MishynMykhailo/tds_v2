<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alias' => fake()->unique()->regexify('[a-z0-9]{8}'),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(['weight', 'redirect', 'geo']),
            'uniqueness_method' => 'ip_ua',
            'cookies_ttl' => 0,
            'action_type' => 0,
            'action_payload' => null,
            'action_for_bots' => '404',
            'bot_redirect_url' => null,
            'bot_text' => null,
            'action_tracking_disabled' => false,
            'position' => fake()->numberBetween(1, 9999),
            'state' => 'active',
            'mode' => 'general',
            // Legacy cost type "CPV" is deliberately excluded here — the
            // CampaignSerializer remaps CPV -> CPC on the fly (see
            // docs/legacy-reference/frontend/backend_api_reference.md
            // §10.1). Use the withCpvCostType() state below to exercise
            // that remapping in a test.
            'cost_type' => fake()->randomElement(['CPC', 'CPA', 'CPM']),
            'cost_value' => fake()->randomFloat(4, 0, 50),
            'cost_currency' => 'USD',
            'group_id' => 0,
            'bind_visitors' => null,
            'traffic_source_id' => 0,
            'token' => Str::random(32),
            'cost_auto' => true,
            'domain_id' => 0,
            'notes' => null,
            'parameters' => [],
            'uniqueness_use_cookies' => true,
            'traffic_loss' => 0,
        ];
    }

    /**
     * Force the legacy "CPV" cost type, used to test that the API remaps
     * it to "CPC" on output (never exposes raw "CPV").
     */
    public function withCpvCostType(): static
    {
        return $this->state(fn (array $attributes) => [
            'cost_type' => 'CPV',
        ]);
    }

    /**
     * Archived campaign (soft "trash" state, not physically deleted).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }

    /**
     * Disabled/paused campaign.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'disabled',
        ]);
    }
}
