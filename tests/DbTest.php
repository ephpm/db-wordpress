<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress\Tests;

use Ephpm\Db\WordPress\Db;
use Ephpm\Db\WordPress\PdoSqliteDbOps;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real WordPress `wpdb` (core class-wpdb.php from the
 * Composer dev dependency) through the `Db` subclass, against the
 * pdo_sqlite-backed bridge fake.
 */
final class DbTest extends TestCase
{
    private function makeDb(): Db
    {
        $db = new Db('user', 'pass', 'wordpress', 'localhost', new PdoSqliteDbOps());
        $db->suppress_errors(); // Keep print_error() quiet; we assert on last_error.

        return $db;
    }

    private function makeDbWithTable(): Db
    {
        $db = $this->makeDb();
        $created = $db->query(
            'CREATE TABLE wp_items ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT, '
            . 'qty INTEGER, '
            . 'price REAL, '
            . 'note TEXT'
            . ')'
        );
        $this->assertTrue($created);
        $db->query('CREATE UNIQUE INDEX idx_items_name ON wp_items (name)');

        return $db;
    }

    // ── Connection lifecycle ────────────────────────────────────────────

    public function testConstructsReadyWithoutAnyConnection(): void
    {
        $db = $this->makeDb();

        $this->assertTrue($db->ready);
        $this->assertTrue($db->check_connection());
        $this->assertNull($db->dbh);
        $this->assertSame('utf8mb4', $db->charset);
    }

    public function testDbVersionIsNumericPrefixOfServerInfo(): void
    {
        $db = $this->makeDb();

        // The pdo_sqlite fake has no VERSION() function, so this is the
        // documented fallback string; under the real bridge it is the
        // value litewire returns for SELECT VERSION().
        $this->assertSame('8.0.0-litewire', $db->db_server_info());
        $this->assertSame('8.0.0', $db->db_version());
        $this->assertTrue(version_compare($db->db_version(), '5.5.5', '>='));
    }

    // ── Writes: insert_id / rows_affected ───────────────────────────────

    public function testInsertPopulatesInsertIdAndRowsAffected(): void
    {
        $db = $this->makeDbWithTable();

        $result = $db->insert(
            'wp_items',
            ['name' => 'widget', 'qty' => 3, 'price' => 1.5],
            ['%s', '%d', '%f']
        );

        $this->assertSame(1, $result);
        $this->assertSame(1, $db->rows_affected);
        $this->assertSame(1, $db->insert_id);

        $db->insert('wp_items', ['name' => 'gadget', 'qty' => 7]);
        $this->assertSame(2, $db->insert_id);
    }

