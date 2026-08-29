<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Port of legacy `Component\ThirdPartyIntegration\Model\ThirdPartyIntegration`
 * (application/Component/ThirdPartyIntegration/Model/ThirdPartyIntegration.php).
 * Table `third_party_integration` (see
 * database/migrations/2025_01_01_000025_create_third_party_integration_tables.php).
 *
 * Legacy `getIntegrationName()` reads `settings['name']` — replicated as a
 * plain accessor here rather than an Eloquent attribute, since it's only
 * used by TpiMandatoryController::listAsOptionsAction().
 */
class ThirdPartyIntegration extends Model
{
    protected $table = 'third_party_integration';

    protected $fillable = ['integration', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function getIntegrationName(): ?string
    {
        return $this->settings['name'] ?? null;
    }
}
