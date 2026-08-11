<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress;

/**
 * `wpdb` subclass that routes every query through ePHPm's in-process DB
 * bridge (`ephpm_db_query()` / `ephpm_db_execute()`) instead of mysqli.
 *
 * There is no socket and no connection handle: `$this->dbh` stays null
 * for the object's whole life, and every method that would touch mysqli
 * is overridden. Everything else — `prepare()`, `insert()`, `update()`,
 * `delete()`, `get_results()`/`get_row()`/`get_var()`/`get_col()`,
 * `esc_like()`, table-name bookkeeping — is inherited from core `wpdb`
 * unchanged and operates on the state this class populates
 * (`last_result`, `num_rows`, `rows_affected`, `insert_id`,
 * `last_error`).
 *
 * Error handling keeps wpdb's contract: bridge exceptions are caught
 * and surfaced in-band via `$this->last_error` (and `query()` returning
 * false), never rethrown into calling code.
 *
 * This file can only be loaded after WordPress's own
 * `wp-includes/class-wpdb.php` has been required (which is always true
 * inside the `wp-content/db.php` drop-in flow, and arranged manually in
 * the test bootstrap).
 */
class Db extends \wpdb
{
    /** The bridge backend (SAPI functions in production, a fake in tests). */
    protected DbOpsInterface $dbOps;

    /** Rows staged by the last `_do_query()` when it routed to query(). */
    private ?array $bridgeRows = null;

    /** OK metadata staged by the last `_do_query()` when it routed to execute(). */
    private ?array $bridgeOk = null;

    /** Error message ('' = none) and MySQL errno from the last `_do_query()`. */
    private string $bridgeError = '';
    private int $bridgeErrno = 0;

    /** Column names of the last rowset, in select-list order. */
    private array $bridgeColNames = [];

    /** Cached `SELECT VERSION()` result. */
    private ?string $serverInfo = null;

    /**
     * @param string $dbuser     Ignored (no credentials — in-process bridge).
     * @param string $dbpassword Ignored.
     * @param string $dbname     Ignored (the embedded DB is the only DB).
     * @param string $dbhost     Ignored.
     */
    public function __construct(
        $dbuser = '',
        #[\SensitiveParameter]
        $dbpassword = '',
        $dbname = '',
        $dbhost = '',
        ?DbOpsInterface $dbOps = null
    ) {
        $this->dbOps = $dbOps ?? new SapiDbOps();
        parent::__construct($dbuser, $dbpassword, $dbname, $dbhost);
    }

    // ── Connection lifecycle: there is no connection ────────────────────

    /**
     * "Connects" — a no-op besides marking the handle ready. The bridge
     * has no socket; the per-thread litewire session on the Rust side is
     * opened lazily on the first `ephpm_db_*` call.
     *
     * @param bool $allow_bail Unused (there is nothing to bail on).
     * @return bool Always true.
     */
    public function db_connect($allow_bail = true)
    {
        $this->is_mysql = true; // MySQL dialect (litewire translates it).
        $this->dbh = null;

        if (!$this->has_connected) {
            $this->init_charset();
        }
        // Without a mysqli handle determine_charset() passes DB_CHARSET
        // through untouched; default to utf8mb4 like a modern server would.
        if (empty($this->charset)) {
            $this->charset = 'utf8mb4';
        }

        $this->has_connected = true;
        $this->ready = true;

        return true;
    }

    /**
     * The in-process bridge cannot "go away" like a TCP connection.
     *
     * @param bool $allow_bail Unused.
     * @return bool Whether the bridge is usable.
     */
    public function check_connection($allow_bail = true)
    {
        return (bool) $this->ready;
    }

    /** No-op: the embedded database is the only database. */
    public function select($db, $dbh = null)
    {
    }

    /** No-op: no mysqli handle to set a charset on. */
    public function set_charset($dbh, $charset = null, $collate = null)
    {
    }

    /** No-op: litewire answers `SET sql_mode` with an OK and ignores it. */
    public function set_sql_mode($modes = [])
    {
    }

    // ── Query execution ─────────────────────────────────────────────────

