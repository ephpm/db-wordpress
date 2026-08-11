<?php

declare(strict_types=1);

/*
 * Test bootstrap: load WordPress core's real class-wpdb.php (installed
 * as a Composer dev dependency, roots/wordpress-no-content) standalone,
 * with just enough WordPress function stubs for the code paths wpdb
 * touches outside a full WordPress boot. This is the same trick HyperDB
 * and the SQLite drop-in ecosystem rely on — class-wpdb.php is designed
 * to be includable outside core (see the str_contains() notes in its
 * source).
 */

require __DIR__ . '/../vendor/autoload.php';

// Keep print_error()'s error_log() chatter out of the phpunit output.
ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ephpm-db-wordpress-tests.log');

define('ABSPATH', __DIR__ . '/../wordpress/');
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);

// Exercise the SAVEQUERIES logging path for the whole suite.
define('SAVEQUERIES', true);

// ── Minimal WordPress function stubs ────────────────────────────────────
// Only what class-wpdb.php's non-mysqli code paths call at runtime.

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function wp_load_translations_early(): void
{
}

function _doing_it_wrong(string $function_name, string $message, string $version): void
{
}

/*
 * A real (if tiny) filter registry: wpdb's placeholder-escape mechanism
 * depends on add_filter('query', [$wpdb, 'remove_placeholder_escape'])
 * actually running when query() applies the 'query' filter. Inert stubs
 * here would leave the placeholder token in every prepared query that
 * contains a literal '%'.
 */
$GLOBALS['__test_filters'] = [];

/** @param mixed $value */
function apply_filters(string $hook_name, $value, ...$args)
{
    foreach ($GLOBALS['__test_filters'][$hook_name] ?? [] as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['__test_filters'][$hook_name][] = $callback;

    return true;
}

/**
 * @param callable|false $callback
 * @return bool|int Priority (10) if registered, false otherwise.
 */
function has_filter(string $hook_name, $callback = false)
{
    $registered = $GLOBALS['__test_filters'][$hook_name] ?? [];
    if (false === $callback) {
        return [] !== $registered ? 10 : false;
    }

    return \in_array($callback, $registered, true) ? 10 : false;
}

function is_multisite(): bool
{
    return false;
}

function wp_debug_backtrace_summary($ignore_class = null, int $skip_frames = 0, bool $pretty = true): string
{
    return 'phpunit';
}

/** @param mixed $thing */
function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

function mbstring_binary_safe_encoding(bool $reset = false): void
{
}

function reset_mbstring_encoding(): void
{
}

/** @param mixed $maybeint */
function absint($maybeint): int
{
    return abs((int) $maybeint);
}

function did_action(string $hook_name): int
{
    return 0;
}

class WP_Error
{
    /** @param mixed $data */
    public function __construct(
        public string|int $code = '',
        public string $message = '',
        public $data = ''
    ) {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

// ── WordPress core's wpdb ───────────────────────────────────────────────

require ABSPATH . WPINC . '/class-wpdb.php';
