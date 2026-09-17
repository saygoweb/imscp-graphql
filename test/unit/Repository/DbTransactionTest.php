<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Repository;

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
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DbTransactionTest extends TestCase
{
    public function testTheOutermostBeginOpensARealTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::never())->method('exec');

        (new Db($pdo))->beginTransaction();
    }

    public function testANestedBeginIsASavepoint(): void
    {
        // The same statements DatabaseMySQL issues (M1), so that a Db with no
        // panel behind it nests exactly as one with a panel does.
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction');
        $pdo->expects(self::once())->method('exec')->with('SAVEPOINT TRANSACTION1');

        $db = new Db($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
    }

    public function testANestedCommitReleasesTheSavepointAndTheOuterOneCommits(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::exactly(2))->method('exec')->withConsecutive(
            array('SAVEPOINT TRANSACTION1'),
            array('RELEASE SAVEPOINT TRANSACTION1')
        );
        $pdo->expects(self::once())->method('commit');

        $db = new Db($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
        $db->commit();
        $db->commit();
    }

    public function testANestedRollBackRollsBackToTheSavepointOnly(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects(self::exactly(2))->method('exec')->withConsecutive(
            array('SAVEPOINT TRANSACTION1'),
            array('ROLLBACK TO SAVEPOINT TRANSACTION1')
        );
        $pdo->expects(self::once())->method('rollBack');

        $db = new Db($pdo);
        $db->beginTransaction();
        $db->beginTransaction();
        $db->rollBack();
        $db->rollBack();
    }

    public function testCommitWithNothingOpenIsRefused(): void
    {
        // A commit with no begin is a bug in the caller. Saying so is better
        // than a PDOException about "no active transaction" from deep inside
        // whatever happened to call it.
        $this->expectException(RuntimeException::class);

        (new Db($this->createMock(PDO::class)))->commit();
    }

    public function testRollBackWithNothingOpenDoesNothing(): void
    {
        // Rollback runs on failure paths, which must never throw a second
        // exception over the first.
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('rollBack');

        (new Db($pdo))->rollBack();
    }

    public function testAnOwnerGivenAtConstructionOwnsTheCount(): void
    {
        // In the panel the owner is DatabaseMySQL. Db must not keep a second
        // count beside it: two counters that disagree about depth is the
        // failure M2 describes.
        $owner = new class {
            /** @var string[] */
            public $calls = array();
            public function beginTransaction(): void { $this->calls[] = 'begin'; }
            public function commit(): void { $this->calls[] = 'commit'; }
            public function rollBack(): void { $this->calls[] = 'rollBack'; }
        };
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::never())->method('beginTransaction');
        $pdo->expects(self::never())->method('exec');

        $db = new Db($pdo, $owner);
        $db->beginTransaction();
        $db->commit();
        $db->beginTransaction();
        $db->rollBack();

        self::assertSame(array('begin', 'commit', 'begin', 'rollBack'), $owner->calls);
    }

    public function testExecuteReturnsTheAffectedRowCount(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(array('a', 3));
        $statement->method('rowCount')->willReturn(2);
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->with('UPDATE t SET c = ? WHERE id = ?')->willReturn($statement);

        self::assertSame(
            2,
            (new Db($pdo))->execute('UPDATE t SET c = ? WHERE id = ?', array('x' => 'a', 'y' => 3))
        );
    }

    public function testLastInsertIdIsAnInteger(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('lastInsertId')->willReturn('42');

        self::assertSame(42, (new Db($pdo))->lastInsertId());
    }

    public function testADetachedHandleRefusesToOpenATransaction(): void
    {
        $this->expectException(RuntimeException::class);

        Db::detached()->beginTransaction();
    }
}
