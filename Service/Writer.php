<?php
namespace iMSCP\Plugin\SGW_GraphQL\Service;

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

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use PDOException;
use Throwable;

/**
 * Spec section 8.1 step 8: one transaction per mutation.
 *
 * The caller does steps 9 and 10 - send_request() and write_log() - after
 * run() returns, which is after the commit. That is deliberately not folded
 * in here: a daemon that read the rows mid-transaction would see nothing, and
 * keeping the two calls visible in each service keeps that ordering
 * reviewable where the rows are written.
 */
final class Writer
{
    /** MariaDB's ER_DUP_ENTRY. */
    const DUPLICATE_KEY = 1062;

    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @param callable $work fn(): mixed
     * @return mixed What $work returned
     * @throws ApiException CONFLICT on a duplicate key
     */
    public function run(callable $work)
    {
        $this->db->beginTransaction();

        try {
            $result = $work();
        } catch (Throwable $e) {
            try {
                $this->db->rollBack();
            } catch (Throwable $ignored) {
                // A rollback failure must never replace the exception that
                // caused it; there is nothing useful to do with it here.
            }

            throw self::asConflict($e);
        }

        // Outside the try: Db::commit() decrements its own-counter depth
        // before asking PDO to commit, so a failed commit has already left
        // the count at what rollBack() would expect after a *successful*
        // rollback. Rolling back here as well would decrement it a second
        // time and undo the enclosing transaction level.
        try {
            $this->db->commit();
        } catch (Throwable $e) {
            throw self::asConflict($e);
        }

        return $result;
    }

    private static function asConflict(Throwable $e): Throwable
    {
        if (self::isDuplicate($e)) {
            return new ApiException(
                ErrorCode::CONFLICT, 'An object with that name already exists.', array(), $e
            );
        }

        return $e;
    }

    /**
     * Whether a uniqueness clash is anywhere in the chain. Only 1062: a 1048
     * shares SQLSTATE 23000 and is a bug (measurement M8).
     */
    public static function isDuplicate(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && isset($current->errorInfo[1])
                && (int)$current->errorInfo[1] === self::DUPLICATE_KEY
            ) {
                return true;
            }
        }

        return false;
    }
}
