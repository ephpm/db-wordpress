<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The drop-in's graceful-fallback contract: without the ephpm SAPI
 * functions it must NOT set `$wpdb`, so `require_wp_db()` constructs
 * the stock mysqli-backed wpdb as if no drop-in were installed.
 */
final class DropinFallbackTest extends TestCase
{
    public function testWithoutNativesTheDropinLeavesWpdbUnset(): void
    {
        // This test process has no ephpm_db_query() (we're on plain
        // php-cli), which is exactly the fallback scenario.
        $this->assertFalse(\function_exists('ephpm_db_query'));

        unset($GLOBALS['wpdb']);
        require \dirname(__DIR__) . '/dropin/db.php';

        $this->assertArrayNotHasKey('wpdb', $GLOBALS);
    }

    public function testDropinIsInertOutsideWordPress(): void
    {
        // Simulate a direct hit on the file with no ABSPATH: it must
        // return before doing anything. We can't undefine ABSPATH in
        // this process (the bootstrap defines it), so instead assert the
        // guard exists at the top of the file.
        $source = file_get_contents(\dirname(__DIR__) . '/dropin/db.php');
        $this->assertIsString($source);
        $guardPos = strpos($source, "if (!defined('ABSPATH'))");
        $this->assertNotFalse($guardPos, 'ABSPATH guard missing');
        $firstStatement = strpos($source, 'if (');
        $this->assertSame($guardPos, $firstStatement, 'ABSPATH guard must be the first statement');
    }
}
