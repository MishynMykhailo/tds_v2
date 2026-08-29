<?php

namespace App\Services;

use App\Models\AclRule;
use App\Models\AffiliateNetwork;
use App\Models\Campaign;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Landing;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use Illuminate\Contracts\Support\Enumerable;

/**
 * Port of legacy `Component\Users\Service\AclService`
 * (application/Component/Users/Service/AclService.php in the old codebase).
 * Contract: docs/legacy-reference/frontend/backend_api_reference.md §5.
 *
 * Legacy invariant preserved everywhere: `$user->isAdmin()` bypasses ACL
 * entirely (see `AclService::filter()`/`isCreateAllowed()`/etc. in the old
 * code — every one of them short-circuits `true`/full-list for an admin).
 * A `null` user (guest / unauthenticated — see CurrentUserService) is
 * treated as having NO access anywhere, which differs from legacy (legacy
 * throws `Core\Application\Exception\Error("Unauthenticated user")` for a
 * null user in most of these methods, because in the old codebase every
 * `?object=` request is already guaranteed to have a resolved user by the
 * time it reaches a controller — auth is enforced upstream). Here, until
 * the parallel auth middleware is wired, `CurrentUserService::get()` can
 * legitimately return null on every request, so "null user -> deny" is the
 * safe interpretation of the same invariant instead of a fatal error.
 *
 * `entity_type` values mirror the real legacy `Traffic\Model\*::aclKey()`
 * constants (confirmed by reading the old model sources directly — these
 * are NOT simply lowercased class names):
 *   - Campaign::$_aclKey    = "campaigns"
 *   - AffiliateNetwork      = "affiliate_networks"
 *   - Domain                = "domains"
 *   - Landing               = "landings"
 *   - Offer                 = "offers"
 *   - TrafficSource         = "traffic_sources"
 *   - BaseStream::$_aclKey  = NULL — streams have NO direct ACL entity type;
 *     access is always checked through the parent campaign
 *     (`isViewAllowed($campaign)`/`isEditAllowed($campaign)`), see §10.2:
 *     "Стримы всегда привязаны к кампании — доступ проверяется через ACL
 *     родительской кампании, не напрямую по стриму." Callers (e.g.
 *     StreamsController) must resolve the stream's parent Campaign and pass
 *     THAT to isViewAllowed()/isEditAllowed()/etc., not the Stream itself.
 *
 * ACL_KEYS below now covers every entity module ported so far (Campaigns,
 * Offers, Landings, TrafficSources, Domains, AffiliateNetworks) — extend it
 * as more modules land. Streams intentionally has NO entry (see the
 * $_aclKey=NULL note above — it's not a bug, callers must pass the parent
 * Campaign instead).
 *
 * `App\Models\User` and `App\Models\Group` are intentionally ALSO absent
 * from ACL_KEYS (confirmed by reading the real legacy sources, not
 * assumed):
 *   - `Users\Model\User` declares no `$_aclKey` at all — legacy
 *     `UsersController`/`ApiKeysController` gate purely on
 *     `$user->isAdmin()`, never through `AclService::filter()`/
 *     `isCreateAllowed()`. There is no per-user "view/edit this other
 *     user" ACL concept in the legacy contract to replicate.
 *   - `Groups\Model\Group::$_aclKey = "group"` IS declared, but it's
 *     vestigial: `Users\Service\AclService::_getEntityTypeFromList()`
 *     never reads a Group's own aclKey — for `$isGroup=true` calls it
 *     resolves the entity type from `GroupService::getAclEntityType(
 *     $group->type)` instead (the group's *contained* entity kind:
 *     campaigns/offers/landings, which already equal real ACL_KEYS
 *     values). See the dedicated `isGroupViewAllowed()`/
 *     `isGroupEditAllowed()`/`addGroupAuthorPermission()` methods below,
 *     which key off `$group->type` directly instead of ACL_KEYS.
 */
class AclService
{
    public const VIEW = 'view';

    public const EDIT = 'edit';

    /**
     * Legacy `Component\Users\Service\AclService::ALLOW_ANY` /
     * `::ALLOW_NONE` — sentinel return values of getAllowedCampaignIds()
     * below, alongside a real array of allowed campaign ids.
     */
    public const ALLOW_ANY = 'allow_any';

