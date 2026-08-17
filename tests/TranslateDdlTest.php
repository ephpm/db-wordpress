<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress\Tests;

use Ephpm\Db\WordPress\Db;
use Ephpm\Db\WordPress\PdoSqliteDbOps;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the DDL / dummy-table rewrites added to
 * Db::translateMysqlToBridge() for plugin activation (Yoast SEO,
 * ActionScheduler, Contextual Related Posts).
 */
final class TranslateDdlTest extends TestCase
{
    private function db(): Db
    {
        return new Db('u', 'p', 'wordpress', 'localhost', new PdoSqliteDbOps());
    }

    // ── UPDATE/DELETE ORDER BY LIMIT ────────────────────────────────────

    public function testUpdateOrderByLimitRewrite(): void
    {
        $this->assertSame(
            "UPDATE wp_posts SET post_password = 'x' WHERE rowid IN (SELECT rowid FROM wp_posts "
            . "WHERE post_type = 'scheduled-action' ORDER BY menu_order ASC, ID ASC LIMIT 25)",
            Db::translateMysqlToBridge(
                "UPDATE wp_posts SET post_password = 'x' WHERE post_type = 'scheduled-action' "
                . "ORDER BY menu_order ASC, ID ASC LIMIT 25"
            )
        );
    }

    public function testUpdateLimitWithoutOrderRewrite(): void
    {
        $this->assertSame(
            'UPDATE wp_t SET a = 1 WHERE rowid IN (SELECT rowid FROM wp_t WHERE b = 2 LIMIT 5)',
            Db::translateMysqlToBridge('UPDATE wp_t SET a = 1 WHERE b = 2 LIMIT 5')
        );
    }

    public function testDeleteOrderByLimitRewrite(): void
    {
        $this->assertSame(
            'DELETE FROM wp_t WHERE rowid IN (SELECT rowid FROM wp_t WHERE b = 2 ORDER BY id ASC LIMIT 10)',
            Db::translateMysqlToBridge('DELETE FROM wp_t WHERE b = 2 ORDER BY id ASC LIMIT 10')
        );
    }

