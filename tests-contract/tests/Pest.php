<?php

/*
|--------------------------------------------------------------------------
| Contract test bootstrap
|--------------------------------------------------------------------------
|
| This suite is a standalone Composer package (not part of the Laravel
| rewrite) so it can be pointed at ANY backend implementing the documented
| admin-panel API contract via the TDS_TEST_TARGET env var, e.g.:
|
|   TDS_TEST_TARGET=http://localhost:8090 vendor/bin/pest
|   TDS_TEST_TARGET=http://host.docker.internal:8090 vendor/bin/pest
|
| See docs/legacy-reference/frontend/backend_api_reference.md for the
| contract itself (§2 routing, §4 auth, §6 error format, §7 params,
| §10.1 Campaigns).
|
*/
