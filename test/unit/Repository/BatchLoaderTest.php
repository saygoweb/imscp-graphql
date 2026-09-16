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

use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use iMSCP\Plugin\SGW_GraphQL\Model\AdminModel;
use iMSCP\Plugin\SGW_GraphQL\Repository\BatchLoader;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BatchLoaderTest extends TestCase
{
    /** @var array<int, array> Every key list the loader was handed. */
    private $calls = array();

    protected function setUp(): void
    {
        $this->calls = array();
    }

    private function loader(): BatchLoader
    {
        return new BatchLoader(Db::detached());
    }

    /**
     * A loader that squares its keys and records what it was asked for.
     */
    private function squares(): callable
    {
        return function (array $keys) {
            $this->calls[] = $keys;
            $out = array();

            foreach ($keys as $key) {
                $out[$key] = $key * $key;
            }

            return $out;
        };
    }

    /**
     * Drain the deferred queue the way GraphQL's SyncPromiseAdapter does.
     */
    private function drain(): void
    {
        SyncPromise::runQueue();
    }

    private function valueOf(Deferred $deferred)
    {
        $this->drain();

        self::assertSame(SyncPromise::FULFILLED, $deferred->state);

        return $deferred->result;
    }

    public function testTenKeysCostOneCallNotTen(): void
    {
        // The whole reason this class exists. A resolver that asked per parent
        // would produce ten calls here, which is the N+1 spec section 10.1
        // calls a defect.
        $loader = $this->loader();
        $deferreds = array();

        for ($i = 1; $i <= 10; $i++) {
            $deferreds[$i] = $loader->keyed('squares', $i, $this->squares());
        }

        $this->drain();

        self::assertCount(1, $this->calls);
        self::assertSame(range(1, 10), $this->calls[0]);
        self::assertSame(1, $loader->flushes());
        self::assertSame(49, $deferreds[7]->result);
    }

    public function testTheSameKeyTwiceIsAskedForOnce(): void
    {
        $loader = $this->loader();

        $a = $loader->keyed('squares', 4, $this->squares());
        $b = $loader->keyed('squares', 4, $this->squares());

        $this->drain();

        self::assertSame(array(array(4)), $this->calls);
        self::assertSame(16, $a->result);
        self::assertSame(16, $b->result);
    }

    public function testAKeyAskedForAgainAfterAFlushIsNotAskedForAgain(): void
    {
        // Memoisation across levels. A deep document resolves the same parent
        // at several depths; the second one must be free.
        $loader = $this->loader();

        $first = $loader->keyed('squares', 3, $this->squares());
        $this->drain();

        $second = $loader->keyed('squares', 3, $this->squares());
        $this->drain();

        self::assertCount(1, $this->calls);
        self::assertSame(9, $second->result);
        self::assertSame(1, $loader->flushes());
    }

    public function testDifferentBucketsDoNotShareKeys(): void
    {
        // Two edges both keyed by domain_id would otherwise hand each other's
        // answers back - a cross-object data leak, not merely a wrong count.
        $loader = $this->loader();

        $square = $loader->keyed('squares', 5, $this->squares());
        $double = $loader->keyed('doubles', 5, function (array $keys) {
            $this->calls[] = $keys;

            return array(5 => 10);
        });

        $this->drain();

        self::assertSame(25, $square->result);
        self::assertSame(10, $double->result);
        self::assertSame(2, $loader->flushes());
    }

    public function testAKeyTheLoaderOmitsResolvesToNull(): void
    {
        // "No rows" and "not asked" must not be told apart by the resolver:
        // it gets null and renders an absent object, rather than a warning.
        $loader = $this->loader();

        $deferred = $loader->keyed('sparse', 9, function (array $keys) {
            $this->calls[] = $keys;

            return array();
        });

        self::assertNull($this->valueOf($deferred));
    }

    public function testAKeyTheLoaderOmitsIsNotAskedForAgain(): void
    {
        // The other half of the omitted-key contract. "No rows" must be
        // memoised just as a hit is, or a resolver that asks twice for a
        // parent that has none pays for two queries - the N+1 coming back for
        // exactly the objects that have nothing to show.
        $loader = $this->loader();
        $absent = function (array $keys) {
            $this->calls[] = $keys;

            return array();
        };

        $first = $loader->keyed('sparse', 9, $absent);
        $this->drain();

        $second = $loader->keyed('sparse', 9, $absent);
        $this->drain();

        self::assertCount(1, $this->calls, 'an empty answer must be memoised too');
        self::assertNull($first->result);
        self::assertNull($second->result);
        self::assertSame(1, $loader->flushes());
    }

    public function testStringKeysWork(): void
    {
        // ftp_users is keyed by 'user@domain'.
        $loader = $this->loader();

        $deferred = $loader->keyed('ftp', 'shop@example.test', function (array $keys) {
            $this->calls[] = $keys;

            return array('shop@example.test' => 'ok');
        });

        self::assertSame('ok', $this->valueOf($deferred));
        self::assertSame(array(array('shop@example.test')), $this->calls);
    }

    public function testResetForgetsEverything(): void
    {
        $loader = $this->loader();

        $loader->keyed('squares', 2, $this->squares());
        $this->drain();
        $loader->reset();
        $loader->keyed('squares', 2, $this->squares());
        $this->drain();

        self::assertCount(2, $this->calls, 'reset must drop the memo, not keep it');
        // One, not zero: reset() zeroes the counter and the flush that follows
        // it increments the counter again. Asserting zero here would only pass
        // if reset() had also broken the memo's own bookkeeping.
        self::assertSame(1, $loader->flushes(), 'reset must zero the counter, and the next flush counts');
    }

    public function testResetZeroesTheCounterOnItsOwn(): void
    {
        $loader = $this->loader();

        $loader->keyed('squares', 2, $this->squares());
        $this->drain();

        self::assertSame(1, $loader->flushes());

        $loader->reset();

        self::assertSame(0, $loader->flushes());
    }


    /**
     * A loader that throws the first time it is called, and records the call.
     */
    private function throwing(): callable
    {
        return function (array $keys) {
            $this->calls[] = $keys;

            throw new RuntimeException('the connection went away');
        };
    }

    public function testASiblingOfAFailedKeyFlushDoesNotResolveToNull(): void
    {
        // GraphQL\Deferred catches an executor's throw per promise, so a
        // failed batch rejects only the promise that happened to trigger it.
        // If the pending list were cleared before the loader ran, every
        // sibling in the batch would find nothing pending and fall through to
        // "no answer for this key" - which keyed() spells null and byColumn()
        // turns into an empty list. A dropped connection would then read as
        // "this customer has nothing", inside a 200 response.
        $loader = $this->loader();
        $throwing = $this->throwing();

        $a = $loader->keyed('squares', 1, $throwing);
        $b = $loader->keyed('squares', 2, $throwing);

        $this->drain();

        self::assertSame(SyncPromise::REJECTED, $a->state);
        self::assertSame(
            SyncPromise::REJECTED, $b->state,
            'a sibling of a failed batch must fail, not report no rows'
        );
    }

    public function testAFailedByColumnFlushDoesNotReadAsNoRowsForSiblings(): void
    {
        // The same hazard one layer up. byColumn() ends in ->then(), which
        // maps keyed()'s null to array(), so a sibling that fell through
        // would hand a resolver an empty list of models.
        $loader = new BatchLoader(new Db($this->createMock(PDO::class)));

        $a = $loader->byColumn(AdminModel::class, 'created_by', 1);
        $b = $loader->byColumn(AdminModel::class, 'created_by', 2);

        $this->drain();

        self::assertSame(SyncPromise::REJECTED, $a->state);
        self::assertSame(
            SyncPromise::REJECTED, $b->state,
            'a failed query must not read as an empty result set'
        );
    }

    public function testASiblingOfAFailedEdgeFlushDoesNotResolveToAnEmptyList(): void
    {
        // flushEdge()'s half of the same defect. Anorm's
        // distributeBatchResults() is what assigns the edge property, so a
        // batchLoad() that throws leaves every model's property untouched -
        // null, never assigned - and edge()'s is_array() conversion turns
        // that into array(). Ten customers, one dropped connection, nine
        // reporting zero mail accounts.
        $pdo = $this->createMock(PDO::class);
        $loader = $this->loader();
        $deferreds = array();

        for ($i = 1; $i <= 2; $i++) {
            $model = new AdminModel($pdo);
            $model->admin_id = $i;
            $deferreds[] = $loader->related($model, 'domains');
        }

        $this->drain();

        self::assertSame(SyncPromise::REJECTED, $deferreds[0]->state);
        self::assertSame(
            SyncPromise::REJECTED, $deferreds[1]->state,
            'a sibling of a failed edge batch must fail, not report no children'
        );
    }

    public function testANullKeyResolvesToNullWithoutAQuery(): void
    {
        // domain.domain_ip_id is nullable, and byColumn(ServerIpModel::class,
        // 'ip_id', null) is the caller. SQL's IN (NULL) is never true, so the
        // query would cost a round trip to learn nothing.
        $loader = $this->loader();

        $deferred = $loader->keyed('ips', null, $this->squares());

        self::assertNull($this->valueOf($deferred));
        self::assertSame(array(), $this->calls, 'a null key must cost no query');
        self::assertSame(0, $loader->flushes());
    }

    public function testANullKeyDoesNotPoisonTheNextFlushOfItsBucket(): void
    {
        // The half that is not benign. A null key left sitting in the pending
        // list is dragged along by the next flush of that bucket and binds a
        // spurious NULL into the IN clause.
        $loader = $this->loader();

        $absent = $loader->keyed('ips', null, $this->squares());
        $present = $loader->keyed('ips', 5, $this->squares());

        $this->drain();

        self::assertNull($absent->result);
        self::assertSame(25, $present->result);
        self::assertSame(
            array(array(5)), $this->calls, 'the IN list must carry no NULL'
        );
    }

    public function testAColumnNameThatIsNotAnIdentifierIsRefused(): void
    {
        // byColumn() interpolates the column into backticks. The model class
        // beside it is validated; this is the argument that would survive
        // until one of them became caller-supplied.
        $loader = $this->loader();

        $this->expectException(InvalidArgumentException::class);

        $loader->byColumn(AdminModel::class, 'admin_id` = 1 OR `1', 1);
    }

    public function testAColumnNameIsCheckedBeforeAnythingIsEnqueued(): void
    {
        // Eagerly, not at flush time: a rejected promise three levels into a
        // document is a worse report than a refused call.
        $loader = $this->loader();

        try {
            $loader->byColumn(AdminModel::class, 'no spaces allowed', 1);
            self::fail('an invalid column must be refused');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('no spaces allowed', $e->getMessage());
        }

        $this->drain();
        self::assertSame(0, $loader->flushes());
    }

    public function testADetachedHandleRefusesToQuery(): void
    {
        // If a unit test ever reaches a real query it must say so, not return
        // an empty result set that looks like "this customer has nothing".
        $this->expectException(RuntimeException::class);

        Db::detached()->rows('SELECT 1');
    }

    public function testAnAnormStrategyClassIsNeverLoaded(): void
    {
        // The containment this whole design exists for. Anorm's Strategy layer
        // decides, per call, whether to batch at all - and its default
        // configuration picks individual loading, the N+1, at ten source
        // models or fewer, which is exactly the size this API sees. Its
        // JoinWithSelectionLoader is a stub that guesses table names. Neither
        // is on this class's path, and this asserts it stays that way.
        // class_exists(..., false) does not autoload, so this asserts what has
        // actually been loaded rather than what could be.
        $loader = $this->loader();
        $loader->keyed('squares', 1, $this->squares());
        $this->drain();

        foreach (array(
            'Anorm\Relationship\BatchLoadingOrchestrator',
            'Anorm\Relationship\Strategy\QueryStrategySelector',
            'Anorm\Relationship\Strategy\FieldSelectionParser',
            'Anorm\Relationship\Strategy\DataSizeEstimator',
            'Anorm\Relationship\Strategy\JoinWithSelectionLoader'
        ) as $class) {
            self::assertFalse(class_exists($class, false), $class . ' was loaded');
        }
    }
}
