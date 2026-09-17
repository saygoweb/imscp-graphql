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

    public function testARollBackThatThrowsKeepsTheOriginalException(): void
    {
        // rollBack() runs on a failure path; a second exception from it must
        // never replace the one that caused the rollback in the first place.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willThrowException(new RuntimeException('rollback also failed'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        (new Writer(new Db($pdo)))->run(static function () {
            throw new RuntimeException('boom');
        });
    }

    public function testACommitThatThrowsIsRethrownAndRollBackIsNeverCalled(): void
    {
        // Db::commit() decrements its own-counter depth before asking PDO to
        // commit. Rolling back after a failed commit would decrement the
        // depth a second time and undo the enclosing transaction level.
        $pdo = $this->createMock(PDO::class);
        // Db::commit() drops its own-counter depth to 0 before asking PDO to
        // commit, so a real PDO transaction can still be open when commit()
        // throws; inTransaction() true makes sure this test would catch
        // Writer calling rollBack() regardless, not merely pass because
        // Db::rollBack() declined to act on it.
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('commit')->willThrowException(new RuntimeException('commit failed'));
        $pdo->expects(self::never())->method('rollBack');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('commit failed');

        (new Writer(new Db($pdo)))->run(static function () {
            return 42;
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
