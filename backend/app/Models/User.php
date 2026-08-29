<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Legacy auth is JWT-cookie based, re-verified against user_password_hashes
 * on every request (see docs/legacy-reference/frontend/backend_api_reference.md
 * §4.1) — NOT Laravel's default session guard. This still extends
 * Authenticatable for compatibility with Laravel internals (factories,
 * `auth()` helper availability), but the actual login/verify flow lives in
 * an AuthService, not Laravel's built-in auth drivers. Schema mirrors
 * legacy `tds_users` 1-to-1 (confirmed via DESCRIBE against the live old
 * backend) — no `name`/`email`, those are not part of the legacy contract.
 */
#[Fillable(['type', 'login', 'password', 'password_hash', 'rules', 'permissions'])]
#[Hidden(['password', 'password_hash'])]
class User extends Authenticatable
{
    use HasFactory;

    /**
     * `rules` (`tds_users.rules`, a plain TEXT column in legacy — confirmed
     * via application/data/schema.sql) is only ever written as a PHP array
     * (see `Component\Users\Service\UserService::createUser()`:
     * `$data["rules"] = []` when absent) and read back raw/unprocessed by
     * `UserSerializer` — legacy's generic `DataConverterService::
     * convertToType()` JSON-encodes any array value written to a
     * non-JSON-typed column by default (its `default:` branch), so an array
     * cast here reproduces that on write, and mirrors it symmetrically on
     * read (legacy never actually decodes it back anywhere, but nothing
     * else in the legacy contract reads `rules` as a scalar either — it's
     * unused beyond being echoed back in the serializer). `permissions`
     * (varchar(250)) is NOT cast — legacy only ever treats it as a plain
     * string (`User::getPermissions()` -> raw `get("permissions")`, never
     * called anywhere in the old codebase either).
     */
    protected $casts = [
        'rules' => 'array',
    ];

    public function passwordHashes()
    {
        return $this->hasMany(UserPasswordHash::class);
    }

    public function aclResource()
    {
        return $this->hasOne(AclResource::class);
    }

    public function aclRules()
    {
        return $this->hasMany(AclRule::class);
    }

    public function isAdmin(): bool
    {
        return $this->type === 'ADMIN';
    }
}
