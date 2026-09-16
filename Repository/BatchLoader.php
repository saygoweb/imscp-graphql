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

use Anorm\Model;
use Anorm\Relationship\BatchLoader\ManyHasOneBatchLoader;
use Anorm\Relationship\BatchLoader\OneHasManyBatchLoader;
use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use InvalidArgumentException;

/**
 * One query per edge per level, whatever the number of parents.
 *
 * Spec section 10.1 asks for the DataLoader pattern and names the edges:
 * customer->domain, domain->subdomains, domain->aliases, alias->subdomains,
 * domain->mail accounts, customer->ftp users, database->sql users, and the
 * reverse of each. GraphQL\Deferred supplies the timing - it queues a callback
 * that runs only when the executor can make no further progress, which is
 * exactly the end of a level - and Anorm's two IN-clause loaders supply the
 * query.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT TOUCH
 *
 * Anorm's Relationship\Strategy\ layer, and every route into it:
 * BatchLoadingOrchestrator::loadRelationshipsForModels(),
 * Model::loadRelated(), Model::loadAllRelated(),
 * RelationshipManager::loadRelated() and OneHasMany::batchLoad(). This class
 * calls none of them. It calls OneHasManyBatchLoader and ManyHasOneBatchLoader
 * directly, which take no strategy decision at all: both emit one IN-clause
 * SELECT unconditionally, which is precisely the hand-written batch loader
 * spec section 10.1 would otherwise have the plugin write.
 *
 * Two measured facts about the vendored Anorm (v3.1.2) are why:
 *
 *   1. Strategy\QueryStrategySelector returns STRATEGY_INDIVIDUAL_LOADING when
 *      the source count is at or below its 'individual_loading_threshold',
 *      which defaults to ten. A customer has one domain and a reseller has
 *      tens of customers, so its default configuration would choose the N+1
 *      for exactly the sizes this API sees.
 *   2. Strategy\JoinWithSelectionLoader::getTableName() returns the literal
 *      string 'users' for the source side and guesses the related table by
 *      string substitution on the class name. Its own comment calls it "a
 *      simplified implementation".
 *
 * Configuring the orchestrator cannot fix either, because
 * OneHasMany::batchLoad() constructs its own `new QueryStrategySelector()`
 * with default configuration regardless of how the orchestrator was
 * configured.
 *
 * There is a unit test and an integration test asserting those five classes
 * are never loaded. Keep them passing.
 *
 * A HISTORICAL NOTE ON PHP 7.4
 *
 * This plan was drafted against Anorm v3.1.1, whose FieldSelectionParser and
 * DataSizeEstimator called str_contains(), str_starts_with() and
 * str_ends_with() - PHP 8.0 functions with no polyfill - so any path reaching
 * them was a fatal error on the panel's PHP 7.4, invisible to php -l because
 * an undefined function is a run-time error. The vendored v3.1.2 has replaced
 * those calls with strpos()/substr() and declares "php": "^7.4 || ^8.0", and
 * both classes were measured running clean on PHP 7.4.33. That hazard is gone;
 * reasons 1 and 2 above are not, and they are sufficient on their own.
 */
final class BatchLoader
{
    /** @var Db */
    private $db;

    /**
     * Models waiting on an Anorm edge.
     *
     * bucket => array(spl_object_hash => Model). A bucket is one
     * (model class, relationship name) pair, because the loaders read the
     * relationship definition off the first model in the batch.
     *
     * @var array<string, array<string, Model>>
     */
    private $pendingModels = array();

    /** @var array<string, string> bucket => relationship name */
    private $relationships = array();

    /** @var array<string, bool> bucket => true when the edge is many-has-one */
    private $isParentEdge = array();

    /**
     * Edges Anorm has already assigned, so a second ask is free.
     *
     * bucket => spl_object_hash => the model itself. The model is held rather
     * than a bare flag because spl_object_hash() reuses the hash of a
     * collected object, and a reused hash would read as "already loaded" for a
     * different model.
     *
     * @var array<string, array<string, Model>>
     */
    private $loadedEdges = array();

