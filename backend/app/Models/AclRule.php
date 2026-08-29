<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AclRule extends Model
{
    public const FULL_ACCESS = 'full_access';

    public const CREATED_BY_USER_GROUPS_AND_SELECTED = 'created_by_user_groups_and_selected';

    public const TO_GROUPS_AND_SELECTED = 'to_groups_and_selected';

    public const READ_ONLY = 'read_only';

    public $timestamps = false;

    protected $table = 'acl_rules';

    protected $fillable = ['user_id', 'entity_type', 'access_type', 'groups', 'entities'];

    protected $casts = [
        'groups' => 'array',
        'entities' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createAllowed(): bool
    {
        return in_array($this->access_type, [self::FULL_ACCESS, self::CREATED_BY_USER_GROUPS_AND_SELECTED], true);
    }
}
