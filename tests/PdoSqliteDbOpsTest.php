<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress\Tests;

use Ephpm\Db\WordPress\PdoSqliteDbOps;
use PHPUnit\Framework\TestCase;

/**
 * The test fake itself must honour the bridge contract, or the DbTest
 * assertions prove nothing.
 */
final class PdoSqliteDbOpsTest extends TestCase
{
    private function ops(): PdoSqliteDbOps
    {
        $ops = new PdoSqliteDbOps();
        $ops->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE, n INTEGER, f REAL)');

        return $ops;
    }

    public function testQueryReturnsNativeTypes(): void
    {
        $ops = $this->ops();
        $ops->execute("INSERT INTO t (name, n, f) VALUES ('x', 42, 1.5)");

        $rows = $ops->query('SELECT id, name, n, f, NULL AS nil FROM t');

        $this->assertSame(
            [['id' => 1, 'name' => 'x', 'n' => 42, 'f' => 1.5, 'nil' => null]],
            $rows
        );
    }

    public function testExecuteReturnsOkMetadata(): void
    {
        $ops = $this->ops();

        $ok = $ops->execute("INSERT INTO t (name) VALUES ('a')");
        $this->assertSame(['affected_rows' => 1, 'last_insert_id' => 1], $ok);

        $ops->execute("INSERT INTO t (name) VALUES ('b')");
        $ok = $ops->execute("UPDATE t SET n = 1");
        $this->assertSame(2, $ok['affected_rows']);
    }

    public function testNoRowsetStatementThroughQueryReturnsEmptyArray(): void
    {
        $ops = $this->ops();

        $this->assertSame([], $ops->query("INSERT INTO t (name) VALUES ('a')"));
        $this->assertSame([], $ops->query("SET NAMES 'utf8'"));
    }

    public function testParamsBind(): void
    {
        $ops = $this->ops();
        $ops->execute('INSERT INTO t (name, n) VALUES (?, ?)', ['bound', 7]);

        $rows = $ops->query('SELECT name, n FROM t WHERE n = ?', [7]);
        $this->assertSame([['name' => 'bound', 'n' => 7]], $rows);
    }

    public function testErrorShapeMatchesBridge(): void
    {
        $ops = $this->ops();

        try {
            $ops->query('SELECT FROM WHERE');
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertSame(1064, $e->getCode());
            $this->assertStringStartsWith('SQLSTATE[42000]:', $e->getMessage());
        }

        $ops->execute("INSERT INTO t (name) VALUES ('dup')");
        try {
            $ops->execute("INSERT INTO t (name) VALUES ('dup')");
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertSame(1062, $e->getCode());
            $this->assertStringStartsWith('SQLSTATE[23000]:', $e->getMessage());
        }

        try {
            $ops->query('SELECT * FROM missing');
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertSame(1146, $e->getCode());
        }

        try {
            $ops->query('SELECT missing_col FROM t');
            $this->fail('expected exception');
        } catch (\Exception $e) {
            $this->assertSame(1054, $e->getCode());
        }
    }

    // ── The MySQL -> SQLite literal rewriter ────────────────────────────

    public function testBacktickIdentifiersBecomeDoubleQuoted(): void
    {
        $this->assertSame(
            'SELECT "name" FROM "t"',
            PdoSqliteDbOps::mysqlToSqlite('SELECT `name` FROM `t`')
        );
    }

    public function testBackslashEscapesAreDecoded(): void
    {
        // \' -> ' (re-emitted doubled), \n -> newline, \\ -> backslash.
        $this->assertSame(
            "SELECT 'O''Reilly'",
            PdoSqliteDbOps::mysqlToSqlite("SELECT 'O\\'Reilly'")
        );
        $this->assertSame(
            "SELECT 'a\nb'",
            PdoSqliteDbOps::mysqlToSqlite("SELECT 'a\\nb'")
        );
        $this->assertSame(
            "SELECT 'a\\b'",
            PdoSqliteDbOps::mysqlToSqlite("SELECT 'a\\\\b'")
        );
    }

    public function testDoubleQuotedMysqlStringsBecomeSingleQuoted(): void
    {
        $this->assertSame(
            "SELECT 'hi there'",
            PdoSqliteDbOps::mysqlToSqlite('SELECT "hi there"')
        );
    }

    public function testDoubledQuotesSurvive(): void
    {
        $this->assertSame(
            "SELECT 'it''s'",
            PdoSqliteDbOps::mysqlToSqlite("SELECT 'it''s'")
        );
    }

    public function testEscapedLikeWildcardsKeepTheirBackslash(): void
    {
        // MySQL keeps the backslash on \% and \_ in ordinary string
        // context (it only strips it during LIKE evaluation).
        $this->assertSame(
            "SELECT '50\\%'",
            PdoSqliteDbOps::mysqlToSqlite("SELECT '50\\%'")
        );
    }
}
