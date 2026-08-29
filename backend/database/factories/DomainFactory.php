<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 *
 * Mirrors legacy `tds_domains` defaults (see
 * docs/legacy-reference/frontend/api/10.7_domains.md and the
 * `2025_01_01_000007_create_domains_table` migration this factory is built
 * against). Style matches database/factories/OfferFactory.php /
 * LandingFactory.php.
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainName(),
            'is_ssl' => false,
            'network_status' => 'active',
            'default_campaign_id' => null,
            'state' => 'active',
            'wildcard' => false,
            'catch_not_found' => true,
            'notes' => null,
            'error_description' => null,
            'ssl_status' => 'issued',
            'redirect' => 'not',
            'ssl_data' => null,
            'is_robots_allowed' => true,
            'next_check_at' => null,
            'ssl_redirect' => false,
            'allow_indexing' => true,
            'check_retries' => 0,
        ];
    }

    /**
     * Archived domain (soft "trash" state, not physically deleted — same
     * convention as Campaign/Stream/Offer/Landing).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'archived',
        ]);
    }

    /**
     * Domain still awaiting its first network check (legacy
     * `Domain::NETWORK_STATUS_VALIDATING`, the state a freshly-created
     * domain starts in per DomainsController::createAction).
     */
    public function validating(): static
    {
        return $this->state(fn (array $attributes) => [
            'network_status' => 'validating',
            'ssl_status' => 'awaiting_dns',
        ]);
    }
}
