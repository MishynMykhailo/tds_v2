<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Stream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stream>
 */
class StreamFactory extends Factory
{
    protected $model = Stream::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['regular', 'forced', 'default']),
            'name' => fake()->words(2, true),
            // App\Models\Campaign does use HasFactory (unlike the note in
            // CampaignsTest.php, which is about the state of things when
            // that file was written), but we go through CampaignFactory
            // directly here anyway to match this codebase's established
            // factory style (see database/factories/CampaignFactory.php).
            'campaign_id' => CampaignFactory::new()->create()->id,
            'group_id' => 0,
            'position' => fake()->numberBetween(1, 999),
            'action_options' => null,
            'comments' => null,
            'state' => 'active',
            'action_type' => null,
            'action_payload' => null,
            // Traffic\Model\BaseStream schemas (see
            // docs/legacy-reference/frontend/backend_api_reference.md
            // §10.2): LANDINGS (default) / REDIRECT / ACTION.
            'schema' => 'LANDINGS',
            'collect_clicks' => true,
            'filter_or' => false,
            'weight' => fake()->numberBetween(1, 100),
            'chance' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * Archived stream (legacy `deleteAction` archives, never physically
     * deletes — see §10.2 "на самом деле архивирует").
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }

    /**
     * Disabled/paused stream.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'disabled',
        ]);
    }

    /**
     * Attach the stream to a specific (already-created) campaign instead of
     * spinning up a brand new one.
     */
    public function forCampaign(Campaign|int $campaign): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaign instanceof Campaign ? $campaign->id : $campaign,
        ]);
    }
}
