<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

/**
 * i-MSCP SGW_GraphQL plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use iMSCP\Database\DatabaseMySQL;
use PDO;
use RuntimeException;

/**
 * The panel's own PDO handle, the small query helpers the plugin needs, and
 * the panel's transaction counter.
 *
 * It is the panel's connection, not a second one: spec section 3.2 requires
 * the same connection and the same transaction, and a read on another
 * connection would not see a mutation's uncommitted rows.
 *
 * Transactions go through the panel's DatabaseMySQL counter when there is a
 * panel (decision D11). DatabaseMySQL opens a real transaction at depth 0 and
 * a savepoint below it; a core helper such as createDefaultMailAccounts()
 * calls DatabaseMySQL::beginTransaction() itself, so if this class opened its
 * transaction on the PDO directly the panel's counter would still read 0 and
 * the helper would ask PDO for a second transaction, which PDO refuses. With
 * no panel - the unit suite - this class keeps the same count itself, with
 * the same statements.
 *
 * Not final, so that a unit test can subclass it and fake rows().
 */
class Db
{
    /** @var PDO|null Null only for a detached handle; see detached(). */
    private $pdo;

    /**
     * @var object|null DatabaseMySQL, or anything with beginTransaction(),
     *                  commit() and rollBack(). Null to count on the PDO.
     */
    private $transactions;

    /** @var int Depth, when this class is counting for itself. */
    private $depth = 0;

    /**
     * @param PDO|null    $pdo          Required, not defaulted: a Db that
     *                                  silently has no connection because
     *                                  nobody passed one is a bug that only
     *                                  shows up as an empty result set.
     *                                  Detachment is asked for by name.
     * @param object|null $transactions The owner of the transaction count.
     *                                  Untyped because DatabaseMySQL does not
     *                                  exist outside the panel.
     */
    public function __construct(?PDO $pdo, $transactions = null)
    {
        $this->pdo = $pdo;
        $this->transactions = $transactions;
    }

    public static function fromPanel(): self
    {
        return new self(DatabaseMySQL::getPDO(), DatabaseMySQL::getInstance());
    }

    /**
     * A handle with no connection.
     *
     * Every method that would talk to the database throws instead. This exists
     * so the unit suite can build the whole resolver map - which is the test
     * that a schema field has lost its resolver - without a database, while
     * still failing loudly rather than returning nothing if a unit test ever
     * reaches a query.
     */
    public static function detached(): self
    {
        return new self(null);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException(
                'This Db is detached: it has no connection and cannot run a query.'
            );
        }

        return $this->pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sql, array $bind = array()): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(array_values($bind));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $bind = array()): ?array
    {
        $rows = $this->rows($sql, $bind);

        return $rows === array() ? null : $rows[0];
    }

    /**
     * @return mixed|null
     */
    public function value(string $sql, array $bind = array())
    {
        $row = $this->row($sql, $bind);

        if ($row === null) {
            return null;
        }

        $values = array_values($row);

        return $values === array() ? null : $values[0];
    }

    /**
     * A run of bound placeholders for an IN clause.
     */
    public function placeholders(int $n): string
    {
        return $n < 1 ? '' : rtrim(str_repeat('?,', $n), ',');
    }

    public function beginTransaction(): void
    {
        if ($this->transactions !== null) {
            $this->transactions->beginTransaction();

            return;
        }

        $pdo = $this->pdo();

        if ($this->depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT TRANSACTION' . $this->depth);
        }

        $this->depth++;
    }

    public function commit(): void
    {
        if ($this->transactions !== null) {
            $this->transactions->commit();

            return;
        }

        if ($this->depth === 0) {
            throw new RuntimeException('commit() was called with no transaction open.');
        }

        $this->depth--;

        if ($this->depth === 0) {
            $this->pdo()->commit();

            return;
        }

        $this->pdo()->exec('RELEASE SAVEPOINT TRANSACTION' . $this->depth);
    }

    public function rollBack(): void
    {
        if ($this->transactions !== null) {
            $this->transactions->rollBack();

            return;
        }

        if ($this->depth === 0) {
            return;
        }

        $this->depth--;

        if ($this->depth === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT TRANSACTION' . $this->depth);
    }

    /**
     * A statement with no result set.
     *
     * @return int The rows the server reports as affected. MySQL counts rows
     *             *changed*, not rows matched, so an UPDATE that writes the
     *             value already there reports 0: never use this to ask whether
     *             a row exists.
     */
    public function execute(string $sql, array $bind = array()): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(array_values($bind));

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int)$this->pdo()->lastInsertId();
    }

    /**
     * The number of statements this connection has sent to the server.
     *
     * MySQL counts them for us, which means the counter cannot drift out of
     * step with a library the way a hand-written wrapper would: Anorm's
     * queries, the plugin's own and exec_query()'s are all on this connection
     * and all counted.
     *
     * This call is itself one of the statements it might be used to count,
     * which is exactly why countQueries() measures its cost at run time
     * rather than assuming it - see there.
     */
    public function questions(): int
    {
        $row = $this->row("SHOW SESSION STATUS LIKE 'Questions'");

        return $row === null ? 0 : (int)$row['Value'];
    }

    /**
     * The number of statements $fn causes.
     *
     * questions() costs a statement itself, so its cost is measured here
     * rather than assumed - the exact overhead depends on the server version
     * and is not worth encoding as a magic number.
     *
     * This counts *server round trips*, not method calls, which is only
     * exact because the panel's connection sets PDO::ATTR_EMULATE_PREPARES
     * (DatabaseMySQL.php's constructor). With emulation on, PDO::prepare()
     * does no network I/O at all - it just builds the statement client-side
     * - so rows()'s prepare()+execute() costs exactly one COM_QUERY, and one
     * Db call is one counted statement. Every N+1 assertion from Task 12
     * onward is built on that arithmetic.
     *
     * If that attribute is ever turned off - the panel's own
     * DatabaseMySQL.php carries a "# FIXME should be FALSE" on it - prepare()
     * becomes a real round trip (COM_STMT_PREPARE) ahead of execute()'s
     * (COM_STMT_EXECUTE), so a Db call's true cost in statements would no
     * longer be exactly one, and every query-count assertion from Task 12
     * onward would be measuring something other than what it thinks it is.
     * Nothing here would throw - countQueries() would just start returning a
     * different, wrong number, quietly. DbTest::testThePanelUsesEmulatedPrepares()
     * is the tripwire for that: it reads the live attribute back off the PDO
     * handle and fails, by name, the day it changes.
     */
    public function countQueries(callable $fn): int
    {
        $probe = $this->questions();
        $overhead = $this->questions() - $probe;

        $before = $this->questions();
        $fn();

        return $this->questions() - $before - $overhead;
    }
}
