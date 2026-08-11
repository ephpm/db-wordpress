<?php

declare(strict_types=1);

namespace Ephpm\Db\WordPress;

/**
 * Test/development stand-in for the real SAPI bridge, backed by
 * `pdo_sqlite`. **Not for production** — inside ePHPm the real bridge
 * (litewire) does full MySQL-dialect translation; this class only
 * approximates the slice of it the test suite exercises:
 *
 * - MySQL string literals (single- or double-quoted, backslash escapes)
 *   are decoded and re-emitted as SQLite literals, mirroring what
 *   litewire's MySQL-dialect parser does. This is what makes
 *   `wpdb::_real_escape()` round-trips honest in tests.
 * - Backtick identifiers become double-quoted identifiers.
 * - `SET ...` statements are no-op OKs (as in litewire).
 * - Errors are mapped onto the bridge's error shape: `\Exception` with
 *   message `SQLSTATE[xxxxx]: <message>` and code = a MySQL errno
 *   (1064 syntax, 1062 duplicate, 1146 missing table, 1054 missing
 *   column, 1105 otherwise).
 *
 * It does NOT emulate SHOW/DESCRIBE/information_schema, MySQL
 * functions, or MySQL type/DDL translation. Keep test SQL inside the
 * dialect subset both engines share.
 */
final class PdoSqliteDbOps implements DbOpsInterface
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    public function query(string $sql, array $params = []): array
    {
        if ($this->isNoOp($sql)) {
            return [];
        }
        $stmt = $this->run($sql, $params);
        if ($stmt->columnCount() === 0) {
            return []; // No-rowset statement routed through query().
        }

        /** @var list<array<string, int|float|string|null>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function execute(string $sql, array $params = []): array
    {
        if ($this->isNoOp($sql)) {
            return ['affected_rows' => 0, 'last_insert_id' => 0];
        }
        $stmt = $this->run($sql, $params);

        return [
            'affected_rows' => $stmt->rowCount(),
            'last_insert_id' => (int) $this->pdo->lastInsertId(),
        ];
    }

    /** `SET NAMES` and friends are dialect no-ops in litewire. */
    private function isNoOp(string $sql): bool
    {
        return (bool) preg_match('/^\s*SET\s/i', $sql);
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare(self::mysqlToSqlite($sql));
            $position = 1;
            foreach ($params as $param) {
                if ($param === null) {
                    $stmt->bindValue($position, null, \PDO::PARAM_NULL);
                } elseif (\is_bool($param)) {
                    $stmt->bindValue($position, $param ? 1 : 0, \PDO::PARAM_INT);
                } elseif (\is_int($param)) {
                    $stmt->bindValue($position, $param, \PDO::PARAM_INT);
                } elseif (\is_float($param) || \is_string($param)) {
                    $stmt->bindValue($position, $param);
                } else {
                    throw new \Exception(
                        'ephpm_db: unsupported parameter type (only null, bool, '
                        . 'int, float, and string parameters bind)'
                    );
                }
                ++$position;
            }
            $stmt->execute();

            return $stmt;
        } catch (\PDOException $e) {
            throw self::mapError($e);
        }
    }

    /** Map a PDOException onto the bridge's MySQL-flavoured error shape. */
    private static function mapError(\PDOException $e): \Exception
    {
        $message = $e->errorInfo[2] ?? $e->getMessage();
        [$errno, $sqlstate] = match (true) {
            str_contains($message, 'syntax error') => [1064, '42000'],
            str_contains($message, 'UNIQUE constraint failed') => [1062, '23000'],
            str_contains($message, 'no such table') => [1146, '42S02'],
            str_contains($message, 'no such column') => [1054, '42S22'],
            default => [1105, 'HY000'],
        };

        return new \Exception("SQLSTATE[{$sqlstate}]: {$message}", $errno);
    }

    /**
     * Rewrite MySQL string literals and backtick identifiers into SQLite
     * form. Decodes MySQL backslash escapes (\n, \r, \0, \Z, \', \", \\)
     * the way a MySQL server (and litewire's MySQL-dialect parser) would,
     * then re-emits SQLite-quoted.
     */
    public static function mysqlToSqlite(string $sql): string
    {
        $out = '';
        $len = \strlen($sql);
        $i = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            if ($ch === '`') { // Backtick identifier -> "double quoted".
                $ident = '';
                ++$i;
                while ($i < $len) {
                    if ($sql[$i] === '`') {
                        if ($i + 1 < $len && $sql[$i + 1] === '`') {
                            $ident .= '`';
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        break;
                    }
                    $ident .= $sql[$i];
                    ++$i;
                }
                $out .= '"' . str_replace('"', '""', $ident) . '"';
                continue;
            }

            if ($ch === "'" || $ch === '"') { // MySQL string literal.
                $quote = $ch;
                $value = '';
                ++$i;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($c === '\\' && $i + 1 < $len) {
                        $next = $sql[$i + 1];
                        $value .= match ($next) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            '0' => "\0",
                            'b' => "\x08",
                            'Z' => "\x1a",
                            // MySQL keeps the backslash on \% and \_ in
                            // ordinary (non-LIKE) string context.
                            '%' => '\\%',
                            '_' => '\\_',
                            default => $next, // \' \" \\ and any other char.
                        };
                        $i += 2;
                        continue;
                    }
                    if ($c === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $value .= $quote; // Doubled quote.
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        break;
                    }
                    $value .= $c;
                    ++$i;
                }
                $out .= "'" . str_replace("'", "''", $value) . "'";
                continue;
            }

            $out .= $ch;
            ++$i;
        }

        return $out;
    }
}
