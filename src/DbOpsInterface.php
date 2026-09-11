<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress;

/**
 * The two-call surface of ePHPm's in-process database bridge.
 *
 * Implementations execute MySQL-dialect SQL against the embedded
 * database and mirror the contract of the `ephpm_db_query()` /
 * `ephpm_db_execute()` SAPI functions exactly:
 *
 * - `query()` returns rows as a list of associative arrays keyed by
 *   column name. Integer/float columns come back as native PHP
 *   int/float, NULL as null, text/blob as string. A statement with no
 *   result set returns an empty array. A duplicate column name
 *   (SELECT a, a) keeps the last value.
 * - `execute()` returns `['affected_rows' => int, 'last_insert_id' => int]`.
 * - `$params` bind to `?` placeholders; only null, bool, int, float,
 *   and string values bind.
 * - Errors throw `\Exception` with `getCode()` = the MySQL error
 *   number (1062, 1064, ...) and a message shaped
 *   `SQLSTATE[xxxxx]: <backend message>`.
 */
interface DbOpsInterface
{
    /**
     * Execute SQL, returning the result rows.
     *
     * @param list<mixed> $params
     *
     * @return list<array<string, int|float|string|null>>
     *
     * @throws \Exception on a database error (code = MySQL errno).
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute SQL, returning the OK metadata.
     *
     * @param list<mixed> $params
     *
     * @return array{affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception on a database error (code = MySQL errno).
     */
    public function execute(string $sql, array $params = []): array;

    /**
     * Execute SQL once and report what it actually did — the unified entry
     * point mirroring the native `ephpm_db_run()` (ePHPm issue #263).
     *
     * `has_rowset` is the authoritative discriminator, read from the
     * executed statement rather than inferred from the SQL's first keyword.
     * `rows` is always an array (empty when `has_rowset` is false).
     * `columns` carries the column metadata as a list of
     * `['name' => string, 'type' => ?string]`, present even for a zero-row
     * result set (ePHPm issue #262) — which the rows alone cannot supply.
     * `affected_rows`/`last_insert_id` are zero for a result set.
     *
     * @param list<mixed> $params
     *
     * @return array{has_rowset: bool, rows: list<array<string, int|float|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int}
     *
     * @throws \Exception on a database error (code = MySQL errno).
     */
    public function run(string $sql, array $params = []): array;
}
