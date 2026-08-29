<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 *
 * Mirrors legacy `tds_groups` defaults (see application/data/schema.sql and
 * the `2025_01_01_000012_create_groups_table` migration this factory is
 * built against). `type` values match `Groups\Model\Group::TYPE_CAMPAIGN`
 * ("campaigns") etc. in the old codebase — these are also the real
 * `acl_rules.entity_type` strings, see App\Services\AclService's group
 * section.
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'position' => fake()->numberBetween(1, 999),
            'type' => 'campaigns',
        ];
    }

    public function forOffers(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'offers']);
    }

    public function forLandings(): static
    {
        return $this->state(fn (array $attributes) => ['type' => 'landings']);
    }
}