    public const ALLOW_NONE = 'allow_none';

    /**
     * @var array<class-string, string> Eloquent model class -> legacy
     *                                  acl_rules.entity_type value.
     */
    private const ACL_KEYS = [
        Campaign::class => 'campaigns',
        Offer::class => 'offers',
        Landing::class => 'landings',
        TrafficSource::class => 'traffic_sources',
        Domain::class => 'domains',
        AffiliateNetwork::class => 'affiliate_networks',
    ];

    /**
     * Legacy `AclService::groupEntityType()` — entity types for which
     * `acl_rules.groups` (not just `.entities`) participates in the
     * per-entity access check, via the entity's own `group_id` column.
     */
    private const GROUP_ENTITY_TYPES = ['campaigns', 'offers', 'landings'];

    /**
     * Legacy `isResourceAllowed($user, $resourceName)`. Deliberately does
     * NOT replicate the legacy "mandatory resources always allowed, even
     * for a null user" branch (`AclResourceRepository::getMandatory()`) —
     * no resource registry has been ported yet, and Campaigns/Streams have
     * no "guest-allowed" resource per the task brief, so a null user is
     * simply denied.
     */
    public function isResourceAllowed(?User $user, string $resourceName): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $resource = $user->aclResource;
        if (! $resource) {
            return false;
        }