    /**
     * Keys waiting on a keyed() or byColumn() load.
     *
     * bucket => array(stringified key => original key).
     *
     * @var array<string, array<string, mixed>>
     */
    private $pendingKeys = array();

    /** @var array<string, callable> bucket => loader */
    private $loaders = array();

    /**
     * Answers already loaded, kept for the life of the request.
     *
     * bucket => array(stringified key => value). A parent resolved at three
     * depths of one document is asked for once.
     *
     * @var array<string, array<string, mixed>>
     */
    private $results = array();

    /** @var int */
    private $flushes = 0;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * A one-has-many edge: every child of this parent.
     *
     * @param string $relationship The property name declared with hasMany() on
     *                             the model, e.g. 'mailAccounts'
     * @return Deferred Resolves to Model[], empty when there are none
     */
    public function related(Model $parent, string $relationship): Deferred
    {
        return $this->edge($parent, $relationship, false);
    }

    /**
     * A many-has-one edge: this child's parent.
     *
     * @param string $relationship The property name declared with belongsTo()
     *                             on the model, e.g. 'customer'
     * @return Deferred Resolves to Model|null
     */
    public function parent(Model $child, string $relationship): Deferred
    {
        return $this->edge($child, $relationship, true);
    }

    /**
     * Every row of one model class whose $column holds $value.
     *
     * The read path's one Anorm entry point. A resolver that wants typed
     * property access to a single table - $domain->domain_created rather than
     * $row['domain_created'] - loads it through here and gets a Model back;
     * Task 12's Domain.createdAt is the first caller. One IN-clause query per
     * (class, column) pair per level, memoised by keyed() underneath.
     *
     * Returns a SyncPromise rather than a Deferred because it ends in
     * ->then(): SyncPromise::then() does `$child = new self()`, and `self` is
     * SyncPromise. Declaring Deferred here is a TypeError on the first call.
     *
     * @param string $modelClass A Model\* class name
     * @param string $column     A column of that model's table
     * @param mixed  $value      The value to match
     * @return SyncPromise Resolves to Model[]
     */
    public function byColumn(string $modelClass, string $column, $value): SyncPromise
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an Anorm model.', $modelClass
            ));
        }

        $db = $this->db;
        $bucket = 'col:' . $modelClass . '.' . $column;

        return $this->keyed($bucket, $value, static function (array $keys) use (
            $db, $modelClass, $column
        ) {
            $probe = new $modelClass($db->pdo());
            $table = $probe->_mapper->table;
            $in = $db->placeholders(count($keys));

            $grouped = array();

            foreach ($db->rows(
                'SELECT * FROM `' . $table . '` WHERE `' . $column . '` IN (' . $in . ')',
                $keys
            ) as $row) {
                $model = new $modelClass($db->pdo());
                $model->_mapper->readArray($model, $row);
                $grouped[(string)$row[$column]][] = $model;
            }

            return $grouped;
        })->then(static function ($models) {
            // A key with no rows resolves to null from keyed(); an edge that
            // returns a list must return a list.
            return $models === null ? array() : $models;
        });
    }

    /**
     * Anything Anorm cannot express: the vhost union, the batched counts, the
     * api_perm lookup, a filtered and paged list.
     *
     * @param string   $bucket One namespace per distinct query shape. Two
     *                         edges that share a bucket would hand each other's
     *                         answers back, so a bucket name carries the class
     *                         and the column, never just the column.
     * @param mixed    $key
     * @param callable $loader fn(array $keys): array - a key => value map.
     *                         A key the loader omits resolves to null.
     */
    public function keyed(string $bucket, $key, callable $loader): Deferred
    {
        $index = (string)$key;

        if (!isset($this->results[$bucket])
            || !array_key_exists($index, $this->results[$bucket])
        ) {
            $this->pendingKeys[$bucket][$index] = $key;
            $this->loaders[$bucket] = $loader;
        }

        return new Deferred(function () use ($bucket, $index) {
            if (isset($this->pendingKeys[$bucket][$index])) {
                $this->flushKeys($bucket);
            }

            return $this->results[$bucket][$index] ?? null;
        });
    }

    /**
     * How many batch queries have run. Task 17's threshold is measured with
     * Db::countQueries(); this is the cheaper in-process view of the same
     * thing, for a unit test that has no database.
     */
    public function flushes(): int
    {
        return $this->flushes;
    }

    public function reset(): void
    {
        $this->pendingModels = array();
        $this->relationships = array();
        $this->isParentEdge = array();
        $this->loadedEdges = array();
        $this->pendingKeys = array();
        $this->loaders = array();
        $this->results = array();
        $this->flushes = 0;
    }

    private function edge(Model $model, string $relationship, bool $isParent): Deferred
    {
        $bucket = 'rel:' . get_class($model) . '::' . $relationship;
        $index = spl_object_hash($model);

        // Enqueue only if this model's edge has not been loaded already.
        // Without this, asking the same parent twice - which a deep document
        // does whenever two children share a parent - re-enqueues it and the
        // second Deferred opens a second query for an answer the model is
        // already holding.
        //
        // The property cannot be the test: both Anorm loaders'
        // distributeBatchResults() assign unconditionally, [] for
        // one-has-many and null for many-has-one, so isset() cannot tell
        // "loaded and empty" from "never loaded". The model itself is held
        // rather than just its hash, because spl_object_hash() reuses the
        // hash of a collected object and a reused hash would read as loaded.
        if (!isset($this->loadedEdges[$bucket][$index])) {
            $this->pendingModels[$bucket][$index] = $model;
            $this->relationships[$bucket] = $relationship;
            $this->isParentEdge[$bucket] = $isParent;
        }

        return new Deferred(function () use ($bucket, $index, $model, $relationship, $isParent) {
            // Flush only if this model is still waiting. A sibling deferred in
            // the same level may already have flushed the whole bucket, and
            // flushing twice would be the second query this class exists to
            // prevent.
            if (isset($this->pendingModels[$bucket][$index])) {
                $this->flushEdge($bucket);
            }

            $value = $model->{$relationship};

            if ($isParent) {
                return $value;
            }

            return is_array($value) ? $value : array();
        });
    }

    private function flushEdge(string $bucket): void
    {
        $indexes = array_keys($this->pendingModels[$bucket]);
        $models = array_values($this->pendingModels[$bucket]);
        $relationship = $this->relationships[$bucket];
        $isParent = $this->isParentEdge[$bucket];

        unset(
            $this->pendingModels[$bucket],
            $this->relationships[$bucket],
            $this->isParentEdge[$bucket]
        );

        // Constructed per flush rather than held as a field: both loaders are
        // stateless, and holding one would be one more thing to reason about
        // when asking whether the strategy layer can be reached.
        $loader = $isParent ? new ManyHasOneBatchLoader() : new OneHasManyBatchLoader();

        $results = $loader->batchLoad($models, $relationship);
        $loader->distributeBatchResults($models, $results, $relationship);

        // Every model in this flush now carries its edge. Recording that is
        // what makes a second ask free; see edge()'s note on why the model is
        // held rather than the hash alone.
        foreach ($indexes as $position => $index) {
            $this->loadedEdges[$bucket][$index] = $models[$position];
        }

        $this->flushes++;
    }

    private function flushKeys(string $bucket): void
    {
        $keys = array_values($this->pendingKeys[$bucket]);
        $loader = $this->loaders[$bucket];

        unset($this->pendingKeys[$bucket], $this->loaders[$bucket]);

        if (!isset($this->results[$bucket])) {
            $this->results[$bucket] = array();
        }

        foreach (call_user_func($loader, $keys) as $key => $value) {
            $this->results[$bucket][(string)$key] = $value;
        }

        // Every key that was asked for gets an entry, so a second ask for a
        // key with no rows does not run the query again.
        foreach ($keys as $key) {
            if (!array_key_exists((string)$key, $this->results[$bucket])) {
                $this->results[$bucket][(string)$key] = null;
            }
        }

        $this->flushes++;
    }
}
