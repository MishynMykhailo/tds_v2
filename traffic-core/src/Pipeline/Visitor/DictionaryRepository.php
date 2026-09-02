<?php

namespace TrafficCore\Pipeline\Visitor;

use TrafficCore\Db;

/**
 * Generic find-or-create-by-value repository for the `ref_*` dictionary
 * tables created by
 * `backend/database/migrations/2025_01_01_000029_create_visitors_and_geo_device_ref_tables.php`
 * (all fourteen `(id, value)`-shaped tables plus `ref_ips`).
 *
 * Legacy has no single equivalent class — it batches this per-field
 * across a whole click batch (see
 * `application/Component/Clicks/ClickProcessing/ExtractVisitors/
 * VisitorAggregator.php` and its per-dictionary siblings under the same
 * `ExtractVisitors/` directory, one repository class per dictionary
 * table). This project only processes one click per request, so a
 * single reusable method against a whitelisted table name replaces all
 * of those near-duplicate classes — same end result (a dictionary row
 * always exists for a given value, id reused on repeat), simpler for a
 * single-row case.
 *
 * Uses MySQL's `INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`
 * idiom for an atomic, race-safe find-or-create in one round trip (no
 * TOCTOU window between a SELECT and a fallback INSERT under concurrent
 * clicks for the same value).
 */
class DictionaryRepository
{
    /**
     * Every table this repository is allowed to touch — a fixed
     * whitelist since the table name is interpolated into raw SQL (never
     * take it from unsanitized input).
     */
    private const ALLOWED_TABLES = [
        'ref_ips',
        'ref_user_agents',
        'ref_countries',
        'ref_regions',
        'ref_cities',
        'ref_device_types',
        'ref_device_models',
        'ref_languages',
        'ref_browsers',
        'ref_browser_versions',
        'ref_os',
        'ref_os_versions',
        'ref_connection_types',
        'ref_operators',
        'ref_isp',
    ];

    /**
     * Find-or-create a dictionary row by its `value`. Returns null
     * without touching the DB when `$value` is null/empty — a missing
     * dimension (e.g. GeoDb returned no city) stays an absent FK, not an
     * empty-string row.
     *
     * @param string|int $value For `ref_ips`, the packed unsigned-int IP;
     *                          for every other table, the raw string value.
     */
    public function findOrCreateByValue(string $table, string|int|null $value): ?int
    {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException("Not a known ref_ dictionary table: {$table}");
        }

        if ($value === null || $value === '') {
            return null;
        }

        $pdo = Db::instance();
        $stmt = $pdo->prepare(
            "INSERT INTO {$table} (value) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );
        $stmt->execute([$value]);

        return (int) $pdo->lastInsertId();
    }
}