    /**
     * Performs a database query. Mirrors core `wpdb::query()` — same
     * filters, same charset validation, same return-value contract —
     * with the mysqli round trip replaced by the bridge.
     *
     * @param string $query Database query.
     * @return int|bool Boolean true for CREATE, ALTER, TRUNCATE and DROP
     *                  queries. Number of rows affected/selected for all
     *                  other queries. Boolean false on error.
     */
    public function query($query)
    {
        if (!$this->ready) {
            $this->check_current_query = true;

            return false;
        }

        if (\function_exists('apply_filters')) {
            /** This filter is documented in wp-includes/class-wpdb.php */
            $query = apply_filters('query', $query);
        }

        if (!$query) {
            $this->insert_id = 0;

            return false;
        }

        $this->flush();

        // Log how the function was called.
        $this->func_call = "\$db->query(\"$query\")";

        // If we're writing to the database, make sure the query will write safely.
        if ($this->check_current_query && !$this->check_ascii($query)) {
            $stripped_query = $this->strip_invalid_text_from_query($query);
            /*
             * strip_invalid_text_from_query() can perform queries, so we need
             * to flush again, just to make sure everything is clear.
             */
            $this->flush();
            if ($stripped_query !== $query) {
                $this->insert_id = 0;
                $this->last_query = $query;

                if (\function_exists('wp_load_translations_early')) {
                    wp_load_translations_early();
                }

                $this->last_error = \function_exists('__')
                    ? __('WordPress database error: Could not perform query because it contains invalid data.')
                    : 'WordPress database error: Could not perform query because it contains invalid data.';

                return false;
            }
        }

        $this->check_current_query = true;

        // Keep track of the last query for debug.
        $this->last_query = $query;

        $this->_do_query($query);

        // If there is an error then take note of it.
        if ('' !== $this->bridgeError) {
            $this->last_error = $this->bridgeError;

            // Clear insert_id on a subsequent failed insert.
            if ($this->insert_id && preg_match('/^\s*(insert|replace)\s/i', $query)) {
                $this->insert_id = 0;
            }

            $this->print_error($this->last_error);

            return false;
        }

        if (preg_match('/^\s*(create|alter|truncate|drop)\s/i', $query)) {
            $return_val = true;
        } elseif (preg_match('/^\s*(insert|delete|update|replace)\s/i', $query)) {
            $this->rows_affected = (int) ($this->bridgeOk['affected_rows'] ?? 0);

            // Take note of the insert_id.
            if (preg_match('/^\s*(insert|replace)\s/i', $query)) {
                $this->insert_id = (int) ($this->bridgeOk['last_insert_id'] ?? 0);
            }

            // Return number of rows affected.
            $return_val = $this->rows_affected;
        } else {
            $num_rows = 0;

            foreach ($this->bridgeRows ?? [] as $row) {
                /*
                 * Match stock wpdb's typing contract exactly: mysqli
                 * returns EVERY scalar column as a string (mysqlnd only
                 * types natively under MYSQLI_OPT_INT_AND_FLOAT_NATIVE,
                 * which wpdb never sets). The bridge hands us native
                 * int/float, so stringify them here — core and plugins
                 * strict-compare against string values (e.g.
                 * `'0' === get_comments_number()` in the comments-title
                 * block) and native types break those paths. NULL stays
                 * null, as it does under mysqli. Floats use PHP's default
                 * float-to-string conversion.
                 */
                foreach ($row as $col => $value) {
                    if (null !== $value && !\is_string($value)) {
                        $row[$col] = (string) $value;
                    }
                }
                $this->last_result[$num_rows] = (object) $row;
                ++$num_rows;
            }

            // Log and return the number of rows selected.
            $this->num_rows = $num_rows;
            $return_val = $num_rows;
        }

        return $return_val;
    }

    /**
     * Internal function to perform the bridge call, replacing core's
     * private `_do_query()` (which performs the mysqli_query() call).
     * Same SAVEQUERIES/timer/num_queries behavior as core.
     *
     * Statements that can produce a rowset route through
     * `ephpm_db_query()`; everything else routes through
     * `ephpm_db_execute()` so affected-rows/insert-id metadata is
     * captured. Bridge exceptions are staged, not rethrown — wpdb's
     * contract is error-in-band via `$last_error`.
     *
     * @param string $query The query to run.
     */
    private function _do_query($query)
    {
        if (\defined('SAVEQUERIES') && SAVEQUERIES) {
            $this->timer_start();
        }

        $this->bridgeRows = null;
        $this->bridgeOk = null;
        $this->bridgeError = '';
        $this->bridgeErrno = 0;
        $this->bridgeColNames = [];

        try {
            if ($this->is_rowset_query($query)) {
                $rows = $this->dbOps->query($query);
                $this->bridgeRows = $rows;
                if (isset($rows[0])) {
                    $this->bridgeColNames = array_keys($rows[0]);
                }
            } else {
                $this->bridgeOk = $this->dbOps->execute($query);
            }
        } catch (\Throwable $e) {
            $this->bridgeError = $e->getMessage();
            $this->bridgeErrno = (int) $e->getCode();
        }

        ++$this->num_queries;

        if (\defined('SAVEQUERIES') && SAVEQUERIES) {
            $this->log_query(
                $query,
                $this->timer_stop(),
                $this->get_caller(),
                $this->time_start,
                []
            );
        }
    }

