# Round 4A findings (draft, in progress)

## 0. CRITICAL cross-cutting bug found while setting up the import test (creating a target campaign)

- **`application/Core/Model/AbstractModel.php::restoreData($data)`** used raw `static::$_fields` (which is `NULL`
  for literally every model in this codebase — the long-cataloged Pattern 12 from BUG_PATTERNS.md) instead of the
  established fallback `static::$_fields ?: static::_realColumns()` already used everywhere else in this same file
  (`definition()`, `getFields()`). `DataConverterService::restoreFromMysql($fields, $data)` does
  `foreach ($fields as $field => $type)` — with `$fields = NULL` this silently iterates zero times (PHP warning,
  no exception) and returns an EMPTY array. Effect: `AbstractModel::reload()` (used by essentially every
  `createAction()` across the app — Campaigns, and likely Offers/Landings/TrafficSources/Domains/etc — to refresh
  the entity right after insert before serializing the response) always wiped the entity's in-memory data down to
  nothing. Symptom actually caught live: `POST ?object=campaigns.create` returned HTTP 200 but body
  `{"domain":null,"domain_id":null}` (only the two keys the serializer's `extra()` sets unconditionally) instead of
  the real created campaign — frontend then tried to route to `#!/campaigns/undefined` and threw a UI-Router
  "Transition Rejection" error, silently stranding the user on the create form after a campaign WAS actually
  created in the DB. Confirmed via direct PHP reproduction (`CampaignService::create()` then `->reload()`:
  `getId()` went from `"8"` to `NULL`, `getData()` from a full row to `[]`) and via real HTTP
  (`campaigns.create` response body empty vs. full after fix). FIXED: `static::$_fields` ->
  `static::$_fields ?: static::_realColumns()`. This is likely one of the highest-impact bugs found this whole
  session given how many controllers call `->reload()` after create.
- Related, smaller bug exposed by the above once the response was no longer empty:
  **`application/Component/Campaigns/Controller/CampaignsController.php::createAction()`** built its response
  with `new CampaignSerializer()` (both `$extended` and `$withStreams` defaulted to `false`), unlike `updateAction()`
  which correctly uses `CampaignSerializer(true, $withStreams)`. With `$extended=false` the serializer skips the
  block that normalizes `traffic_source_id`/`ts` (turns raw DB `"0"` into `null`) and that adds `group`/
  `streams_count`/`postbacks`. Symptom: newly created campaign's `traffic_source_id` leaked through as the raw
  string `"0"`, and the frontend immediately fired `?object=trafficSources.show&id=0` which 404'd
  (`TrafficSource #0 not found`, visible as an unhandled promise rejection in the browser console right after
  create). FIXED: `createAction()` now builds `$withStreams` the same way `updateAction()` does and uses
  `CampaignSerializer(true, $withStreams)`.
- Both fixes verified live: creating a campaign via the real UI now correctly navigates to
  `#!/campaigns/<new-id>?activeTab=1` with zero console errors, and `campaigns.create`'s JSON response contains
  the full campaign row with `traffic_source_id: null`.

## 1. Triggers
- BUG: `application/Component/Triggers/Repository/TriggersRepository.php` — `$_validTargets`/`$_validActions`/`$_validConditions`
  static props were `NULL` (never populated), used by `TriggerValidator`'s "in" rule. Every trigger create/update
  failed with 406 `{"condition":["Contains invalid value"],"action":[...],"target":[...]}` even with values taken
  directly from the app's own `Triggers.targets/conditions/actions` dropdowns. FIXED: populated with
  `TriggerAssociation::TARGET_*/ACTION_*/CONDITION_*` constants (matches TriggerAssociation::setTarget() which
  already referenced the same never-populated `self::$_validTargets`).
  Verified: created trigger (target=stream, condition=not_respond, action=disable, interval=45) on Stream 1 of
  campaign 4, saved via campaigns.update (nested streams[].triggers[]), confirmed row in `tds_triggers`, confirmed
  survives full page reload.
- Minor bug (same file family): `application/Traffic/Pipeline/Service/EntityBindingService.php` —
  `const _BINDING_TYPE_DIC = NULL;` (same "null dictionary constant" pattern as already-fixed
  `LandingOfferRotator::_ASSOCIATION_FIELD_DIC`). Used in `bindEntityRedis()`/`_logMessage()` as
  `self::_BINDING_TYPE_DIC[$type]` — silently NULL (PHP 7.4: warning + NULL, not fatal) so bind log messages read
  "Visitor is bound by ... to  #7" (empty type name) instead of naming stream/landing page/offer. FIXED: populated
  dictionary (s=>stream, lp=>landing page, of=>offer).

## 2. Split testing / bind_visitors
- Campaign 4 switched to Split testing (type=weight), created Stream 2 (weight 50) alongside Stream 1 (weight 100),
  bind_visitors set to "Streams, LPs, offers" (slo).
- CRITICAL BUG: `application/Traffic/Pipeline/Stage/UpdateCampaignUniquenessSessionStage.php` — closure passed to
  `$logEntry->addLazy()` referenced `$serverRequest` without `use ($serverRequest)` (classic decompiler
  "lost use()" bug, same family as several already fixed this session). `TrafficLogEntry::addLazy()` executes the
  closure immediately (despite the name), so `$serverRequest` was undefined/NULL inside → fatal
  `Error: Call to a member function getCookieParams() on null`, propagating uncaught up through Pipeline ->
  ClickDispatcher -> HTTP 500. This is hit on every NON-bot click reaching this stage (bot clicks take an early
  return a few lines above and never reach the bug) — meaning **every real-browser (non-curl) click that isn't
  flagged as a bot has been completely broken** (500 error, nothing recorded), while curl/bot-flagged test clicks
  used in prior rounds' verification silently avoided the bug. FIXED: added `use ($serverRequest)` + `return`.
  Verified: 5 consecutive real-browser clicks (same context) went from 500 -> 200 after the fix.
- Verified split-testing weight rotation: 24 clicks from 24 distinct browser contexts (distinct User-Agent, same
  uniqueness_method=ip_ua so distinct uniqueness IDs) against weight 100 (Stream 1) vs weight 50 (Stream 2) landed
  15:9 in tds_clicks.stream_id — consistent with the expected ~2:1 ratio.
- Verified bind_visitors sticky binding: 5 consecutive clicks from the SAME browser context all bound to the same
  stream_id=7/offer_id=3 in tds_clicks; confirmed via Redis keys
  `{prefix}:{uniquenessID}:s:4` = "7" and `{prefix}:{uniquenessID}:of:4` = "3" with TTL matching campaign's
  cookies_ttl (12h -> ~43200s).
- Campaign.isBindVisitorsEnabled()/isBindVisitorsLandingEnabled()/isBindVisitorsOfferEnabled() — reviewed, logic is
  correct (bind_visitors values "s"/"sl"/"slo" via strlen checks), no bug found here.

## 3. Streams export/import
- `streams.export` (UI: Campaigns grid row -> "..." menu -> Export Streams) works: returns
  `{"url": "http://tds-app:8080/exports/streams_4_2026-08-27.json"}`, file contains both streams with weight,
  schema, offers, landings.
- POTENTIAL BUG (found via code reading, confirming live): `application/Component/Streams/ExportStreams.php::export()`
  never includes `triggers` in the exported item (only filters/landings/offers) — StreamSerializer (normal read
  path) DOES include triggers via `_addAssociation`, but the dedicated export serializer path does not. So any
  trigger configured on a stream (like the one added in section 1) is silently dropped from the export file.
- CRITICAL BUG (root cause of "import silently creates nothing"):
  `application/Component/Streams/Controller/StreamsController.php::importAction()` had
  `$fileContent = (int) $files["file"]->getStream();` — same `(int)` vs `(string)` cast-on-object bug already
  cataloged multiple times this session (Pattern 9). `getStream()` returns a PSR-7 StreamInterface; `(int)` on an
  object always yields `1`. Effect chain: `empty(1)` is false so the "empty file" guard never triggers;
  `ImportStreams::import()` does `json_decode(1, true)` -> PHP coerces to `json_decode("1", true)` -> returns int
  `1` (valid scalar JSON); `empty(1)` is again false so "wrong format" guard never triggers either; then
  `foreach ($items as ...)` on an integer silently iterates zero times (PHP warning, no exception) -> `$result = []`.
  Net effect: uploading ANY file (valid or garbage) returned HTTP 200 with body `[]` and created NOTHING, with zero
  errors surfaced anywhere. FIXED: `(int)` -> `(string)`.
- SECOND BUG, exposed only after the cast fix above got the importer actually reading real JSON:
  `application/Component/Streams/ImportStreams.php::import()` — when `save=true`, before calling
  `StreamService::create($data)`:
  ```
  foreach ($data as $field => $value) {
      if (!$definition->hasField($field)) { unset($data[$field]); }
  }
  ```
  `$definition = Stream::definition()` only knows real `streams` table columns. `filters`/`landings`/`offers`/
  `triggers` are NOT real `streams` columns (separate tables), so this loop stripped ALL of them from `$data`
  before `create()`. `StreamService::create()` -> `_updateAssociations()` uses `array_key_exists("landings", $params)`
  etc. to decide whether to assign associations — with the keys gone, nothing was ever assigned. FIXED: excluded
  `filters`/`landings`/`offers`/`triggers` from the strip loop (`$associationFields` allowlist).
- THIRD BUG (gap, not a crash): `application/Component/Streams/ExportStreams.php::export()` never included
  `triggers` in the exported item at all (only filters/landings/offers) — so even after fixing the two bugs above,
  triggers would still never survive an export/import round trip. FIXED: added `_getTriggers()` (same pattern as
  `_getFilters`/`_getLandings`/`_getOffers`, using `TriggersRepository::allByStream()` +
  `StreamTriggerSerializer`, same id/stream_id/updated_at exclusions).
- Verified end-to-end after all three fixes: exported campaign 4 (Stream 1: trigger `target=stream/condition=
  always/action=do_nothing/interval=30` + offer; Stream 2: landing + offer), imported into a fresh campaign
  (id 10, "RoundA Import Target") via the real UI file-upload flow (`?object=streams.import`, multipart) ->
  both new streams (13, 14) created with correct name/weight/schema, trigger correctly attached to stream 13,
  landing association correctly attached to stream 14, offer associations correctly attached to both — confirmed
  in `tds_streams`/`tds_triggers`/`tds_stream_landing_associations`/`tds_stream_offer_associations`.

## 4. Offers: clone/archive/cost types — NO BUGS FOUND, fully working

Created a fresh external/redirect offer "RoundA CostType Test" (id 6) via the real UI (`?object=offers.create`,
CPA, payout 15.50, non-auto). Verified live:
- Switching Payout Type CPA -> CPC via edit form + save (`?object=offers.update`) correctly preserved
  `payout_value = 15.5000`, only `payout_type` changed in `tds_offers`.
- Clone (`?object=offers.clone`) correctly created a new offer ("Copy of RoundA CostType Test", id 7) with
  `payout_type`/`payout_value`/`action_payload` all copied from the source.
- Archive (`?object=offers.archive`, UI "Delete" button + confirm) correctly set `state=deleted`.
- `?object=offers.deleted` correctly lists archived offers (state=deleted) — note: did not find an obvious UI
  entry point for this "trash" view on the Offers grid itself (no visible "show deleted" toggle/icon found in this
  pass, unlike Campaigns which has one via the row `...` menu context) — confirmed the backend endpoint works via
  direct API call, worth a quick follow-up UI-discoverability check but not a backend bug.
- Restore (`?object=offers.restore`) correctly set `state` back to `active`.
- Note: only `CPA`/`CPC` payout types actually exist for Offers by design
  (`Traffic\Model\Offer::getValidPayoutTypes()` = `[PAYOUT_TYPE_CPA, PAYOUT_TYPE_CPC]`, confirmed in
  `OfferRepository::$_costTypes`) — `RevShare` is a Campaign-level *cost model* concept only, not an Offer
  *payout type* option; the Create/Edit Offer form correctly only offers CPA/CPC radios. Not a bug, just
  clarifying the task wording ("CPA/CPC/RevShare") against what the app actually implements for offers.

## 5. Landing editor (Ace) — NO BUGS FOUND, fully working

Opened the code editor for the pre-uploaded local landing "PW Test Landing Local" (id 3, via the small
`ion-android-attach` icon next to the name in the Landing Pages grid -> `#!/editor/landing/3`). Verified live,
all via the real UI (Ace editor keyboard interaction, not just direct API calls):
- `?object=editor.loadFiles` -> correct file tree (`index.html`).
- `?object=editor.loadFileData` -> correct file content.
- Edited the content in Ace (select-all, retype with a unique marker string) and clicked Save ->
  `?object=editor.saveFileData` -> saved verbatim to
  `/app/lander/pw-test-landing-local/index.html` on disk; confirmed by a **fresh full page reload** of
  `#!/editor/landing/3` (new browser navigation, not just re-render) that `loadFileData` returns the edited
  content back.
- `createFile` (the small "+" icon next to "File manager", modal asks for `dir/file_name`) -> created
  `roundA-test-file.txt` on disk, and the file tree correctly refreshed to include it.
- `removeFile` (select file -> red "Delete" button -> confirm in a second modal) -> file physically removed from
  disk and the file tree correctly refreshed back to just `index.html`.
- Side note (not a bug, testing-script artifact worth recording): typing raw `<html>...</html>` into the Ace
  editor via `page.keyboard.type()` triggers Ace's HTML auto-close-tag feature, appending extra stray closing
  tags after the typed content (e.g. `...</html></h1></body></html>`) — this is expected editor behavior, not a
  backend issue; the backend correctly persisted exactly the bytes the frontend sent.
- Unrelated observation while locating the right row: a DIFFERENT, pre-existing landing named "langing" (id 2,
  also flagged `local_file` with the attach icon) throws
  `?object=editor.loadFiles` -> 500 `DirectoryNotFoundException: The "/app/lander/langing" directory does not
  exist.` This is a broken/incomplete test fixture from an earlier round (claims to be a local landing but its
  files were never actually uploaded to disk) — not a code bug, just noting it in case it causes confusion for
  other testers hitting that same landing.

## 6. Infrastructure notes for coordinating agent / next rounds

- `tds-mysql` got OOM-killed mid-session (`docker ps -a` showed `Exited (137)`) under combined load from both
  round4A and round4B running Playwright + report queries concurrently against the single-threaded PHP built-in
  dev server; restarted it with `docker start tds-mysql`, data was intact (bind-mounted, not ephemeral). Also saw
  the shared `tds-app` PHP built-in server (`php -S`, single-threaded) queue up requests for 200+ seconds under
  concurrent load from both rounds' Playwright activity + `cron:run` — not a code bug, just a dev-stand capacity
  limit worth knowing if things seem to "hang".
- The background click-queue-processing loop the coordinating agent started
  (`while true; do su -s /bin/sh www-data -c "cd /app && php process_queue.php" ...`) references a
  `process_queue.php` file that **does not exist anywhere in the repo** — every iteration of that loop fails with
  "Could not open input file: process_queue.php" (visible in `var/log/queue_loop.log`). It has apparently never
  actually processed the click queue. I did NOT touch/kill that loop (per instructions), but flushed the queue
  manually for my own tests via `su -s /bin/sh www-data -c "cd /app && php bin/cli.php cron:run"` (the real
  command that runs `ExecuteDelayedCommand`/`ProcessCommandQueue`), which worked correctly (took ~90s, no hang).
  Recommend the coordinating agent either fix/recreate `process_queue.php` or switch the loop to
  `bin/cli.php cron:run` so click processing actually happens automatically going forward.
