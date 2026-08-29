<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 *
 * Mirrors legacy `tds_api_keys` defaults (see application/data/schema.sql
 * and the `2025_01_01_000013_create_api_keys_table` migration this factory
 * is built against). `key` shape matches
 * App\Http\Controllers\Admin\ApiKeysController's random-key generation
 * (32 lowercase hex chars, same length/alphabet as legacy's
 * `md5(uniqid(rand(), true) . SALT)`).
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => bin2hex(random_bytes(16)),
            'user_id' => UserFactory::new()->create()->id,
            'datetime' => now(),
        ];
    }

    public function forUser(User|int $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user instanceof User ? $user->id : $user,
        ]);
    }
}