    /**
     * Whether a statement should route through `ephpm_db_query()`
     * (rowset-shaped) rather than `ephpm_db_execute()` (OK-shaped).
     */
    protected function is_rowset_query(string $query): bool
    {
        $q = ltrim($query, " \t\r\n(");
        // Strip leading comments so /* hints */ don't confuse routing.
        while (preg_match('/^(?:\/\*.*?\*\/|--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$))\s*/s', $q, $m)) {
            $q = substr($q, \strlen($m[0]));
        }

        return (bool) preg_match('/^(?:SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH|VALUES|TABLE)\b/i', $q);
    }

    /**
     * The MySQL error number of the last failed query (1062, 1064, ...),
     * as reported by the bridge. 0 when the last query succeeded.
     */
    public function last_errno(): int
    {
        return $this->bridgeErrno;
    }

    // ── Escaping ────────────────────────────────────────────────────────

    /**
     * MySQL-style string escaping without mysqli. The SQL produced still
     * goes through litewire's MySQL-dialect parser, which decodes
     * backslash escapes exactly as a MySQL server would, so escape the
     * way mysql_real_escape_string() does: NUL, \n, \r, \, ', " and
     * Ctrl-Z become backslash sequences.
     *
     * Core's non-mysqli fallback is addslashes(), which misses \n, \r,
     * NUL and Ctrl-Z — that's why this override exists.
     *
     * @param string $data String to escape.
     * @return string Escaped string.
     */
    public function _real_escape($data)
    {
        if (!is_scalar($data)) {
            return '';
        }

        $escaped = strtr((string) $data, [
            "\0" => '\\0',
            "\n" => '\\n',
            "\r" => '\\r',
            '\\' => '\\\\',
            "'" => "\\'",
            '"' => '\\"',
            "\x1a" => '\\Z',
        ]);

        return $this->add_placeholder_escape($escaped);
    }

    // ── Charset determination without SHOW FULL COLUMNS ─────────────────

    /**
     * Core interrogates `SHOW FULL COLUMNS` to learn per-table charsets
     * for its invalid-text stripping. The embedded database stores utf8
     * (SQLite TEXT is utf8), so short-circuit to utf8mb4 — this keeps
     * core's charset validation on its pure-PHP regex path and off the
     * `CONVERT(... USING ...)` SQL path.
     *
     * @param string $table Unused.
     * @return string Always 'utf8mb4'.
     */
    protected function get_table_charset($table)
    {
        return 'utf8mb4';
    }

    /**
     * See {@see Db::get_table_charset()}.
     *
     * @param string $table  Unused.
     * @param string $column Unused.
     * @return string Always 'utf8mb4'.
     */
    public function get_col_charset($table, $column)
    {
        return 'utf8mb4';
    }

    // ── Result metadata ─────────────────────────────────────────────────

    /**
     * Synthesizes column metadata from the last rowset. The bridge
     * returns rows keyed by column name but no field metadata, so only
     * `name`/`orgname` are meaningful; the other mysqli field properties
     * are present (so `get_col_info()` calls don't error) but carry
     * placeholder values. An empty rowset has no column names at all —
     * a documented bridge limitation.
     */
    protected function load_col_info()
    {
        if ($this->col_info) {
            return;
        }
        if ([] === $this->bridgeColNames) {
            return;
        }

        $this->col_info = [];
        foreach (array_values($this->bridgeColNames) as $i => $name) {
            $this->col_info[$i] = (object) [
                'name' => $name,
                'orgname' => $name,
                'table' => '',
                'orgtable' => '',
                'def' => '',
                'db' => '',
                'catalog' => 'def',
                'max_length' => 0,
                'length' => 0,
                'charsetnr' => 0,
                'flags' => 0,
                'type' => 0,
                'decimals' => 0,
            ];
        }
    }

    // ── Server identity ─────────────────────────────────────────────────

    /**
     * Returns the version string the embedded database advertises
     * (`SELECT VERSION()` through the bridge — litewire answers
     * '8.0.0-litewire'). Cached for the object's lifetime. Falls back to
     * the same literal if the query fails so `db_version()` (inherited:
     * numeric prefix of this string) always yields a sane
     * version_compare() input.
     *
     * @return string Database server version string.
     */
    public function db_server_info()
    {
        if (null === $this->serverInfo) {
            $this->serverInfo = '8.0.0-litewire';
            try {
                $rows = $this->dbOps->query('SELECT VERSION()');
                if (isset($rows[0])) {
                    $first = reset($rows[0]);
                    if (\is_string($first) && '' !== $first) {
                        $this->serverInfo = $first;
                    }
                }
            } catch (\Throwable $e) {
                // Keep the fallback.
            }
        }

        return $this->serverInfo;
    }
}
