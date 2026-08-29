<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPreference>
 *
 * Mirrors legacy `tds_user_preferences` defaults (see
 * application/data/schema.sql and the
 * `2025_01_01_000014_create_user_preferences_table` migration this factory
 * is built against).
 */
class UserPreferenceFactory extends Factory
{
    protected $model = UserPreference::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new()->create()->id,
            'pref_name' => 'timezone',
            'pref_value' => 'UTC',
        ];
    }

    public function forUser(User|int $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user instanceof User ? $user->id : $user,
        ]);
    }
}
