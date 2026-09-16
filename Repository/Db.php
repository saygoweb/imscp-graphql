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

/**
 * The panel's own PDO handle, and the small query helpers the read side needs
 * for the things that are not model reads.
 *
 * It is the panel's connection, not a second one: spec section 3.2 requires
 * the same connection and the same transaction, and a read on another
 * connection would not see a mutation's uncommitted rows.
 *
 * Not final, so that a unit test can subclass it and fake rows().
 */
class Db
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function fromPanel(): self
    {
        return new self(DatabaseMySQL::getPDO());
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $sql, array $bind = array()): array
    {
        $statement = $this->pdo->prepare($sql);
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

    /**
     * The number of statements this connection has sent to the server.
     *
     * MySQL counts them for us, which means the counter cannot drift out of
     * step with a library the way a hand-written wrapper would: Anorm's
     * queries, the plugin's own and exec_query()'s are all on this connection
     * and all counted.
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
