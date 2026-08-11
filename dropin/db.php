<?php
/**
 * Plugin Name: ePHPm Database
 * Description: WordPress database drop-in backed by ePHPm's in-process DB bridge (ephpm_db_query/ephpm_db_execute SAPI functions). wpdb without mysqli — no socket, SQLite underneath.
 * Version: 0.1.0
 * Author: ePHPm
 * License: MIT
 *
 * INSTALL
 * -------
 * Copy (or symlink) this file to `wp-content/db.php`. WordPress loads it
 * from `require_wp_db()` — before plugins, and (usually) before any
 * Composer autoloader has been required — with core's `wpdb` class
 * already loaded. This file therefore loads the package's classes
 * itself, then replaces the global `$wpdb`.
 *
 * GRACEFUL FALLBACK
 * -----------------
 * If the `ephpm_db_query()` SAPI function does not exist (plain PHP
 * hosting, or ePHPm without `[db.sqlite]`), this file logs a notice and
 * returns WITHOUT setting `$wpdb`. WordPress then constructs the stock
 * mysqli-backed `wpdb` exactly as if no drop-in were installed.
 *
 * EXPECTED LAYOUT
 * ---------------
 * The package is installed via Composer (`composer require ephpm/db-wordpress`).
 * To locate its classes this drop-in tries, in order:
 *
 *   1. A `EPHPM_DB_AUTOLOAD` constant pointing at a `vendor/autoload.php`
 *      (define it in `wp-config.php` if your layout is non-standard).
 *   2. `WP_CONTENT_DIR/vendor/autoload.php`
 *   3. `ABSPATH/vendor/autoload.php`
 *   4. `<this dir>/vendor/autoload.php` and a couple of relative climbs,
 *      covering the case where the drop-in lives inside the installed
 *      package directory.
 *   5. As a last resort, it `require_once`s the package's `src/*.php`
 *      files directly, resolved relative to this drop-in's location.
 *
 * If none of those resolve the `Ephpm\Db\WordPress\Db` class the
 * drop-in does nothing (WordPress falls back to the stock mysqli wpdb)
 * and logs a notice, rather than fataling the site.
 *
 * @package Ephpm\Db\WordPress
 */

if (!defined('ABSPATH')) {
    // Not loaded by WordPress; bail rather than expose anything standalone.
    return;
}

if (!function_exists('ephpm_db_query')) {
    /*
     * Not running inside ePHPm (or [db.sqlite] isn't active). Leave $wpdb
     * unset: require_wp_db() will construct the stock mysqli-backed wpdb,
     * exactly as if this drop-in weren't installed.
     */
    if (function_exists('error_log')) {
        error_log(
            'ephpm db.php: ephpm_db_query() is not available '
            . '(not running inside ePHPm, or [db.sqlite] is not active); '
            . 'falling back to the stock mysqli wpdb.'
        );
    }
    return;
}

if (!class_exists('Ephpm\\Db\\WordPress\\Db')) {
    (static function (): void {
        $candidates = [];

        if (defined('EPHPM_DB_AUTOLOAD')) {
            $candidates[] = (string) EPHPM_DB_AUTOLOAD;
        }
        if (defined('WP_CONTENT_DIR')) {
            $candidates[] = rtrim((string) WP_CONTENT_DIR, '/\\') . '/vendor/autoload.php';
        }
        $candidates[] = rtrim((string) ABSPATH, '/\\') . '/vendor/autoload.php';
        $candidates[] = __DIR__ . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__) . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__, 2) . '/vendor/autoload.php';

        foreach ($candidates as $autoload) {
            if ($autoload !== '' && is_file($autoload)) {
                require_once $autoload;
                if (class_exists('Ephpm\\Db\\WordPress\\Db')) {
                    return;
                }
            }
        }

        // Last resort: require the package source files directly. This
        // drop-in ships inside the package at `dropin/db.php`, so the
        // sources are one directory up under `src/`. We also probe a
        // vendor install path for safety.
        $srcDirs = [
            dirname(__DIR__) . '/src',
            dirname(__DIR__, 1) . '/vendor/ephpm/db-wordpress/src',
        ];
        foreach ($srcDirs as $src) {
            $iface = $src . '/DbOpsInterface.php';
            if (is_file($iface)) {
                require_once $iface;
                require_once $src . '/SapiDbOps.php';
                require_once $src . '/PdoSqliteDbOps.php';
                require_once $src . '/Db.php';
                if (class_exists('Ephpm\\Db\\WordPress\\Db')) {
                    return;
                }
            }
        }
    })();
}

if (!class_exists('Ephpm\\Db\\WordPress\\Db')) {
    // Couldn't find the package. Leave WordPress to the stock mysqli
    // wpdb instead of fataling; surface a hint in the error log.
    if (function_exists('error_log')) {
        error_log(
            'ephpm db.php: could not locate Ephpm\\Db\\WordPress\\Db. '
            . 'Install ephpm/db-wordpress via Composer or define '
            . 'EPHPM_DB_AUTOLOAD in wp-config.php. '
            . 'Falling back to the stock mysqli wpdb.'
        );
    }
    return;
}

/*
 * Instantiate with the same constants require_wp_db() would use. The
 * bridge ignores all four (there are no credentials and only one
 * database), but keeping them makes $wpdb->dbname etc. read as expected.
 */
$GLOBALS['wpdb'] = new \Ephpm\Db\WordPress\Db(
    defined('DB_USER') ? DB_USER : '',
    defined('DB_PASSWORD') ? DB_PASSWORD : '',
    defined('DB_NAME') ? DB_NAME : '',
    defined('DB_HOST') ? DB_HOST : ''
);