        return in_array($resourceName, $resource->resources ?? [], true);
    }

    /**
     * Legacy `filterByAcl($entityList, $forEdit, $user)` (thin wrapper
     * around `filter($user, $entityList, $operationType, false)`).
     *
     * @param  iterable<object>  $entities
     * @return array<int, object>
     */
    public function filterByAcl(iterable $entities, bool $forEdit, ?User $user): array
    {
        $list = $this->toList($entities);

        if ($user === null) {
            return [];
        }

        if ($user->isAdmin()) {
            return $list;
        }

        if (empty($list)) {
            return [];
        }

        $entityType = $this->aclKeyFor($list[0]);
        $rule = $this->findRule($user, $entityType);
        if (! $rule) {
            return [];
        }

        $operationType = $forEdit ? self::EDIT : self::VIEW;

        // Legacy `filter()`: FULL_ACCESS always passes the whole list;
        // READ_ONLY passes the whole list ONLY for a view operation (an
        // edit-mode filter against a read_only rule yields nothing).
        if ($rule->access_type === AclRule::FULL_ACCESS
            || ($rule->access_type === AclRule::READ_ONLY && $operationType === self::VIEW)) {
            return $list;
        }

        if ($rule->access_type === AclRule::READ_ONLY) {
            return [];
        }

        return array_values(array_filter(
            $list,
            fn ($entity) => $this->entityAllowedByRule($rule, $entity)
        ));
    }

    /** Legacy per-entity view check (old code inlines this via `filter()` with a 1-item list + throwDeny()). */
    public function isViewAllowed(?User $user, $entity): bool
    {
        return $this->checkSingle($user, $entity, self::VIEW);
    }

    /** Legacy per-entity edit check. */
    public function isEditAllowed(?User $user, $entity): bool
    {
        return $this->checkSingle($user, $entity, self::EDIT);
    }

    private function checkSingle(?User $user, $entity, string $operationType): bool
    {
        if ($user === null || $entity === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $entityType = $this->aclKeyFor($entity);
        $rule = $this->findRule($user, $entityType);
        if (! $rule) {
            return false;
        }

        if ($rule->access_type === AclRule::FULL_ACCESS) {
            return true;
        }

        if ($rule->access_type === AclRule::READ_ONLY) {
            return $operationType === self::VIEW;
        }

        return $this->entityAllowedByRule($rule, $entity);
    }

    /** Legacy `isCreateAllowed($user, $entityType)` -> `$acl->createAllowed()`. */
    public function isCreateAllowed(?User $user, string $entityType): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $rule = $this->findRule($user, $entityType);
        if (! $rule) {
            return false;
        }

        return $rule->createAllowed();
    }

    /**
     * Legacy `addAuthorPermission($user, $entityList, $isGroup)`, narrowed
     * to a single freshly-created entity (matches how CampaignsController
     * calls it: once per created campaign, right after `->save()`).
     * No-op for admins (legacy: admins never need explicit grants) and for
     * any access_type other than CREATED_BY_USER_GROUPS_AND_SELECTED
     * (legacy: `!$acl->createAllowed()` bails out — but FULL_ACCESS doesn't
     * need per-entity grants either, only the "created by me" type actually
     * tracks individual entity ids to extend access).
     */
    public function addAuthorPermission(User $user, $entity): void
    {
        if ($user->isAdmin() || $entity === null) {
            return;
        }

        $entityType = $this->aclKeyFor($entity);
        $rule = $this->findRule($user, $entityType);

        if (! $rule) {
            // Legacy silently no-ops when the user has no ACL rule row yet
            // for this entity type (`empty($acl)` branch in
            // addAuthorPermission()) — it does NOT create one implicitly.
            return;
        }

        if ($rule->access_type !== AclRule::CREATED_BY_USER_GROUPS_AND_SELECTED) {
            return;
        }

        $id = $this->entityId($entity);
        if ($id === null) {
            return;
        }

        $entities = $rule->entities ?? [];
        if (! in_array($id, $entities, true)) {
            $entities[] = $id;
            $rule->entities = $entities;
            $rule->save();
        }
    }

    /**
     * Port of legacy `getAllowedCampaignIds($user)`
     * (application/Component/Users/Service/AclService.php) — used by
     * `Component\Clicks\Grid\AccessRestriction` to build the
     * `campaign_id IN (...)` filter automatically added to every
     * clicks/conversions grid query (`GridBuilder::factory()` in the old
     * codebase). Returns:
     *   - self::ALLOW_ANY  — admin, or a full_access/read_only campaigns
     *     rule: no filtering needed, every campaign is visible.
     *   - self::ALLOW_NONE — null (guest) user (legacy throws instead, see
     *     class docblock for why this port denies instead), no acl_rules
     *     row for "campaigns" at all, or a to_groups_and_selected/
     *     created_by_user_groups_and_selected rule that resolves to an
     *     empty id set.
     *   - array<int> — the concrete allowed campaign ids: the rule's own
     *     `entities` merged with every campaign whose `group_id` is in the
     *     rule's `groups` (legacy: `CampaignRepository::findByGroupIds()`).
     */
    public function getAllowedCampaignIds(?User $user): string|array
    {
        if ($user === null) {
            return self::ALLOW_NONE;
        }

        if ($user->isAdmin()) {
            return self::ALLOW_ANY;
        }

        $rule = $this->findRule($user, 'campaigns');
        if (! $rule) {
            return self::ALLOW_NONE;
        }

        if ($rule->access_type === AclRule::FULL_ACCESS || $rule->access_type === AclRule::READ_ONLY) {
            return self::ALLOW_ANY;
        }

        $result = array_map('intval', $rule->entities ?? []);

        $groupIds = $rule->groups ?? [];
        if (! empty($groupIds)) {
            $groupCampaignIds = Campaign::query()
                ->whereIn('group_id', $groupIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $result = array_values(array_unique(array_merge($result, $groupCampaignIds)));
        }

        if (empty($result)) {
            return self::ALLOW_NONE;
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Group-level checks (App\Models\Group / GroupsController), ported from
    // legacy `Admin\Controller\Helper\AclHelper::isEditGroupAllowed()` /
    // `isViewGroupAllowed()` / `filterGroupsByAcl()`, which all bottom out
    // in `Users\Service\AclService::filter($user, [$group], $op, true)`.
    //
    // Deliberately NOT modeled via ACL_KEYS/aclKeyFor() like the entity
    // checks above: a Group is not an ACL-checkable entity type in its own
    // right (legacy `Groups\Model\Group::$_aclKey = "group"` is a vestigial
    // value — no `acl_rules` row ever uses entity_type="group", confirmed
    // by reading `Users\Service\AclService::_getEntityTypeFromList()`,
    // which for `$isGroup=true` resolves the entity type from
    // `GroupService::getAclEntityType($group->type)` instead, i.e. the
    // *contained* entity kind, not the Group class). `Group::type` values
    // ("campaigns"/"offers"/"landings") already equal the real aclKey
    // strings 1:1 (see `Groups\Model\Group::TYPE_CAMPAIGN` etc. in the old
    // codebase), so no extra mapping table is needed — `$group->type` is
    // used directly as the `acl_rules.entity_type` to look up.
    //
    // The check itself also differs from checkSingle()'s per-entity logic:
    // a group is "allowed" when the group's OWN id (not some contained
    // entity's id) is listed in the matching rule's `groups` array — this
    // is what legacy `AclRule::checkGroupId()` / `_filterByAcl($isGroup=true)`
    // does, as opposed to `checkEntityId()`/`entities` for a normal entity.
    // ---------------------------------------------------------------

    public function isGroupViewAllowed(?User $user, Group $group): bool
    {
        return $this->checkGroupAccess($user, $group, self::VIEW);
    }

    public function isGroupEditAllowed(?User $user, Group $group): bool
    {
        return $this->checkGroupAccess($user, $group, self::EDIT);
    }

    private function checkGroupAccess(?User $user, Group $group, string $operationType): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $rule = $this->findRule($user, (string) $group->type);
        if (! $rule) {
            return false;
        }

        if ($rule->access_type === AclRule::FULL_ACCESS) {
            return true;
        }

        if ($rule->access_type === AclRule::READ_ONLY) {
            return $operationType === self::VIEW;
        }

        $groups = $rule->groups ?? [];

        return in_array($group->getKey(), $groups, true);
    }

    /**
     * Legacy `AclHelper`-driven `GroupsController::createAction()` ->
     * `Users\Service\AclService::addAuthorPermission($user, [$group], true)`
     * -> `AclRule::addGroupPermission($group->getId())`: extends a
     * `created_by_user_groups_and_selected` rule's `groups` array with the
     * newly created group's own id (mirrors addAuthorPermission() above,
     * but appends to `groups` instead of `entities` — see the class docblock
     * above this section for why groups use a different id list).
     */
    public function addGroupAuthorPermission(User $user, Group $group): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $rule = $this->findRule($user, (string) $group->type);
        if (! $rule || $rule->access_type !== AclRule::CREATED_BY_USER_GROUPS_AND_SELECTED) {
            return;
        }

        $groups = $rule->groups ?? [];
        if (! in_array($group->getKey(), $groups, true)) {
            $groups[] = $group->getKey();
            $rule->groups = $groups;
            $rule->save();
        }
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    private function findRule(User $user, string $entityType): ?AclRule
    {
        return AclRule::query()
            ->where('user_id', $user->getKey())
            ->where('entity_type', $entityType)
            ->first();
    }

    private function entityAllowedByRule(AclRule $rule, $entity): bool
    {
        if (in_array($rule->entity_type, self::GROUP_ENTITY_TYPES, true)) {
            $groupId = is_object($entity) ? ($entity->group_id ?? null) : null;
            $groups = $rule->groups ?? [];
            if ($groupId !== null && in_array($groupId, $groups, true)) {
                return true;
            }
        }

        $id = $this->entityId($entity);
        $entities = $rule->entities ?? [];

        return $id !== null && in_array($id, $entities, true);
    }

    private function entityId($entity)
    {
        if (is_object($entity) && method_exists($entity, 'getKey')) {
            return $entity->getKey();
        }

        if (is_object($entity) && isset($entity->id)) {
            return $entity->id;
        }

        return is_array($entity) ? ($entity['id'] ?? null) : null;
    }

    private function aclKeyFor($entity): string
    {
        $class = is_object($entity) ? get_class($entity) : null;

        if ($class !== null && isset(self::ACL_KEYS[$class])) {
            return self::ACL_KEYS[$class];
        }

        throw new \InvalidArgumentException(
            'AclService: no known ACL entity_type for '.($class ?? gettype($entity)).
            ' — add it to AclService::ACL_KEYS (mirror the real legacy '.
            'Traffic\\Model\\*::$_aclKey value, not a lowercased class name).'
        );
    }

    /**
     * @param  iterable<object>  $entities
     * @return array<int, object>
     */
    private function toList(iterable $entities): array
    {
        if (is_array($entities)) {
            return array_values($entities);
        }

        // Illuminate\Support\Enumerable (Eloquent/Support Collection):
        // ->all() keeps the actual model objects, unlike ->toArray() which
        // would recursively serialize them.
        if ($entities instanceof Enumerable) {
            return array_values($entities->all());
        }

        return array_values(iterator_to_array($entities));
    }
}
