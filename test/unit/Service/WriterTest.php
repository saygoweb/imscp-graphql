<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

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
use iMSCP\Plugin\SGW_GraphQL\Service\Writer;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WriterTest extends TestCase
{
    private function pdoException(int $driverCode): PDOException
    {
        $e = new PDOException('SQLSTATE[23000]: Integrity constraint violation');
        $e->errorInfo = array('23000', $driverCode, 'detail');

        return $e;
    }

    public function testTheWorkIsCommittedAndItsResultReturned(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::once())->method('commit');
        $pdo->expects(self::never())->method('rollBack');

        self::assertSame(42, (new Writer(new Db($pdo)))->run(static function () {
            return 42;
        }));
    }

    public function testAFailureRollsBackAndIsRethrown(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::never())->method('commit');
        $pdo->expects(self::once())->method('rollBack');

        $this->expectException(RuntimeException::class);

        (new Writer(new Db($pdo)))->run(static function () {
            throw new RuntimeException('boom');
        });
    }

    public function testADuplicateKeyIsAConflict(): void
    {
        // Spec section 8.4: a retried create fails with CONFLICT.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $duplicate = $this->pdoException(1062);

        try {
            (new Writer(new Db($pdo)))->run(static function () use ($duplicate) {
                throw $duplicate;
            });
            self::fail('expected CONFLICT');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::CONFLICT, $e->getErrorCode());
            self::assertSame($duplicate, $e->getPrevious());
        }
    }

    public function testADuplicateWrappedByTheCoreIsStillAConflict(): void
    {
        // exec_query() wraps PDOException in DatabaseException, with the
        // PDOException as its previous. createDefaultMailAccounts() is where
        // that wrapping reaches a service.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $wrapped = new RuntimeException('wrapped', 23000, $this->pdoException(1062));

        $this->expectException(ApiException::class);

        (new Writer(new Db($pdo)))->run(static function () use ($wrapped) {
            throw $wrapped;
        });
    }

    public function testANotNullViolationIsNotAConflict(): void
    {
        // Measurement M8: 1048 shares SQLSTATE 23000 with 1062, and the panel
        // reads it as "already exists". It is a bug, not a clash, and must
        // surface as one.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $notNull = $this->pdoException(1048);

        try {
            (new Writer(new Db($pdo)))->run(static function () use ($notNull) {
                throw $notNull;
            });
            self::fail('expected the PDOException');
        } catch (PDOException $e) {
            self::assertSame($notNull, $e);
        }
    }
}