    public function testUpdateAndDeleteReturnRowsAffected(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'a', 'qty' => 1]);
        $db->insert('wp_items', ['name' => 'b', 'qty' => 1]);

        $updated = $db->update('wp_items', ['qty' => 9], ['qty' => 1], ['%d'], ['%d']);
        $this->assertSame(2, $updated);
        $this->assertSame(2, $db->rows_affected);

        $deleted = $db->delete('wp_items', ['name' => 'a'], ['%s']);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $db->rows_affected);
    }

    public function testDdlReturnsTrue(): void
    {
        $db = $this->makeDb();

        $this->assertTrue($db->query('CREATE TABLE t (id INTEGER)'));
        $this->assertTrue($db->query('ALTER TABLE t ADD COLUMN x TEXT'));
        $this->assertTrue($db->query('DROP TABLE t'));
    }

    // ── Reads: result shapes and native types ───────────────────────────

    public function testGetResultsObjectArrayAAndArrayN(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget', 'qty' => 3, 'price' => 1.5]);

        $objects = $db->get_results('SELECT id, name, qty, price, note FROM wp_items');
        $this->assertCount(1, $objects);
        $this->assertInstanceOf(\stdClass::class, $objects[0]);
        $this->assertSame('widget', $objects[0]->name);
        $this->assertSame(1, $objects[0]->id);       // Native int, not "1".
        $this->assertSame(3, $objects[0]->qty);
        $this->assertSame(1.5, $objects[0]->price);  // Native float.
        $this->assertNull($objects[0]->note);        // SQL NULL -> null.
        $this->assertSame(1, $db->num_rows);

        $assoc = $db->get_results('SELECT id, name FROM wp_items', ARRAY_A);
        $this->assertSame([['id' => 1, 'name' => 'widget']], $assoc);

        $numeric = $db->get_results('SELECT id, name FROM wp_items', ARRAY_N);
        $this->assertSame([[1, 'widget']], $numeric);
    }

    public function testGetRowGetVarGetCol(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget', 'qty' => 3]);
        $db->insert('wp_items', ['name' => 'gadget', 'qty' => 7]);

        $row = $db->get_row('SELECT name, qty FROM wp_items ORDER BY id LIMIT 1');
        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame('widget', $row->name);

        $rowA = $db->get_row('SELECT name, qty FROM wp_items ORDER BY id LIMIT 1', ARRAY_A);
        $this->assertSame(['name' => 'widget', 'qty' => 3], $rowA);

        $this->assertSame(10, $db->get_var('SELECT SUM(qty) FROM wp_items'));
        $this->assertSame(['widget', 'gadget'], $db->get_col('SELECT name FROM wp_items ORDER BY id'));
        $this->assertNull($db->get_var('SELECT name FROM wp_items WHERE qty = 999'));
    }

    public function testGetColInfoReturnsColumnNames(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget', 'qty' => 3]);

        $db->get_results('SELECT id, name AS label FROM wp_items');
        $this->assertSame(['id', 'label'], $db->get_col_info('name'));

        // Documented bridge limitation: an empty rowset carries no column
        // metadata, so get_col_info() has nothing to report.
        $db->get_results('SELECT id FROM wp_items WHERE id = -1');
        $this->assertNull($db->get_col_info('name'));
    }

    // ── Errors are in-band (wpdb contract) ──────────────────────────────

    public function testBadSqlSetsLastErrorAndReturnsFalse(): void
    {
        $db = $this->makeDb();

        $result = $db->query('SELECT FROM WHERE');

        $this->assertFalse($result);
        $this->assertStringStartsWith('SQLSTATE[', $db->last_error);
        $this->assertSame(1064, $db->last_errno());

        global $EZSQL_ERROR;
        $this->assertNotEmpty($EZSQL_ERROR);
        $this->assertSame('SELECT FROM WHERE', $EZSQL_ERROR[array_key_last($EZSQL_ERROR)]['query']);
    }

    public function testDuplicateKeyIsError1062(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget']);

        $result = $db->query("INSERT INTO wp_items (name) VALUES ('widget')");

        $this->assertFalse($result);
        $this->assertSame(1062, $db->last_errno());
        $this->assertStringContainsString('SQLSTATE[23000]', $db->last_error);
        // A failed follow-up insert must not report the previous insert_id.
        $this->assertSame(0, $db->insert_id);
    }

    public function testMissingTableIsErrorAndNextQueryClearsIt(): void
    {
        $db = $this->makeDbWithTable();

        $this->assertFalse($db->query('SELECT * FROM wp_missing'));
        $this->assertSame(1146, $db->last_errno());
        $this->assertNotSame('', $db->last_error);

        $this->assertNotFalse($db->query('SELECT 1'));
        $this->assertSame('', $db->last_error);
    }

    // ── Escaping and prepare() ──────────────────────────────────────────

    public function testEscapingRoundTripsHostileStrings(): void
    {
        $db = $this->makeDbWithTable();

        $hostile = "O'Reilly said \"hi\"\r\nback\\slash\ttab and \x1a sub";
        $inserted = $db->query(
            $db->prepare('INSERT INTO wp_items (name) VALUES (%s)', $hostile)
        );
        $this->assertSame(1, $inserted);

        $this->assertSame(
            $hostile,
            $db->get_var($db->prepare('SELECT name FROM wp_items WHERE id = %d', $db->insert_id))
        );
    }

    public function testPreparePlaceholders(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => "it's", 'qty' => 5, 'price' => 2.25]);

        // %s is quoted and escaped, %d is an int, %f a float, %i an identifier.
        $sql = $db->prepare(
            'SELECT qty FROM %i WHERE name = %s AND qty = %d AND price = %f',
            'wp_items',
            "it's",
            5,
            2.25
        );
        $this->assertStringContainsString('`wp_items`', $sql);
        $this->assertStringContainsString("'it\\'s'", $sql);

        $this->assertSame(5, $db->get_var($sql));
    }

    public function testPreparedLikeWithEscapedPercentLiteral(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget-blue']);
        $db->insert('wp_items', ['name' => 'gadget-red']);

        $sql = $db->prepare(
            'SELECT name FROM wp_items WHERE name LIKE %s ORDER BY id',
            $db->esc_like('widget-') . '%'
        );
        $this->assertSame(['widget-blue'], $db->get_col($sql));
    }

    // ── Multi-byte and invalid-byte handling ────────────────────────────

    public function testNonAsciiUtf8RoundTrips(): void
    {
        $db = $this->makeDbWithTable();

        $value = 'héllo wörld — 日本語 🚀';
        $db->query($db->prepare('INSERT INTO wp_items (name) VALUES (%s)', $value));

        $this->assertSame(
            $value,
            $db->get_var($db->prepare('SELECT name FROM wp_items WHERE id = %d', $db->insert_id))
        );
    }

    public function testInvalidUtf8IsRejectedInBand(): void
    {
        $db = $this->makeDbWithTable();

        $result = $db->query("INSERT INTO wp_items (name) VALUES ('bad \xF5 byte')");

        $this->assertFalse($result);
        $this->assertStringContainsString('invalid data', $db->last_error);
    }

    // ── Statement routing ───────────────────────────────────────────────

    public function testSetNamesIsANoOpOk(): void
    {
        $db = $this->makeDb();

        // Routed through execute(); litewire answers a plain OK. Core wpdb
        // would return 0 for a no-rowset non-write statement, so do we.
        $this->assertSame(0, $db->query("SET NAMES 'utf8mb4'"));
        $this->assertSame('', $db->last_error);
    }

    public function testTransactionsFlowThroughAsSql(): void
    {
        $db = $this->makeDbWithTable();

        $db->query('BEGIN');
        $db->insert('wp_items', ['name' => 'temp']);
        $db->query('ROLLBACK');
        $this->assertSame(0, $db->get_var('SELECT COUNT(*) FROM wp_items'));

        $db->query('BEGIN');
        $db->insert('wp_items', ['name' => 'kept']);
        $db->query('COMMIT');
        $this->assertSame(1, $db->get_var('SELECT COUNT(*) FROM wp_items'));
    }

    public function testLeadingCommentRouting(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget']);

        $this->assertSame(1, $db->query('/* hint */ SELECT * FROM wp_items'));
    }

    // ── Debug fields: SAVEQUERIES / num_queries ─────────────────────────

    public function testSaveQueriesPopulatesQueryLog(): void
    {
        $db = $this->makeDbWithTable();
        $before = count($db->queries);
        $numBefore = $db->num_queries;

        $db->query('SELECT 1');

        $this->assertSame($numBefore + 1, $db->num_queries);
        $this->assertCount($before + 1, $db->queries);

        [$sql, $time, $callstack, $start, $data] = $db->queries[array_key_last($db->queries)];
        $this->assertSame('SELECT 1', $sql);
        $this->assertIsFloat($time);
        $this->assertSame('phpunit', $callstack);
        $this->assertIsFloat($start);
        $this->assertSame([], $data);
    }

    public function testFlushClearsResultState(): void
    {
        $db = $this->makeDbWithTable();
        $db->insert('wp_items', ['name' => 'widget']);
        $db->get_results('SELECT * FROM wp_items');

        $db->flush();

        $this->assertSame([], $db->last_result);
        $this->assertSame(0, $db->num_rows);
        $this->assertNull($db->last_query);
        $this->assertSame('', $db->last_error);
    }
}
