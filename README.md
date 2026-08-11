# ephpm/db-wordpress

WordPress [database drop-in](https://developer.wordpress.org/reference/classes/wpdb/)
(`wp-content/db.php`) backed by [ePHPm](https://ephpm.dev)'s in-process DB
bridge via the `ephpm_db_query()` / `ephpm_db_execute()` SAPI functions.
The same `wpdb` API WordPress core already uses — `$wpdb->get_results()`,
`$wpdb->insert()`, `$wpdb->prepare()` — served by the embedded SQLite
database in the same OS process. No mysqli, no socket, no MySQL server.

```php
// Anywhere in WordPress, once wp-content/db.php is in place:
$wpdb->insert( 'wp_options', [ 'option_name' => 'greeting', 'option_value' => 'hi' ] );
$wpdb->insert_id;                                             // 1
$wpdb->get_var( "SELECT option_value FROM wp_options WHERE option_name = 'greeting'" ); // 'hi'
```

Each query resolves to a direct C function call into the Rust side of
ePHPm, where a per-thread [litewire](https://github.com/ephpm/litewire)
session translates the MySQL-dialect SQL to SQLite — the **same**
translation the MySQL wire frontend serves, without the TCP round trip.
`SHOW TABLES`, `DESCRIBE`, `information_schema` queries, `SET NAMES`,
and `BEGIN`/`COMMIT`/`ROLLBACK` behave exactly as they do over the wire.

> **Requires unreleased ePHPm.** The `ephpm_db_*` SAPI functions exist
> only on ePHPm **main** (merged in
> [ephpm#257](https://github.com/ephpm/ephpm/pull/257)) — they are **not
> in any tagged release** (newest at time of writing: v0.6.2). And they
> are only registered when `[db.sqlite]` is active. On anything else,
> the drop-in cleanly falls back to the stock mysqli `wpdb` (see
> [Graceful fallback](#graceful-fallback)).

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Graceful fallback](#graceful-fallback)
- [How the drop-in finds its classes](#how-the-drop-in-finds-its-classes)
- [What is implemented](#what-is-implemented)
- [Behavior notes and limitations](#behavior-notes-and-limitations)
- [Transactions](#transactions)
- [Testing without ePHPm](#testing-without-ephpm)
- [Smoke-testing under a real ePHPm build](#smoke-testing-under-a-real-ephpm-build)
- [Troubleshooting](#troubleshooting)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **WordPress** with database drop-in support (every supported WP
  release loads `wp-content/db.php` from `require_wp_db()`). WordPress
  core is pulled in as a **dev dependency only** (the test suite runs
  against the real `class-wpdb.php`); the shipped package has no
  WordPress Composer dependency.
- **The ePHPm runtime, built from main**, with `[db.sqlite]` configured.
  The global `ephpm_db_query()` / `ephpm_db_execute()` functions are
  registered by ePHPm's embedded PHP; under PHP-FPM, Apache mod_php, or
  stock php-cli they don't exist and the drop-in steps aside.
- **mysqli is NOT required.** WordPress core skips its "missing the
  MySQL extension" check whenever `wp-content/db.php` exists (see
  `wp_check_php_mysql_versions()` in `wp-includes/load.php`). ePHPm's
  embedded PHP ships mysqli anyway, but this drop-in never calls it.

You can confirm the SAPI is present from any PHP file with:

```php
var_dump(function_exists('ephpm_db_query'));   // expect bool(true)
```

If you get `false`, you're not running inside ePHPm — or ePHPm is
running without `[db.sqlite]`.

---

## Install

```bash
composer require ephpm/db-wordpress
```

Then activate the drop-in by copying it into `wp-content/`:

```bash
cp vendor/ephpm/db-wordpress/dropin/db.php wp-content/db.php
```

(Symlinking works too if your deployment allows it.) WordPress loads
`wp-content/db.php` automatically from `require_wp_db()` — no plugin to
activate. Your `wp-config.php` still needs the `DB_*` constants defined
(WordPress reads them early), but their values are ignored by the
bridge; there are no credentials and only one database.

---

## Graceful fallback

`require_wp_db()`'s contract is: core loads `class-wpdb.php`, then your
`db.php`, and constructs the stock `wpdb` itself **unless the drop-in
set `$wpdb`**. This drop-in only sets `$wpdb` when `ephpm_db_query()`
exists. So:

- **Inside ePHPm with `[db.sqlite]`** → `$wpdb` is the bridge-backed
  subclass; no mysqli connection is ever attempted.
- **Anywhere else** (plain hosting, ePHPm with `[db.mysql]` proxy mode,
  older ePHPm) → the drop-in logs one notice and returns without
  touching `$wpdb`; WordPress constructs the stock mysqli `wpdb` against
  `DB_HOST` exactly as if no drop-in were installed.

A site can therefore carry this file permanently and move between
runtimes without editing anything.

---

## How the drop-in finds its classes

WordPress loads `db.php` **very early** — before plugins and, usually,
before any Composer autoloader has run. The drop-in therefore loads the
package's classes itself, trying these locations in order:

1. A `EPHPM_DB_AUTOLOAD` constant pointing at a `vendor/autoload.php`.
   Define it in `wp-config.php` if your layout is unusual:
   ```php
   define('EPHPM_DB_AUTOLOAD', '/srv/app/vendor/autoload.php');
   ```
2. `WP_CONTENT_DIR/vendor/autoload.php`
3. `ABSPATH/vendor/autoload.php`
4. `vendor/autoload.php` relative to the drop-in (and a couple of parent
   climbs), covering the case where the drop-in still lives inside the
   installed package.
5. As a last resort, it `require_once`s the package's `src/*.php` files
   directly, resolved relative to the drop-in's own location.

If none of those resolve `Ephpm\Db\WordPress\Db`, the drop-in does
**nothing** — WordPress falls back to the stock mysqli `wpdb` and a
notice is written to the PHP error log, rather than fataling the site.

---

## What is implemented

`Ephpm\Db\WordPress\Db` subclasses core `wpdb` and replaces only the
mysqli-touching internals. Everything else is core's own code operating
on the state this class populates.

| `wpdb` surface | Status |
|---|---|
| `query()` | **reimplemented** — same filters, charset validation, and return contract as core, with the mysqli round trip replaced by the bridge. Rowset-shaped statements (`SELECT`/`SHOW`/`DESCRIBE`/`DESC`/`EXPLAIN`/`WITH`/`VALUES`/`TABLE`) route through `ephpm_db_query()`; everything else through `ephpm_db_execute()` |
| `insert_id`, `rows_affected`, `num_rows`, `last_result` | **populated** from the bridge (`last_result` rows are `stdClass`, matching core's `mysqli_fetch_object`) |
| `last_error` | **populated** in-band from caught bridge exceptions (`SQLSTATE[xxxxx]: <message>`); exceptions never escape into calling code. The MySQL errno is additionally exposed via `Db::last_errno()` |
| `db_connect()`, `check_connection()` | **reimplemented** as no-ops — there is no socket to open or lose; `$wpdb->dbh` stays `null` for the object's life |
| `db_server_info()` | **reimplemented** — `SELECT VERSION()` through the bridge (litewire answers `8.0.0-litewire`), cached; same-string fallback if the query fails. `db_version()` is inherited on top and yields `8.0.0` |
| `_real_escape()` | **reimplemented** — `mysql_real_escape_string()`-equivalent escaping in PHP (NUL, `\n`, `\r`, `\`, `'`, `"`, Ctrl-Z), no mysqli. litewire's MySQL-dialect parser decodes these exactly as a MySQL server would |
| `select()`, `set_charset()`, `set_sql_mode()` | **no-ops** — one database, no handle; litewire already answers `SET sql_mode` with an OK |
| `get_table_charset()`, `get_col_charset()` | **reimplemented** — constant `utf8mb4` (SQLite TEXT is UTF-8), keeping core's invalid-text stripping on its pure-PHP path instead of `SHOW FULL COLUMNS` + `CONVERT()` SQL |
| `load_col_info()` | **reimplemented** — column names synthesized from the last rowset; see limitations |
| `prepare()` (incl. `%i`, `%d`, `%f`, `%s`, literal `%%`), `esc_like()` | **inherited** — verified working atop `_real_escape()` in the test suite; not reimplemented |
| `insert()`, `replace()`, `update()`, `delete()` | **inherited** — build SQL via `prepare()` and call `query()` |
| `get_var()`, `get_row()`, `get_col()`, `get_results()` (OBJECT / ARRAY_A / ARRAY_N / OBJECT_K) | **inherited** — operate on `last_result` |
| `tables()`, `set_prefix()`, `set_blog_id()`, `get_charset_collate()`, `has_cap()`, `flush()`, `timer_start()`/`timer_stop()`, `log_query()` + SAVEQUERIES, `$num_queries`, `$EZSQL_ERROR` | **inherited** — pure PHP in core, no mysqli involved |
| `close()` | **inherited** — always returns `false` (there is no connection to close) |
| Multi-server / HyperDB-style routing, read/write splitting | **not supported** — out of scope; there is exactly one embedded database |
| mysqli pass-through (`$wpdb->dbh`, `mysqli_result` access) | **not supported** — `$dbh` is always `null`; code that reaches into it for raw mysqli calls will not work |
| `get_col_length()` | **degraded** — always returns `false` (no `SHOW FULL COLUMNS` interrogation), so core skips its PHP-side length truncation. SQLite does not enforce `VARCHAR(n)` lengths anyway |

Native types survive the bridge: integer columns arrive as PHP `int`,
floats as `float`, `NULL` as `null` — same as mysqlnd with default
settings.

---

## Behavior notes and limitations

SQLite sits underneath, behind litewire's MySQL-dialect translation.
The differences this package's authors have actually verified:

- **Escaped `LIKE` wildcards behave differently.** `wpdb::esc_like()`
  escapes `%`/`_` with a backslash, and MySQL's `LIKE` strips that
  backslash during pattern evaluation. SQLite's `LIKE` has **no default
  escape character**, and litewire (at the pinned revision) does not add
  an `ESCAPE '\'` clause when translating — so a pattern like `50\%`
  matches a literal backslash on SQLite instead of a literal `%`.
  Unescaped wildcards (`LIKE 'prefix%'`, the overwhelmingly common
  WordPress case) work correctly and are covered by the test suite.
- **Empty rowsets carry no column metadata.** `ephpm_db_query()`
  returns rows keyed by column name and nothing else, so a `SELECT`
  matching zero rows yields no column names — `get_col_info()` returns
  `null` after it. With rows present, only the `name`/`orgname` fields
  of `get_col_info()` objects are meaningful; the other mysqli field
  properties exist but carry placeholder values.
- **Duplicate select-list column names collapse.** `SELECT a, a` or
  un-aliased same-named join columns keep the **last** value per name —
  the same behavior as stock wpdb, whose `last_result` also comes from
  `mysqli_fetch_object()`. Alias your columns if you need both values.
- **Errors are MySQL-shaped.** `last_error` reads
  `SQLSTATE[xxxxx]: <backend message>` and `Db::last_errno()` reports
  the mapped MySQL errno (1062 duplicate key, 1064 parse error, ...),
  as produced by litewire's error mapping.
- **`SHOW`/`DESCRIBE`/`information_schema` are emulated by litewire**
  (this is what `dbDelta()` and the WordPress installer lean on). This
  package's own suite does not exercise `dbDelta()`; that emulation is
  implemented and tested in litewire/ePHPm, not here.
- **`wp_check_php_mysql_versions()` is satisfied** by the mere presence
  of `wp-content/db.php` — core only demands mysqli when there is no
  drop-in.

---

## Transactions

`BEGIN` / `COMMIT` / `ROLLBACK` flow through `$wpdb->query()` as plain
SQL and behave exactly as on ePHPm's MySQL wire path: the per-thread
bridge session tracks transaction state.

Commit (or roll back) explicitly before your response is done — that's
the correct shape for request-scoped work, same as it would be against
a real MySQL server.

If a script doesn't — including when a mid-request PHP fatal makes
finishing impossible — the transaction is **not** leaked: at request
end the server rolls back any transaction left open on the bridge
session and logs a warning
([ephpm#258](https://github.com/ephpm/ephpm/pull/258), on ePHPm main
alongside the bridge itself; the teardown covers both fpm-style and
worker-mode execution and is proven by ePHPm's E2E suite). Treat that
rollback as the safety net it is — an abandoned transaction still means
the request's writes are discarded, and the warning in the server log
is telling you the script has a bug.

---

## Testing without ePHPm

The `Db` engine takes an optional `DbOpsInterface`, so the whole wpdb
surface can be exercised on plain php-cli with no WordPress boot and no
ePHPm runtime. `PdoSqliteDbOps` is a **test/dev-only** stand-in backed
by `pdo_sqlite` that approximates the bridge contract — including
MySQL string-literal decoding and MySQL-shaped errors — but does *not*
emulate `SHOW`/`DESCRIBE` or MySQL DDL translation:

```php
use Ephpm\Db\WordPress\Db;
use Ephpm\Db\WordPress\PdoSqliteDbOps;

$db = new Db('', '', '', '', new PdoSqliteDbOps());
$db->query('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
$db->insert('t', ['name' => "O'Reilly"]);
assert($db->insert_id === 1);
assert($db->get_var('SELECT name FROM t') === "O'Reilly");
```

The test suite pulls **real WordPress core** (`roots/wordpress-no-content`,
dev-only) and loads its actual `class-wpdb.php`, so `prepare()`,
`insert()`, output modes, escaping, and the SAVEQUERIES logging are
core's own code under test — not a re-implementation. Run it with:

```bash
composer install
composer test
```

---

## Smoke-testing under a real ePHPm build

This package's CI runs on php-cli against the `PdoSqliteDbOps` fake
only. **It has not been integration-tested against a live ePHPm main
build as part of this release.** To smoke it yourself:

```bash
# 1. Build ePHPm from main (the ephpm_db_* functions are not in any release):
git clone https://github.com/ephpm/ephpm && cd ephpm
cargo xtask release                      # → target/release/ephpm

# 2. Point it at a WordPress docroot with the drop-in installed and
#    [db.sqlite] active:
cat > ephpm.toml <<'EOF'
[server]
listen = "127.0.0.1:8080"
document_root = "/srv/wordpress"

[db.sqlite]
path = "/srv/wordpress-data/wp.db"
EOF
./target/release/ephpm serve --config ephpm.toml

# 3. Verify from PHP inside the docroot:
#    var_dump(function_exists('ephpm_db_query'));  // true
#    then run the WordPress installer normally.
```

---

## Troubleshooting

### The site connects to MySQL instead of the embedded database

The drop-in fell back. Check the PHP error log for
`ephpm db.php: ephpm_db_query() is not available` — you're not running
inside ePHPm, or `[db.sqlite]` isn't configured (the functions are only
registered when the embedded database is active). Remember the bridge
requires an ePHPm **main** build, not v0.6.2 or older.

### `ephpm db.php: could not locate Ephpm\Db\WordPress\Db`

The drop-in couldn't find the package classes and fell back to the
stock wpdb. Install via Composer in a standard layout or set
`EPHPM_DB_AUTOLOAD` in `wp-config.php` (see
[How the drop-in finds its classes](#how-the-drop-in-finds-its-classes)).

### A plugin's `LIKE` search with escaped wildcards misbehaves

Known limitation — see
[Behavior notes and limitations](#behavior-notes-and-limitations).
Plain prefix/suffix searches (`LIKE 'foo%'`) are unaffected.

### A plugin reaches into `$wpdb->dbh`

There is no mysqli handle; `$wpdb->dbh` is `null` by design. Code doing
raw `mysqli_*()` calls against it is incompatible with this drop-in
(and with every non-mysqli wpdb replacement).

---

## How it works

ePHPm runs PHP inside the same OS process as its embedded SQLite
database via the embed SAPI. [ephpm#257](https://github.com/ephpm/ephpm/pull/257)
registered two host functions into PHP's global function table:

- `ephpm_db_query(string $sql, array $params = []): array` — rows as a
  list of associative arrays; int/float/null arrive as native PHP types.
- `ephpm_db_execute(string $sql, array $params = []): array` —
  `{affected_rows, last_insert_id}`.

Calling one is a direct C call into Rust, where a lazily-created
per-thread litewire `Session` — sharing the **same backend instance**
as ePHPm's MySQL wire frontend, including its query-stats wrapper —
translates the MySQL-dialect SQL and executes it against SQLite.
Errors surface as PHP exceptions carrying the mapped MySQL errno and a
PDO-style `SQLSTATE[...]` message.

This package wraps those two functions in a `wpdb` subclass
(`Ephpm\Db\WordPress\Db`) plus a thin `db.php` drop-in that instantiates
it as `$wpdb` when the functions exist. The rest of WordPress — the
options API, `WP_Query`, meta caches, the installer — keeps working
unchanged because it all routes through `wpdb`.

See [ephpm.dev](https://ephpm.dev) for the ePHPm architecture and
configuration reference.

---

## License

MIT — see [LICENSE](LICENSE).
