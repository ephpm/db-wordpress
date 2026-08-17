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

        // Rewrite the MySQL-only DML WordPress core emits that the embedded
        // engine rejects (multi-table DELETE, INSERT ... ON DUPLICATE KEY
        // UPDATE). `last_query` and the 'query' filter keep the original.
        $bridgeSql = self::translateMysqlToBridge($query);

        try {
            if ($this->is_rowset_query($bridgeSql)) {
                $rows = $this->dbOps->query($bridgeSql);
                $this->bridgeRows = $rows;
                if (isset($rows[0])) {
                    $this->bridgeColNames = array_keys($rows[0]);
                }
            } else {
                $this->bridgeOk = $this->dbOps->execute($bridgeSql);
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

    // ── MySQL-dialect fix-ups the embedded engine can't do ───────────────

    /**
     * Rewrite the two MySQL-only DML constructs WordPress core emits that
     * the embedded engine (litewire → Turso) will not execute, into forms
     * both Turso and `pdo_sqlite` accept. Everything else — including the
     * MySQL functions litewire already translates (CONCAT, SUBSTRING,
     * REGEXP, LIKE) and backtick identifiers — is passed through
     * untouched.
     *
     * Applied at the bridge boundary only (see {@see Db::_do_query()}), so
     * `last_query`, the SAVEQUERIES log and the 'query' filter all still
     * observe the original MySQL text.
     */
    public static function translateMysqlToBridge(string $sql): string
    {
        $sql = self::rewriteOnDuplicateKeyUpdate($sql);
        $sql = self::rewriteInsertIgnore($sql);
        $sql = self::rewriteMultiTableDelete($sql);
        $sql = self::rewriteAliasJoinDelete($sql);
        $sql = self::rewriteTruncate($sql);
        $sql = self::stripOnUpdateCurrentTimestamp($sql);
        $sql = self::rewriteUnsupportedAlter($sql);
        $sql = self::rewriteInformationSchemaIndexProbe($sql);
        $sql = self::stripFromDual($sql);

        return $sql;
    }

    /**
     * `INSERT IGNORE INTO …` → `INSERT OR IGNORE INTO …`. MySQL's IGNORE
     * modifier (skip rows that would violate a constraint) maps exactly to
     * SQLite's `OR IGNORE` conflict clause. The `INSERT IGNORE` +
     * `ON DUPLICATE KEY UPDATE` combination is handled earlier by
     * {@see Db::rewriteOnDuplicateKeyUpdate()} (→ INSERT OR REPLACE), so by
     * the time this runs only a plain `INSERT IGNORE` remains. WooCommerce's
     * lookup-table population (`wp_wc_category_lookup`) uses it.
     */
    public static function rewriteInsertIgnore(string $sql): string
    {
        $out = preg_replace('/^(\s*)INSERT\s+IGNORE\s+INTO\b/i', '${1}INSERT OR IGNORE INTO', $sql, 1, $count);

        return (\is_string($out) && $count > 0) ? $out : $sql;
    }

    /**
     * A WooCommerce / plugin index-existence probe against
     * `INFORMATION_SCHEMA.STATISTICS` — which the embedded engine has no
     * equivalent for — is rewritten to `SELECT 1`, reporting the index as
     * present so the caller skips the follow-up `ALTER TABLE … ADD/DROP KEY`
     * that SQLite cannot perform anyway (the key was fixed at CREATE time).
     */
    public static function rewriteInformationSchemaIndexProbe(string $sql): string
    {
        if (preg_match('/^\s*SELECT\b.*\bFROM\s+INFORMATION_SCHEMA\.STATISTICS\b/is', $sql)) {
            return 'SELECT 1';
        }

        return $sql;
    }

    /**
     * `INSERT ... ON DUPLICATE KEY UPDATE col = VALUES(col), ...`
     * → `INSERT OR REPLACE INTO ...` (the ON DUPLICATE clause dropped).
     *
     * WordPress only ever uses ON DUPLICATE KEY UPDATE to overwrite the
     * conflicting row with the values just supplied — every assignment is
     * `col = VALUES(col)` (add_option's options upsert, set_object_terms'
     * term_relationships upsert) — which is exactly SQLite's REPLACE
     * semantics. Turso does **not** honour `ON CONFLICT ... DO UPDATE`
     * (it raises the UNIQUE violation instead of upserting), so REPLACE —
     * not an SQLite upsert — is the portable target that works on both
     * Turso and pdo_sqlite.
     *
     * The clause is matched on its LAST occurrence (greedy prefix) so a
     * literal containing the phrase "ON DUPLICATE KEY UPDATE" inside the
     * inserted VALUES is never mistaken for the real clause.
     *
     * Caveat: REPLACE deletes and re-inserts the conflicting row, so an
     * AUTOINCREMENT surrogate key (e.g. wp_options.option_id) is
     * reassigned. WordPress addresses options by option_name and terms by
     * (object_id, term_taxonomy_id), never by those surrogate ids, so this
     * is transparent to core.
     */
    public static function rewriteOnDuplicateKeyUpdate(string $sql): string
    {
        if (!preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
            return $sql;
        }
        if (!preg_match('/^\s*INSERT\b/i', $sql)) {
            return $sql;
        }

        // Drop the trailing ON DUPLICATE KEY UPDATE ... clause. The greedy
        // `(.*)` prefix anchors on the last occurrence in the statement.
        $stripped = preg_replace(
            '/^(.*)\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\b.*$/is',
            '$1',
            $sql
        );
        if (!\is_string($stripped)) {
            return $sql;
        }

        // INSERT [IGNORE] INTO -> INSERT OR REPLACE INTO.
        $rewritten = preg_replace(
            '/^(\s*)INSERT\s+(?:IGNORE\s+)?INTO\b/i',
            '${1}INSERT OR REPLACE INTO',
            $stripped,
            1,
            $count
        );

        // If there was no INTO to anchor on, leave the statement alone
        // rather than emit an INSERT that would still fail on conflict.
        if (!\is_string($rewritten) || $count === 0) {
            return $sql;
        }

        return $rewritten;
    }

    /**
     * `DELETE a, b FROM t a, t b WHERE ...` (MySQL multi-table DELETE)
     * → `DELETE FROM t WHERE rowid IN (
     *        SELECT a.rowid FROM t a, t b WHERE ...
     *        UNION SELECT b.rowid FROM t a, t b WHERE ...)`.
     *
     * Only the self-join shape WordPress core emits is rewritten — every
     * delete-target alias must resolve to the same base table, as in
     * `delete_expired_transients()` (`DELETE a, b FROM wp_options a,
     * wp_options b ...` / the wp_sitemeta variant). The rows to delete are
     * addressed by `rowid`, which is stable for the ordinary (rowid)
     * tables WordPress uses. Anything that does not match this shape —
     * including an ordinary single-table `DELETE FROM t WHERE ...` — is
     * returned unchanged.
     */
    public static function rewriteMultiTableDelete(string $sql): string
    {
        // DELETE <alias>, <alias>[, ...] FROM <from-list> WHERE <cond>
        if (!preg_match(
            '/^\s*DELETE\s+([a-zA-Z_][a-zA-Z0-9_]*(?:\s*,\s*[a-zA-Z_][a-zA-Z0-9_]*)+)'
            . '\s+FROM\s+(.+?)\s+WHERE\s+(.*)$/is',
            $sql,
            $m
        )) {
            return $sql;
        }

        $targets = preg_split('/\s*,\s*/', trim($m[1])) ?: [];
        $fromClause = trim($m[2]);
        $where = $m[3];

        // Map each "<table> [AS] <alias>" reference to its base table.
        $aliasTable = [];
        foreach (preg_split('/\s*,\s*/', $fromClause) ?: [] as $ref) {
            if (preg_match(
                '/^\s*(`[^`]+`|[a-zA-Z_][a-zA-Z0-9_]*)\s+(?:AS\s+)?([a-zA-Z_][a-zA-Z0-9_]*)\s*$/i',
                $ref,
                $rm
            )) {
                $aliasTable[$rm[2]] = $rm[1];
            }
        }

        // Every delete target must resolve to the same base table for the
        // single-table rowid rewrite to be equivalent.
        $outerTable = null;
        foreach ($targets as $t) {
            if (!isset($aliasTable[$t])) {
                return $sql;
            }
            if ($outerTable === null) {
                $outerTable = $aliasTable[$t];
            } elseif ($aliasTable[$t] !== $outerTable) {
                return $sql;
            }
        }
        if ($outerTable === null) {
            return $sql;
        }

        $selects = [];
        foreach ($targets as $t) {
            $selects[] = "SELECT {$t}.rowid FROM {$fromClause} WHERE {$where}";
        }

        return "DELETE FROM {$outerTable} WHERE rowid IN ("
            . implode(' UNION ', $selects) . ')';
    }

    /**
     * `DELETE <alias> FROM <table> <alias> <join…> WHERE …` — the
     * single-target MySQL multi-table delete (one delete target, joined to
     * one or more other references), as Yoast SEO's indexable de-duplication
     * emits (`DELETE wyi FROM wp_yoast_indexable wyi INNER JOIN
     * wp_yoast_indexable wyi2 WHERE …`).
     *
     * → `DELETE FROM <table> WHERE rowid IN (SELECT <alias>.rowid FROM
     *    <table> <alias> <join…> WHERE …)`.
     *
     * The delete-target alias must be the alias bound to the immediately
     * following base table; otherwise the statement is returned unchanged.
     * The comma-separated multi-target shape is handled by
     * {@see Db::rewriteMultiTableDelete()} and runs first.
     */
    public static function rewriteAliasJoinDelete(string $sql): string
    {
        if (!preg_match(
            '/^\s*DELETE\s+(`?\w+`?)\s+FROM\s+(`?[\w.]+`?)\s+(`?\w+`?)\b(.*)$/is',
            $sql,
            $m
        )) {
            return $sql;
        }

        $targetAlias = trim($m[1], '`');
        $tableAlias = trim($m[3], '`');
        if (0 !== strcasecmp($targetAlias, $tableAlias)) {
            return $sql;
        }

        return 'DELETE FROM ' . $m[2]
            . ' WHERE rowid IN (SELECT ' . $m[3] . '.rowid FROM '
            . $m[2] . ' ' . $m[3] . $m[4] . ')';
    }

    /**
     * `TRUNCATE [TABLE] <t>` → `DELETE FROM <t>`. SQLite has no TRUNCATE;
     * plugins (Yoast SEO's reset routines) use it to empty their tables.
     */
    public static function rewriteTruncate(string $sql): string
    {
        if (preg_match('/^\s*TRUNCATE\s+(?:TABLE\s+)?(`?[A-Za-z0-9_.]+`?)\s*;?\s*$/is', $sql, $m)) {
            return 'DELETE FROM ' . $m[1];
        }

        return $sql;
    }

    /**
     * Strip the MySQL `ON UPDATE CURRENT_TIMESTAMP` column attribute (with
     * optional fractional-seconds precision). SQLite has no auto-update
     * timestamp; the `DEFAULT CURRENT_TIMESTAMP` it follows is accepted
     * as-is by the embedded engine. Applies to CREATE and ALTER column
     * definitions alike (Yoast SEO's `created_at`/`updated_at` columns).
     */
    public static function stripOnUpdateCurrentTimestamp(string $sql): string
    {
        $out = preg_replace(
            '/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\s*\(\s*\d*\s*\))?/i',
            '',
            $sql
        );

        return \is_string($out) ? $out : $sql;
    }

    /**
     * No-op the MySQL-only `ALTER TABLE` forms SQLite cannot perform:
     *
     *  - `ALTER TABLE … CONVERT TO CHARACTER SET …` — charset is fixed
     *    (SQLite TEXT is UTF-8), so the conversion is meaningless.
     *  - `ALTER TABLE <t> CHANGE|MODIFY …` (when the statement is exclusively
     *    column re-typing, i.e. contains no ADD/DROP clause) — SQLite cannot
     *    change a column's type or name in place, and its dynamic typing
     *    makes the declared type cosmetic, so the existing column stands.
     *
     * Both are common in plugin schema-normalisation passes (Yoast SEO,
     * ActionScheduler bundled by WPForms/WooCommerce). Rewritten to
     * `SELECT 1` so the statement succeeds without altering anything.
     */
    public static function rewriteUnsupportedAlter(string $sql): string
    {
        $trimmed = ltrim($sql);

        if (!preg_match('/^ALTER\s+TABLE\b/i', $trimmed)) {
            return $sql;
        }

        if (preg_match('/\bCONVERT\s+TO\s+CHARACTER\s+SET\b/is', $trimmed)) {
            return 'SELECT 1';
        }

        // `DROP PRIMARY KEY` (usually paired with re-adding a PRIMARY/UNIQUE
        // KEY) cannot be expressed in SQLite at all — the key is part of the
        // CREATE TABLE. These statements are idempotent schema-normalisation
        // upgrades (WooCommerce's order-lookup / sessions / downloadable
        // permissions tables) whose target key already exists, so no-op them.
        if (preg_match('/\bDROP\s+PRIMARY\s+KEY\b/is', $trimmed)) {
            return 'SELECT 1';
        }

        // Column re-typing/renaming SQLite cannot do in place, when the
        // statement is exclusively CHANGE/MODIFY (no ADD/DROP we would drop).
        if (
            preg_match('/^ALTER\s+TABLE\s+\S+\s+(?:CHANGE|MODIFY)\b/is', $trimmed)
            && !preg_match('/\b(?:ADD|DROP)\s/i', $trimmed)
        ) {
            return 'SELECT 1';
        }

        return $sql;
    }

    /**
     * Drop the MySQL `FROM dual` dummy table. A `SELECT … WHERE …` with no
     * FROM clause is valid SQLite, so `… FROM dual WHERE …` becomes
     * `… WHERE …`. ActionScheduler's idempotent insert
     * (`INSERT … SELECT … FROM dual WHERE NOT EXISTS (…)`) relies on it.
     */
    public static function stripFromDual(string $sql): string
    {
        $out = preg_replace('/\bFROM\s+dual\b/i', '', $sql);

        return \is_string($out) ? $out : $sql;
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
     * goes through litewire's MySQL-dialect parser, which decodes backslash
     * escapes as a MySQL server would (`\n`, `\r`, `\0`, Ctrl-Z as `\Z`,
     * `\\`, `\"`), so those specials become backslash sequences — matching
     * mysql_real_escape_string() and covering what core's non-mysqli
     * addslashes() fallback misses (`\n`, `\r`, NUL, Ctrl-Z).
     *
     * The **single quote is the exception**: it is doubled (`''`), not
     * backslash-escaped (`\'`). litewire's tenant-path parser rejects a
     * backslash-escaped single quote — `'O\'Reilly'` fails with
     * "statement type `malformed SQL` is not permitted" — whereas `''` is
     * accepted by both MySQL and SQLite/Turso. Any WordPress content with
     * an apostrophe (e.g. the twentytwentyfive theme's block-pattern
     * transient, which 500s the installer) hits this. Doubling is
     * unambiguous here because backslashes are still doubled, so a string
     * can never be broken out of. See
     * https://github.com/ephpm/db-wordpress/issues/1.
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
            "'" => "''",
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
