<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Port of legacy `Component\ThirdPartyIntegration\Model\TPICampaignAssociation`
 * (application/Component/ThirdPartyIntegration/Model/TPICampaignAssociation.php).
 * Table `third_party_integration_campaign_associations` — no timestamps
 * (matches legacy schema exactly, confirmed via live `DESCRIBE` against the
 * old DB, see the migration file's comment).
 */
class TpiCampaignAssociation extends Model
{
    protected $table = 'third_party_integration_campaign_associations';

    public $timestamps = false;

    protected $fillable = ['integration_id', 'campaign_id'];
}
