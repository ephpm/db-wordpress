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
}
