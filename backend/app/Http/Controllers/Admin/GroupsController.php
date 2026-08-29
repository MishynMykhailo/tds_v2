<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Group;
use App\Models\Landing;
use App\Models\Offer;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Groups\Controller\GroupsController` +
 * `Component\Groups\Serializer\GroupSerializer` +
 * `Component\Groups\Service\GroupService` (old codebase:
 * application/Component/Groups/Controller/GroupsController.php,
 * application/Component/Groups/Serializer/GroupSerializer.php,
 * application/Component/Groups/Service/GroupService.php,
 * application/Component/Groups/Validator/GroupValidator.php,
 * application/Component/Groups/Repository/GroupsRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
 *
 * `type` distinguishes which entity kind a group organizes — legacy
 * `Groups\Model\Group::TYPE_CAMPAIGN`/`TYPE_OFFER`/`TYPE_LANDING` =
 * "campaigns"/"offers"/"landings" (confirmed by reading the old model
 * source directly), which are ALSO the real ACL entity_type strings for
 * those three modules (see AclService::ACL_KEYS) — so `type` doubles as
 * the ACL lookup key with no extra mapping table, same as legacy
 * `GroupService::getAclEntityType()` effectively is (an identity mapping
 * once you look past the indirection through `getModelDefinition()`).
 *
 * `showAction` has NO legacy counterpart (legacy only exposes
 * index/listAsOptions/create/update/delete) — added per task brief for
 * CRUD symmetry with the other ported *Controller classes, same
 * "documented addition" treatment as
 * UsersController::listAsOptionsAction(). `delete` is NOT ported (task
 * brief only asked for the 5 actions below) — it also cascades
 * (`group_id = NULL` on every member Campaign/Offer/Landing +
 * `AclService::onGroupDelete()`), out of scope here.
 */
class GroupsController extends Controller
{
    /** Legacy `Groups\Model\Group::TYPE_CAMPAIGN`/`TYPE_OFFER`/`TYPE_LANDING`. */
    private const VALID_TYPES = ['campaigns', 'offers', 'landings'];

    /** @var array<string, class-string> group `type` -> member Eloquent model. */
    private const TYPE_MODELS = [
        'campaigns' => Campaign::class,
        'offers' => Offer::class,
        'landings' => Landing::class,
    ];

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention established by CampaignsController/OffersController/etc.
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        static $cache = null;
        static $cachedFor = null;

        if ($cachedFor === $request) {
            return $cache;
        }

        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            $result = is_array($decoded) ? $decoded : null;
        } elseif (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);
            $result = $parsed;
        } else {
            $result = null;
        }

        $cachedFor = $request;
        $cache = $result;

        return $result;
    }

    private function param(Request $request, string $name, $default = null)
    {
        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        $body = $this->parsedBody($request);
        if (is_array($body) && array_key_exists($name, $body)) {
            return $body[$name];
        }

        return $default;
    }

    private function postParams(Request $request): array
    {
        return $this->parsedBody($request) ?? [];
    }

    private function isPost(Request $request): bool
    {
        return ! empty($this->parsedBody($request)) || $request->isMethod('post');
    }

    private function boolParam($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // ---------------------------------------------------------------
    // §6 error-shape helpers
    // ---------------------------------------------------------------

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => ''], 404);
    }

    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    private function dbError(QueryException $e): Response
    {
        return response()->json(['error' => $e->getMessage(), 'stacktrace' => ''], 500);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Serialization (§8). GroupSerializer::$_fields = true (all columns)
    // + extra `count` when `extended`.
    // ---------------------------------------------------------------

    private function serializeGroup(Group $group, bool $extended = false): array
    {
        $group->refresh();

        $data = $group->toArray();

        if ($extended) {
            $modelClass = self::TYPE_MODELS[$group->type] ?? null;
            $data['count'] = $modelClass ? $modelClass::query()->where('group_id', $group->id)->count() : 0;
        }

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): Response
    {
        $type = (string) $this->param($request, 'type');

        $groups = Group::query()->where('type', $type)->orderBy('position')->orderBy('id')->get();
        $groups = $this->filterViewable($groups);

        $extended = $this->boolParam($this->param($request, 'extended'));

        return response()->json($groups->map(fn (Group $g) => $this->serializeGroup($g, $extended))->values());
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');
        $group = ! empty($id) ? Group::find((int) $id) : null;

        if (! $group) {
            return $this->notFound('Group not found');
        }

        if (! $this->aclService->isGroupViewAllowed($this->currentUserService->get(), $group)) {
            return $this->forbidden('You are not allowed to view this group');
        }

        return response()->json($this->serializeGroup($group));
    }

    public function createAction(Request $request): Response
    {
        $params = $this->postParams($request);
        $type = $params['type'] ?? null;

        $errors = $this->validateGroupParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), (string) $type)) {
            return $this->forbidden('You are not allowed to create groups');
        }

        $group = new Group;
        $group->name = $params['name'];
        $group->type = $type;
        $group->position = $this->nextPosition((string) $type);

        try {
            $group->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addGroupAuthorPermission($this->currentUserService->get(), $group);

        return response()->json($this->serializeGroup($group));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');
        $group = ! empty($id) ? Group::find((int) $id) : null;

        if (! $group) {
            return $this->notFound('Group not found');
        }

        if (! $this->aclService->isGroupEditAllowed($this->currentUserService->get(), $group)) {
            return $this->forbidden('You are not allowed to edit this group');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateGroupParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        if (array_key_exists('name', $params)) {
            $group->name = $params['name'];
        }

        $position = $params['position'] ?? null;

        try {
            if ($position) {
                // Legacy `GroupService::updateGroup()`: bump whatever
                // currently sits at the target position out of the way
                // before moving this group there.
                $occupant = Group::query()->where('position', $position)->first();
                if ($occupant && $occupant->id !== $group->id) {
                    $occupant->position = ((int) $position) + 1;
                    $occupant->save();
                }
                $group->position = $position;
            }

            $group->save();

            // Legacy `GroupService::resort()`: renumber EVERY group's
            // position sequentially (1..N) by current position order —
            // deliberately NOT scoped to this group's `type`, matching the
            // real (slightly odd, but verified) legacy behavior of
            // `GroupsRepository::instance()->all(NULL, "position")`.
            $pos = 0;
            foreach (Group::query()->orderBy('position')->orderBy('id')->get() as $g) {
                $pos++;
                if ($g->position !== $pos) {
                    $g->position = $pos;
                    $g->save();
                }
            }
            $group->refresh();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        return response()->json($this->serializeGroup($group, true));
    }

    public function listAsOptionsAction(Request $request): Response
    {
        $type = (string) $this->param($request, 'type');

        $groups = Group::query()->where('type', $type)->orderBy('position')->orderBy('id')->get();
        $groups = $this->filterViewable($groups);

        $items = $groups->map(fn (Group $g) => [
            'id' => $g->id,
            'value' => $g->id,
            'name' => $g->name,
        ])->values();

        return response()->json($items);
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Legacy `AclHelper::filterGroupsByAcl()` — a guest (null user) sees
     * nothing, an admin sees everything, otherwise per-group
     * `isGroupViewAllowed()`.
     */
    private function filterViewable($groups)
    {
        $user = $this->currentUserService->get();

        if ($user === null) {
            return collect();
        }

        if ($user->isAdmin()) {
            return $groups;
        }

        return $groups->filter(fn (Group $g) => $this->aclService->isGroupViewAllowed($user, $g));
    }

    /**
     * Legacy `GroupService::getNextPosition($type)`: the FIRST matching
     * group's position + 1 (not the max) — verified against the real old
     * source, `GroupsRepository::findFirst("type = ...", "position, id")`
     * -> `$group->get("position") + 1`. Replicated as-is even though it
     * looks like an off-by-something quirk; not this port's place to
     * "fix" legacy business logic.
     */
    private function nextPosition(string $type): int
    {
        $first = Group::query()->where('type', $type)->orderBy('position')->orderBy('id')->first();

        return $first ? $first->position + 1 : 1;
    }

    /**
     * Minimal port of `GroupValidator`: `name`/`type` required (create
     * only for `type`, since legacy never allows changing a group's type
     * on update — there's no `type` handling at all in
     * `GroupService::updateGroup()`), `type` restricted to the three known
     * values (legacy would instead throw a generic `Exception` deep inside
     * `GroupService::getModelDefinition()` for an unknown type — replaced
     * here with a proper 406 so callers get a structured error). NOT
     * ported (TODO): uniqueness(name) scoped by type.
     */
    private function validateGroupParams(array $params, bool $partial = false): array
    {
        $errors = [];

        $namePresent = array_key_exists('name', $params);
        $nameEmpty = $namePresent && trim((string) $params['name']) === '';

        if ((! $partial && (! $namePresent || $nameEmpty)) || ($partial && $namePresent && $nameEmpty)) {
            $errors['name'] = ['The name field is required.'];
        }

        if (! $partial) {
            $typePresent = array_key_exists('type', $params);
            if (! $typePresent || empty($params['type'])) {
                $errors['type'] = ['The type field is required.'];
            } elseif (! in_array($params['type'], self::VALID_TYPES, true)) {
                $errors['type'] = ['The selected type is invalid.'];
            }
        }

        return $errors;
    }
}
