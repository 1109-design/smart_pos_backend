<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decides version/duplicate push conflicts that are provably safe to
 * resolve without a human, so they never park in the pending-conflicts
 * inbox. Two rules, tried in order — anything else abstains (returns null)
 * and the conflict stays manual:
 *
 * Rule A — identical content: the incoming payload's business values all
 * equal the server row's (server may carry extra audit-only fields). No
 * value differs, so keeping the server changes nothing. Clears same-value
 * races like a till re-pushing stock-take counts the server already holds.
 *
 * Rule B — equivalent unique-key duplicate: an INSERT failed with 1062
 * because a row with the same unique key already exists AND every shared
 * business value matches (e.g. every till seeding its own UUID for unit
 * "piece"). Keeping the existing row loses nothing. Requires the loser
 * UUID to be unreferenced — callers must pass the tables whose FKs could
 * point at it; any hit abstains. Products reference units by plain text,
 * which is why the units case qualifies.
 *
 * Deliberately never touches: deletes vs edits, ownership mismatches,
 * invalid transitions, or any case where a business value actually
 * differs — those stay manual (see SyncController's own exclusions).
 */
class AutoConflictResolver
{
    /**
     * Sync-metadata keys that never count as business values.
     *
     * @var list<string>
     */
    private const META_KEYS = [
        'updated_at',
        'created_at',
        'synced_at',
        'deleted_at',
        'device_id',
        'business_id',
        '_dirty_fields',
        'id',
    ];

    /**
     * Rule A: every business value in $incoming equals the server's.
     * $server may be a superset (server-side audit fields are ignored).
     *
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>|null  $server
     */
    public function payloadsMatch(array $incoming, ?array $server): bool
    {
        if ($server === null) {
            return false;
        }

        foreach ($incoming as $key => $value) {
            if (in_array($key, self::META_KEYS, true)) {
                continue;
            }
            if (! array_key_exists($key, $server)) {
                return false;
            }
            if (! self::valuesEqual($value, $server[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rule B: parse the 1062 key name, find the existing domain row, and
     * confirm it is business-equivalent to the rejected payload.
     *
     * @param  array<string, mixed>  $payload  Enriched (tenant-forced) payload.
     * @param  list<string>  $referencedBy  `table.column` pairs that could FK
     *                                      the loser UUID — any hit abstains.
     */
    public function findEquivalentRow(
        string $table,
        array $payload,
        string $errorMessage,
        string $loserUuid,
        array $referencedBy = []
    ): ?object {
        $columns = self::uniqueKeyColumns($table, $errorMessage);
        if ($columns === null || ! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table);
        foreach ($columns as $column) {
            if (! array_key_exists($column, $payload)) {
                return null;
            }
            $query->where($column, $payload[$column]);
        }

        $existing = $query->first();
        if ($existing === null) {
            return null;
        }

        if (! $this->payloadsMatch($payload, (array) $existing)) {
            return null;
        }

        foreach ($referencedBy as $reference) {
            [$refTable, $refColumn] = explode('.', $reference, 2) + [null, null];
            if ($refTable === null || ! Schema::hasTable($refTable)) {
                continue;
            }
            if (DB::table($refTable)->where($refColumn, $loserUuid)->exists()) {
                return null;
            }
        }

        return $existing;
    }

    /**
     * Extract unique-key columns from a duplicate-entry message.
     * Supports both drivers this app runs on:
     * - MySQL: `Duplicate entry 'x' for key 'table_col1_col2_unique'`
     *   (Laravel names keys `{table}_{cols}_unique`). Column names
     *   themselves contain underscores, so the middle segment is matched
     *   greedily against the table's real columns instead of exploding.
     * - SQLite (tests/local): `UNIQUE constraint failed:
     *   table.col1, table.col2` — columns arrive qualified already.
     *
     * @return list<string>|null
     */
    public static function uniqueKeyColumns(string $table, string $errorMessage): ?array
    {
        if (preg_match("/for key '([^']+)'/", $errorMessage, $m)) {
            return self::columnsFromKeyName($table, $m[1]);
        }
        // SQLite appends " (Connection: ..., SQL: ...)" after the column
        // list — stop before any parenthesis.
        if (preg_match('/UNIQUE constraint failed:\s*([^()]+)/', $errorMessage, $m)) {
            $columns = [];
            foreach (explode(',', $m[1]) as $part) {
                $part = trim($part);
                $column = str_starts_with($part, $table.'.')
                    ? substr($part, strlen($table) + 1)
                    : $part;
                if ($column === '') {
                    return null;
                }
                $columns[] = $column;
            }

            return $columns === [] ? null : $columns;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function columnsFromKeyName(string $table, string $key): ?array
    {
        $prefix = $table.'_';
        if (! str_starts_with($key, $prefix) || ! str_ends_with($key, '_unique')) {
            return null;
        }
        $middle = substr($key, strlen($prefix), -strlen('_unique'));
        if ($middle === '' || ! Schema::hasTable($table)) {
            return null;
        }

        $columns = Schema::getColumnListing($table);
        // Longest match first so `business_id` wins over `business`.
        usort($columns, fn ($a, $b) => strlen($b) <=> strlen($a));

        $matched = [];
        $rest = $middle;
        while ($rest !== '') {
            $found = null;
            foreach ($columns as $column) {
                if ($rest === $column || str_starts_with($rest, $column.'_')) {
                    $found = $column;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $matched[] = $found;
            $rest = substr($rest, strlen($found));
            $rest = ltrim($rest, '_');
        }

        return $matched === [] ? null : $matched;
    }

    /**
     * Loose business-value equality: JSON decoding turns ints into strings
     * and MySQL returns tinyints/decimal strings, so strict comparison
     * would cry wolf on identical values. Nulls only equal nulls.
     */
    public static function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }
}
