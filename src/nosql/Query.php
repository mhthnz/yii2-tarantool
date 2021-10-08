<?php

namespace mhthnz\tarantool\nosql;

use mhthnz\tarantool\client\SpaceInterface;
use mhthnz\tarantool\Connection;
use Tarantool\Client\Exception\ClientException;
use Tarantool\Client\Request\SelectRequest;
use Tarantool\Client\Schema\IteratorTypes;
use Yii;
use yii\base\BaseObject;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;

/**
 * Query for performing nosql requests.
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Query extends BaseObject
{
    /**
     * Space name.
     * @var string|null
     */
    public $from;

    /**
     * Condition for nosql command.
     * @var array
     */
    public $where;

    /**
     * @var int
     */
    public $limit = \PHP_INT_MAX & 0xffffffff;

    /**
     * @var int
     */
    public $offset = 0;

    /**
     * Ascending or descending order.
     * @var int
     */
    public $order = SORT_ASC;

    /**
     * @var Connection|null
     */
    private $_db;

    /**
     * @var SpaceInterface|null
     */
    private $_space;

    /**
     * @var string[]
     */
    public static $ITERATOR_MAP = [
        '=' => IteratorTypes::EQ,
        '<' => IteratorTypes::LT,
        '<=' => IteratorTypes::LE,
        '>=' => IteratorTypes::GE,
        '>' => IteratorTypes::GT,
    ];

    /**
     * @param Connection $db
     */
    public function setDb(Connection $db)
    {
        $this->_db = $db;
    }


    /**
     * Set space name.
     * @param string $space
     * @return $this
     */
    public function from(string $space)
    {
        $this->from = $space;
        return $this;
    }

    /**
     * @param int $offset
     * @return $this
     */
    public function offset(int $offset)
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit)
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Condition has different formats:
     *
     * Just index key (will be used primary index):
     * ->where(10)
     *
     * Composite key (will be used primary index):
     * ->where(['field1', 'field2'])
     *
     * Sets index:
     * ->where(['>', 'index-name', 10])
     *
     * Composite key (will be user index-name index):
     * ->where(['>', 'index-name', [101, 100]])
     *
     * Select or get by primary key of space:
     * ->where(['=', 10])
     *
     * Possible iterator types: =, >, >=, <, <=
     * Bitsets, overlaps, neighbor - aren't supported yet.
     *
     * @param mixed $condition
     * @return $this
     */
    public function where($condition)
    {
        $this->where = $condition;

        return $this;
    }

    /**
     * Affects only on = condition e.g. EQ and REQ
     * @return $this
     */
    public function orderAsc()
    {
        $this->order = SORT_ASC;

        return $this;
    }

    /**
     * Affects only on = condition e.g. EQ and REQ
     * @return $this
     */
    public function orderDesc()
    {
        $this->order = SORT_DESC;

        return $this;
    }

    /**
     * Get max tuple from space by index.
     * Primary index will be used by default.
     * If where condition is set, result will be less or equal.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_index/max/
     * @param Connection|null $db
     * @return mixed|null
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function max(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->max()->queryOne();
    }

    /**
     * Get random tuple from space.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_index/random/
     * @param Connection|null $db
     * @return array|null
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function random($seed = null, ?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->random($seed)->queryOne();
    }

    /**
     * Get min tuple from space by index.
     * Primary index will be used by default.
     * If where condition is set, result will be greater or equal.
     * @see https://www.tarantool.io/en/doc/latest/reference/reference_lua/box_index/min/
     * @param Connection|null $db
     * @return mixed|null
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function min(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->min()->queryOne();
    }

    /**
     * Get number of tuples by index.
     * Primary index will be used by default.
     * @param Connection|null $db
     * @return array|mixed|null
     * @throws ClientException
     * @throws InvalidConfigException
     * @throws \Throwable
     */
    public function count(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->count()->queryScalar();
    }

    /**
     * Check that tuple exists using count.
     * @param Connection|null $db
     * @return bool
     * @throws ClientException
     * @throws InvalidConfigException
     * @throws \Throwable
     */
    public function exists(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $this->count($db) > 0;
    }

    /**
     * Retrieve all returned tuples.
     * @param Connection|null $db
     * @return array[]
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function all(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->queryAll();
    }

    /**
     * @param Connection|null $db
     * @return array|null
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function one(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->queryOne();
    }

    /**
     * Using Get for getting tuple.
     * Works only with unique indexes and with primary keys (because it's unique)
     * Limit and offset don't affect on get.
     * @param Connection|null $db
     * @return array|null
     * @throws ClientException
     * @throws InvalidConfigException
     * @throws \Throwable
     * @throws NotSupportedException
     */
    public function get(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->queryGet();
    }

    /**
     * @param Connection|null $db
     * @param int $fieldNo
     * @return array
     * @throws ClientException
     * @throws \Throwable
     * @throws InvalidConfigException
     */
    public function column($fieldNo = 0, ?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db))->queryColumn($fieldNo);
    }

    /**
     * @param Connection|null $db
     * @return Command
     * @throws InvalidConfigException
     */
    public function createCommand(?Connection $db = null)
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->createNosqlCommand($this->build($db));
    }

    /**
     * Convert space name to space id.
     * @param Connection|null $db
     * @return int
     */
    public function buildFrom(?Connection $db = null): int
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return $db->client->getSpace($this->from)->getId();
    }

    /**
     * Trying to extract index from condition and get id.
     * @param Connection|null $db
     * @return int
     */
    public function buildIndex(?Connection $db = null): int
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        $indexID = 0;
        if (!is_array($this->where) || empty($this->where) || !isset(self::$ITERATOR_MAP[$this->where[0]])) {
            return $indexID;
        }

        if (count($this->where) === 3) {
            $space = $db->client->getSpace($this->from);
            $indexID = $space->getIndexIDByName($this->where[1]);
        }

        return $indexID;
    }

    /**
     * @return int
     */
    public function buildIterator(): int
    {
        // Process non array condition
        if (!is_array($this->where) || empty($this->where)) {
            if ($this->order === SORT_ASC) {
                return IteratorTypes::EQ;
            }
            return IteratorTypes::REQ;
        }

        // Array as key (composite key for example)
        $condition = $this->where[0];
        if (is_array($condition) || !isset(self::$ITERATOR_MAP[$condition])) {
            if ($this->order === SORT_ASC) {
                return IteratorTypes::EQ;
            }
            return IteratorTypes::REQ;
        }

        // Process conditions from ITERATOR_MAP
        if ($condition === '=') {
            if ($this->order === SORT_ASC) {
                return IteratorTypes::EQ;
            }
            return IteratorTypes::REQ;
        }

        return self::$ITERATOR_MAP[$condition];
    }

    /**
     * @return array|array[]
     */
    public function buildKey(): array
    {
        if ($this->where === null) {
            return [];
        }

        if (!is_array($this->where) || empty($this->where) || !isset(self::$ITERATOR_MAP[$this->where[0]])) {
            $result = $this->where;
        } else {
            $result = end($this->where);
            reset($this->where);
        }

        if (!is_array($result)) {
            $result = [$result];
        }

        return $result;
    }

    /**
     * Building SelectRequest based on Query.
     * @param Connection|null $db
     * @return SelectRequest
     */
    public function build(?Connection $db = null): SelectRequest
    {
        if ($db === null) {
            $db = $this->_db === null ? Yii::$app->getTarantool() : $this->_db;
        }

        return new SelectRequest($this->buildFrom($db), $this->buildIndex($db), $this->buildKey(), $this->offset, $this->limit, $this->buildIterator());
    }

}