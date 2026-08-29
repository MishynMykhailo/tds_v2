<?php

namespace Database\Factories;

use App\Models\Stream;
use App\Models\StreamFilter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StreamFilter>
 */
class StreamFilterFactory extends Factory
{
    protected $model = StreamFilter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stream_id' => StreamFactory::new()->create()->id,
            'name' => 'country',
            'mode' => 'accept',
            'payload' => json_encode(['US', 'CA']),
        ];
    }

    /**
     * Attach the filter to a specific (already-created) stream instead of
     * spinning up a brand new one.
     */
    public function forStream(Stream|int $stream): static
    {
        return $this->state(fn (array $attributes) => [
            'stream_id' => $stream instanceof Stream ? $stream->id : $stream,
        ]);
    }
}
