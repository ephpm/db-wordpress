<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress;

/**
 * Backend that calls the global `ephpm_db_query()` / `ephpm_db_execute()`
 * functions registered by the ePHPm SAPI. Refuses to construct if those
 * functions aren't present so we fail fast outside the runtime instead
 * of producing "Call to undefined function" errors at request time.
 *
 * The SAPI functions execute SQL through a per-thread litewire Session on
 * the Rust side — the same MySQL-dialect translation the wire frontend
 * serves, without a TCP round trip.
 */
final class SapiDbOps implements DbOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_db_query')) {
            throw new \RuntimeException(
                'ephpm DB SAPI functions are not available. '
                . 'This database drop-in only works inside the ePHPm runtime '
                . 'with [db.sqlite] active; use '
                . 'Ephpm\\Db\\WordPress\\PdoSqliteDbOps in tests.'
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        /** @var list<array<string, int|float|string|null>> */
        return \ephpm_db_query($sql, $params);
    }

    public function execute(string $sql, array $params = []): array
    {
        /** @var array{affected_rows: int, last_insert_id: int} */
        return \ephpm_db_execute($sql, $params);
    }
}
