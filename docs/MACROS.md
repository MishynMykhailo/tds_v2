# Macros reference — legacy vs traffic-core (Phase 14)

Full list of every macro registered in legacy's `MacroRepository::loadMacros()`
(`application/Traffic/Macros/MacroRepository.php`), cross-checked against what
`TrafficCore\Macros\ClickMacroValues`/`OutboundPostbackService` actually
implement. Compiled by re-reading the real registration call and the exact
`ClickMacroValues.php` keys — not from memory of Phase 14's summary, which
missed a few of these (`ts_id`, `destination`, `device_brand`, `offer`,
`traffic_source_name`, `visitor_code`, `keyword_cp1251`, `visitor_id`) that
this file corrects.

Legend: ✅ ported (real data) · 🟡 ported but always empty/zero (no data
source exists) · ❌ not ported.

## Click-context macros (`ClickMacroValues::forPayload()`)

| Macro | Aliases | Status | Notes |
|---|---|---|---|
| `sub_id` | `subid` | ✅ | `payload->clickFields['sub_id']` |
| `sub_id_1`..`sub_id_15` | | ✅ | raw submitted value, not the `ref_sub_ids` dictionary id |
| `extra_param_1`..`extra_param_10` | | ✅ | |
| `source` | | ✅ | |
| `referrer` | `referer` | ✅ | |
| `search_engine` | `se` | ✅ | |
| `keyword` | | ✅ | UTF-8 only — `keyword_cp1251` charset variant ❌ (see below) |
| `ad_campaign_id` | | ✅ | |
| `creative_id` | | ✅ | |
| `external_id` | | ✅ | |
| `x_requested_with` | | ✅ | |
| `cost` | | ✅ | no currency-code argument support (no exchange-rate infra) |
| `revenue` | | ✅ | sum of `lead_revenue`+`sale_revenue`+`rejected_revenue` on the click |
| `profit` | | ✅ | `revenue - cost` |
| `campaign_id` | `tds_campaign_id` | ✅ | |
| `campaign_name` | `tds_campaign_name` | ✅ | |
| `stream_id` | | ✅ | |
| `landing_id` | `tds_landing_id` | ✅ | |
| `offer_id` | `tds_offer_id` | ✅ | |
| `parent_campaign_id` | | ✅ | |
| `country` | `country_code`, `country_name` | ✅ | raw IP2Location code only — `:lang` argument (e.g. `{country:ru}`) accepted but ignored, no translation dictionary |
| `region` | `region_name` | ✅ | same `:lang`-ignored caveat |
| `city` | | ✅ | |
| `device_type` | | ✅ | |
| `device_model` | | ✅ | |
| `browser` | | ✅ | |
| `browser_version` | | ✅ | |
| `os` | | ✅ | |
| `os_version` | | ✅ | |
| `ip` | | ✅ | |
| `user_agent` | `ua`, `useragent` | ✅ | |
| `language` | | ✅ | primary `Accept-Language` tag |
| `current_domain` | | ✅ | |
| `date` | | ✅ | fixed ISO-8601 (`c`) format — legacy's `:format` argument (any `date()` format string) not honored |
| `random` | | ✅ | fixed `0-9999` range — legacy's variable min/max arguments not honored |
| `token` | | ✅ | Redis lookup-token from Phase 9's offer token-binding, null if no offer was chosen |
| `currency` | | ✅ | from `settings.currency` |
| `debug` | | ✅ | JSON dump of headers/server params/click/method/URI |
| `isp` | | 🟡 | always `""` — LITE-tier IP2Location has no ISP data (Phase 9 finding) |
| `operator` | `carrier` | 🟡 | always `""` — same reason |
| `connection_type` | | 🟡 | always `""` — same reason |
| `is_bot` | | 🟡 | always `"0"` — no bot-detection runtime ported |
| `is_using_proxy` | | 🟡 | always `"0"` — no proxy-detection runtime ported |
| `ts_id` | | ❌ | traffic-source id not tracked on `Payload` anywhere |
| `destination` | | ❌ | not tracked |
| `device_brand` | | ❌ | `DeviceInfoResolver` doesn't expose it separately from `device_model` |
| `offer` | | ❌ | legacy's `Predefined\Offer` (offer name/label) — not implemented |
| `traffic_source_name` | | ❌ | traffic source lookup not wired into the click pipeline |
| `visitor_code` | | ❌ | not exposed on `Payload` (only the numeric `visitorId`) |
| `keyword_cp1251` | | ❌ | cp1251-charset variant of `keyword` |

## Conversion-context macros (`OutboundPostbackService::substituteMacros()`)

Separate, much smaller set — only fields `PostbackResult` actually carries.
Legacy's conversion macros need a full `Conversion` model this project
doesn't have.

| Macro | Status | Notes |
|---|---|---|
| `sub_id` | ✅ | (`subid` alias too) |
| `status` | ✅ | raw status string — legacy's `:mapping` remap argument not honored |
| `tid` | ✅ | |
| `cost` | ✅ | |
| `revenue` | ✅ | |
| `profit` | ✅ | `revenue - cost`, not a legacy-registered macro name but added here for symmetry with the click-context set |
| `original_status` | ❌ | no `Conversion` model |
| `conversion_time` | ❌ | no `Conversion` model |
| `conversion_cost` | ❌ | no `Conversion` model |
| `conversion_revenue` | ❌ | no `Conversion` model |
| `conversion_profit` | ❌ | no `Conversion` model |
| `visitor_id` | ❌ | legacy's `AnyConversionMacro("visitor_id")` — no `Conversion` model |

## Deliberately out of scope, not a gap to close later without a real decision

| Macro | Why |
|---|---|
| `from_file` | Executes an arbitrary local PHP file as a macro — same risk class as `local_file`'s sandbox, deliberately not built without an explicit ask (see `LocalFileSandbox`'s own security hardening for how that class of feature was handled when it WAS built). |
| `sample` | Legacy's own no-op example/placeholder macro — nothing to port. |
| Custom (admin-defined) macros | `Component\Macros\Repository\CustomMacroRepository` lets an admin register arbitrary PHP as a named macro via the admin UI — same execution-risk class as `from_file`, not attempted. |
| `alwaysRaw()` override | A handful of legacy macros (e.g. `debug`) force raw/un-urlencoded output regardless of the `{name}` vs `{_name}` prefix. Not ported — use the `{_name}` raw-prefix form manually for the same effect. |
| `_addParamsFromCampaign()` | Legacy's campaign-configured extra macro sources via `campaigns.parameters` — not needed as a separate mechanism here, since `CheckParamAliasesStage` (Phase 10) already resolves those same `campaigns.parameters` aliases into `payload->resolvedParams` earlier in the pipeline, which `BuildRawClickStage` (and therefore `ClickMacroValues`) already reads through. |

---
*Companion to `docs/TRAFFIC_CORE_PLAN.md` Phase 14 and `docs/PORTING_LOG.md`'s
matching entry — update this table if the macro set changes, don't let it
drift out of sync with `ClickMacroValues.php`.*
