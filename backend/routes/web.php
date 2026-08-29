<?php

use App\Http\Controllers\ObjectDispatchController;
use App\Http\Middleware\LegacyAuthMiddleware;
use Illuminate\Support\Facades\Route;

// Compatibility layer — replicates the old `?object=controller.action`
// contract. See App\Http\Controllers\ObjectDispatchController and
// docs/legacy-reference/frontend/backend_api_reference.md §2.
//
// LegacyAuthMiddleware re-verifies the "states" cookie on every request here
// and populates CurrentUserService (§4.1) — it never blocks the request
// itself, see the middleware's docblock.
Route::match(['get', 'post'], '/admin/index.php', [ObjectDispatchController::class, 'handle'])
    ->middleware(LegacyAuthMiddleware::class);
