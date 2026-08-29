<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'type' => 'USER',
            'login' => fake()->unique()->userName(),
            'password' => null,
            'password_hash' => static::$password ??= Hash::make('password'),
            'rules' => null,
            'permissions' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['type' => 'ADMIN']);
    }
}