    public function testUpdateWithoutLimitUntouched(): void
    {
        $sql = "UPDATE wp_options SET option_value = 'x' WHERE option_name = 'y'";
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    public function testSelectWithLimitUntouched(): void
    {
        $sql = 'SELECT * FROM wp_posts WHERE post_status = ? ORDER BY post_date DESC LIMIT 10';
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    // ── ON UPDATE CURRENT_TIMESTAMP ─────────────────────────────────────

    public function testStripsOnUpdateCurrentTimestamp(): void
    {
        $this->assertSame(
            'ALTER TABLE `wp_yoast_indexable` ADD `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL',
            Db::translateMysqlToBridge(
                'ALTER TABLE `wp_yoast_indexable` ADD `updated_at` timestamp '
                . 'DEFAULT CURRENT_TIMESTAMP NOT NULL ON UPDATE CURRENT_TIMESTAMP'
            )
        );
    }

    public function testStripsOnUpdateCurrentTimestampWithPrecision(): void
    {
        $this->assertSame(
            'ALTER TABLE t ADD c timestamp DEFAULT CURRENT_TIMESTAMP(6) NOT NULL',
            Db::translateMysqlToBridge(
                'ALTER TABLE t ADD c timestamp DEFAULT CURRENT_TIMESTAMP(6) NOT NULL ON UPDATE CURRENT_TIMESTAMP(6)'
            )
        );
    }

    // ── INSERT IGNORE ───────────────────────────────────────────────────

    public function testInsertIgnoreBecomesInsertOrIgnore(): void
    {
        $this->assertSame(
            'INSERT OR IGNORE INTO wp_wc_category_lookup (category_id, category_tree_id) VALUES (5, 5)',
            Db::translateMysqlToBridge('INSERT IGNORE INTO wp_wc_category_lookup (category_id, category_tree_id) VALUES (5, 5)')
        );
    }

    public function testOrdinaryInsertUntouched(): void
    {
        $sql = 'INSERT INTO wp_options (option_name, option_value) VALUES (?, ?)';
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    // ── DROP PRIMARY KEY no-op ──────────────────────────────────────────

    public function testDropPrimaryKeyIsNoop(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge('ALTER TABLE wp_wc_order_product_lookup DROP PRIMARY KEY, ADD PRIMARY KEY (order_item_id, order_id)')
        );
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge(
                'ALTER TABLE `wp_woocommerce_sessions` DROP PRIMARY KEY, DROP KEY `session_id`, '
                . 'ADD PRIMARY KEY(`session_id`), ADD UNIQUE KEY(`session_key`)'
            )
        );
    }

    public function testAlterAddColumnStillWorksWithoutDropPk(): void
    {
        $sql = 'ALTER TABLE wp_t ADD COLUMN extra text';
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    public function testStandaloneAddPrimaryKeyIsNoop(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge('ALTER TABLE wp_woocommerce_sessions ADD PRIMARY KEY (`session_id`)')
        );
    }

    public function testAddIndexIsNoop(): void
    {
        $this->assertSame('SELECT 1', Db::translateMysqlToBridge('ALTER TABLE wp_x ADD KEY idx_name (name)'));
        $this->assertSame('SELECT 1', Db::translateMysqlToBridge('ALTER TABLE wp_x ADD UNIQUE KEY uq (sku)'));
    }

    public function testAlterColumnSetDefaultIsNoop(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge("ALTER TABLE wp_wc_product_meta_lookup ALTER COLUMN `stock_status` SET DEFAULT 'instock'")
        );
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge('ALTER TABLE wp_x ALTER COLUMN qty DROP DEFAULT')
        );
    }

    public function testAddColumnNamedAfterKeywordStillPasses(): void
    {
        // A plain column add must never be mistaken for an index/key op.
        $sql = 'ALTER TABLE wp_x ADD COLUMN indexed_at datetime';
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
        $sql2 = 'ALTER TABLE wp_x ADD `primary_email` varchar(100)';
        $this->assertSame($sql2, Db::translateMysqlToBridge($sql2));
    }

    // ── INFORMATION_SCHEMA index probe ──────────────────────────────────

    public function testInformationSchemaStatisticsProbeReturnsOne(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() "
                . "AND TABLE_NAME = 'wp_wc_order_product_lookup' AND INDEX_NAME = 'PRIMARY'"
            )
        );
    }

    // ── ALTER no-ops ────────────────────────────────────────────────────

    public function testConvertToCharacterSetIsNoop(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge('ALTER TABLE wp_yoast_indexable CONVERT TO CHARACTER SET utf8mb4')
        );
    }

    public function testChangeColumnIsNoop(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge('ALTER TABLE `wp_yoast_indexable` CHANGE `title` `title` text')
        );
    }

    public function testModifyColumnIsNoop(): void
    {
        $this->assertSame(
            'SELECT 1',
            Db::translateMysqlToBridge(
                'ALTER TABLE wp_actionscheduler_actions MODIFY COLUMN scheduled_date_gmt '
                . "datetime NULL default '0000-00-00 00:00:00', MODIFY COLUMN scheduled_date_local datetime NULL"
            )
        );
    }

    public function testAlterAddWithChangeWordInNameIsUntouched(): void
    {
        $sql = 'ALTER TABLE t ADD COLUMN change_log text';
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    // ── TRUNCATE ────────────────────────────────────────────────────────

    public function testTruncateBecomesDelete(): void
    {
        $this->assertSame('DELETE FROM wp_yoast_indexable', Db::translateMysqlToBridge('TRUNCATE TABLE wp_yoast_indexable'));
        $this->assertSame('DELETE FROM `wp_x`', Db::translateMysqlToBridge('TRUNCATE `wp_x`'));
    }

    // ── Single-target join DELETE ───────────────────────────────────────

    public function testAliasJoinDeleteRewrite(): void
    {
        $in = 'DELETE wyi FROM wp_yoast_indexable wyi INNER JOIN wp_yoast_indexable wyi2 '
            . 'WHERE wyi2.object_id = wyi.object_id AND wyi2.id < wyi.id';
        $this->assertSame(
            'DELETE FROM wp_yoast_indexable WHERE rowid IN (SELECT wyi.rowid FROM wp_yoast_indexable wyi '
            . 'INNER JOIN wp_yoast_indexable wyi2 WHERE wyi2.object_id = wyi.object_id AND wyi2.id < wyi.id)',
            Db::translateMysqlToBridge($in)
        );
    }

    public function testOrdinaryDeleteUntouched(): void
    {
        $sql = 'DELETE FROM wp_options WHERE option_name = ?';
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    // ── FROM dual ───────────────────────────────────────────────────────

    public function testFromDualStripped(): void
    {
        $this->assertSame(
            'INSERT INTO t (a) SELECT 1  WHERE NOT EXISTS (SELECT 1 FROM t WHERE a = 1)',
            Db::translateMysqlToBridge('INSERT INTO t (a) SELECT 1 FROM dual WHERE NOT EXISTS (SELECT 1 FROM t WHERE a = 1)')
        );
    }

    public function testOrdinarySelectUntouched(): void
    {
        $sql = "SELECT * FROM wp_posts WHERE post_status = 'publish' ORDER BY post_date DESC";
        $this->assertSame($sql, Db::translateMysqlToBridge($sql));
    }

    // ── Integration: rewritten SQL actually executes on pdo_sqlite ──────

    public function testTruncateExecutesAsDelete(): void
    {
        $db = $this->db();
        $db->query('CREATE TABLE wp_t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT)');
        $db->query("INSERT INTO wp_t (v) VALUES ('a'), ('b'), ('c')");
        $this->assertSame(3, (int) $db->get_var('SELECT COUNT(*) FROM wp_t'));

        $this->assertTrue($db->query('TRUNCATE TABLE wp_t'));
        $this->assertSame('', $db->last_error);
        $this->assertSame(0, (int) $db->get_var('SELECT COUNT(*) FROM wp_t'));
    }

    public function testAliasJoinDeleteExecutes(): void
    {
        $db = $this->db();
        $db->query('CREATE TABLE wp_ind (id INTEGER PRIMARY KEY AUTOINCREMENT, object_id INTEGER)');
        $db->query('INSERT INTO wp_ind (object_id) VALUES (5), (5), (6)');

        $db->query('DELETE a FROM wp_ind a INNER JOIN wp_ind b WHERE b.object_id = a.object_id AND b.id < a.id');
        $this->assertSame('', $db->last_error);
        $this->assertSame(2, (int) $db->get_var('SELECT COUNT(*) FROM wp_ind'));
        $this->assertSame('1', $db->get_var('SELECT id FROM wp_ind WHERE object_id = 5'));
    }

    public function testInsertSelectFromDualExecutes(): void
    {
        $db = $this->db();
        $db->query('CREATE TABLE wp_u (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE)');
        $sql = "INSERT INTO wp_u (name) SELECT 'x' FROM dual WHERE NOT EXISTS (SELECT 1 FROM wp_u WHERE name = 'x')";
        $db->query($sql);
        $db->query($sql);
        $this->assertSame('', $db->last_error);
        $this->assertSame(1, (int) $db->get_var('SELECT COUNT(*) FROM wp_u'));
    }
}
