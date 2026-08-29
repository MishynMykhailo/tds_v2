<?php

namespace Database\Factories;

use App\Models\Stream;
use App\Models\Trigger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trigger>
 */
class TriggerFactory extends Factory
{
    protected $model = Trigger::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stream_id' => StreamFactory::new()->create()->id,
            'target' => 'stream',
            'condition' => 'not_respond',
            'selected_page' => null,
            'pattern' => null,
            'action' => 'disable',
            'interval' => 30,
            'next_run_at' => null,
            'alternative_urls' => null,
            'grab_from_page' => null,
            'av_settings' => null,
            'reverse' => false,
            'enabled' => true,
            'scan_page' => false,
        ];
    }

    /**
     * Attach the trigger to a specific (already-created) stream instead of
     * spinning up a brand new one.
     */
    public function forStream(Stream|int $stream): static
    {
        return $this->state(fn (array $attributes) => [
            'stream_id' => $stream instanceof Stream ? $stream->id : $stream,
        ]);
    }
}
