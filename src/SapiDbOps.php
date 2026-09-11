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

    public function run(string $sql, array $params = []): array
    {
        if (\function_exists('ephpm_db_run')) {
            /** @var array{has_rowset: bool, rows: list<array<string, int|float|string|null>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int} */
            return \ephpm_db_run($sql, $params);
        }

        // Floor-preserving fallback for an ePHPm predating ephpm_db_run
        // (< v0.6.3 introspection surface): classify by keyword, then
        // synthesize the unified shape. Column names for a zero-row result
        // come from ephpm_db_columns() when the build has it (also newer),
        // else from the first row — the pre-#262 best effort.
        if (self::mayReturnRows($sql)) {
            $rows = $this->query($sql, $params);
            $columns = \function_exists('ephpm_db_columns')
                ? \ephpm_db_columns()
                : self::columnsFromRows($rows);

            return [
                'has_rowset' => true,
                'rows' => $rows,
                'columns' => $columns,
                'affected_rows' => 0,
                'last_insert_id' => 0,
            ];
        }

        $ok = $this->execute($sql, $params);

        return [
            'has_rowset' => false,
            'rows' => [],
            'columns' => [],
            'affected_rows' => (int) ($ok['affected_rows'] ?? 0),
            'last_insert_id' => (int) ($ok['last_insert_id'] ?? 0),
        ];
    }

    /**
     * Keyword classifier used ONLY by the {@see run()} fallback when the
     * native `ephpm_db_run()` is absent. When the native is present it does
     * this classification authoritatively from the executed statement.
     */
    private static function mayReturnRows(string $sql): bool
    {
        $q = ltrim($sql, " \t\r\n(");
        while (preg_match('/^(?:\/\*.*?\*\/|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$))\s*/s', $q, $m)) {
            $q = substr($q, \strlen($m[0]));
        }

        return (bool) preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH|VALUES|TABLE)\b/i', $q);
    }

    /**
     * Best-effort column metadata from the first row, for the fallback path
     * on a build lacking ephpm_db_columns(). A zero-row result yields no
     * names here — the very gap #262 closes on a current ePHPm.
     *
     * @param list<array<string, int|float|string|null>> $rows
     *
     * @return list<array{name: string, type: ?string}>
     */
    private static function columnsFromRows(array $rows): array
    {
        if (!isset($rows[0])) {
            return [];
        }

        $columns = [];
        foreach (array_keys($rows[0]) as $name) {
            $columns[] = ['name' => (string) $name, 'type' => null];
        }

        return $columns;
    }
}
