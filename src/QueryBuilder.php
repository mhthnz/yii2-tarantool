<?php

namespace mhthnz\tarantool;

use yii\base\NotSupportedException;
use yii\db\ExpressionInterface;
use yii\db\Query;

/**
 * QueryBuilder is the query builder for Tarantool database >= v 2.0
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class QueryBuilder extends \yii\db\QueryBuilder
{
    /**
     * @var Connection the database connection.
     */
    public $db;

    /**
     * @var array mapping from abstract column types (keys) to physical column types (values).
     */
    public $typeMap = [
        Schema::TYPE_PK => 'integer PRIMARY KEY AUTOINCREMENT',
        Schema::TYPE_UPK => 'unsigned PRIMARY KEY AUTOINCREMENT',
        Schema::TYPE_CHAR => 'varchar(1)',
        Schema::TYPE_STRING => 'varchar(255)',
        Schema::TYPE_TEXT => 'string',
        Schema::TYPE_INTEGER => 'integer',
        Schema::TYPE_FLOAT => 'double',
        Schema::TYPE_DOUBLE => 'double',
        Schema::TYPE_BINARY => 'varbinary',
        Schema::TYPE_BOOLEAN => 'boolean',
    ];


    /**
     * {@inheritdoc}
     */
    protected function defaultExpressionBuilders()
    {
        return [
            'yii\db\Query' => 'yii\db\QueryExpressionBuilder',
            'yii\db\Expression' => 'yii\db\ExpressionBuilder',
            'yii\db\conditions\ConjunctionCondition' => 'yii\db\conditions\ConjunctionConditionBuilder',
            'yii\db\conditions\NotCondition' => 'yii\db\conditions\NotConditionBuilder',
            'yii\db\conditions\AndCondition' => 'yii\db\conditions\ConjunctionConditionBuilder',
            'yii\db\conditions\OrCondition' => 'yii\db\conditions\ConjunctionConditionBuilder',
            'yii\db\conditions\BetweenCondition' => 'yii\db\conditions\BetweenConditionBuilder',
            'yii\db\conditions\InCondition' => 'yii\db\conditions\InConditionBuilder',
            'yii\db\conditions\LikeCondition' => 'mhthnz\tarantool\LikeConditionBuilder',
            'yii\db\conditions\ExistsCondition' => 'yii\db\conditions\ExistsConditionBuilder',
            'yii\db\conditions\SimpleCondition' => 'yii\db\conditions\SimpleConditionBuilder',
            'yii\db\conditions\HashCondition' => 'yii\db\conditions\HashConditionBuilder',
            'yii\db\conditions\BetweenColumnsCondition' => 'yii\db\conditions\BetweenColumnsConditionBuilder',
        ];
    }

    /**
     * Change ColumnSchemaBuilder namespace.
     *
     * {@inheritdoc}
     */
    public function getColumnType($type)
    {
        if ($type instanceof ColumnSchemaBuilder) {
            $type = $type->__toString();
        }

        if (isset($this->typeMap[$type])) {
            return $this->typeMap[$type];
        } elseif (preg_match('/^(\w+)\((.+?)\)(.*)$/', $type, $matches)) {
            if (isset($this->typeMap[$matches[1]])) {
                return preg_replace('/\(.+\)/', '(' . $matches[2] . ')', $this->typeMap[$matches[1]]) . $matches[3];
            }
        } elseif (preg_match('/^(\w+)\s+/', $type, $matches)) {
            if (isset($this->typeMap[$matches[1]])) {
                return preg_replace('/^\w+/', $this->typeMap[$matches[1]], $type);
            }
        }

        return $type;
    }

    /**
     * @param array $withs of configurations for each WITH query
     * @param array $params the binding parameters to be populated
     * @return string compiled WITH prefix of query including nested queries
     * @see Query::withQuery()
     * @since 2.0.35
     */
    public function buildWithQueries($withs, &$params)
    {
        if (empty($withs)) {
            return '';
        }

        $recursive = false;
        $result = [];

        foreach ($withs as $i => $with) {
            if ($with['recursive']) {
                $recursive = true;
            }

            $query = $with['query'];
            if ($query instanceof Query) {
                list($with['query'], $params) = $this->build($query, $params);
            }

            $result[] = '"'.$with['alias'] . '" AS (' . $with['query'] . ')';
        }

        return 'WITH ' . ($recursive ? 'RECURSIVE ' : '') . implode (', ', $result);
    }

    /**
     * {@inheritdoc}
     */
    public function buildUnion($unions, &$params)
    {
        if (empty($unions)) {
            return '';
        }

        $result = '';

        foreach ($unions as $i => $union) {
            $query = $union['query'];
            if ($query instanceof Query) {
                list($unions[$i]['query'], $params) = $this->build($query, $params);
            }

            $result .= 'UNION ' . ($union['all'] ? 'ALL ' : '') . ' ' . $unions[$i]['query'] . '  ';
        }

        return trim($result);
    }

    /**
     * Fix union building.
     * {@inheritdoc}
     */
    public function build($query, $params = [])
    {
        $query = $query->prepare($this);

        $params = empty($params) ? $query->params : array_merge($params, $query->params);

        $clauses = [
            $this->buildSelect($query->select, $params, $query->distinct, $query->selectOption),
            $this->buildFrom($query->from, $params),
            $this->buildJoin($query->join, $params),
            $this->buildWhere($query->where, $params),
            $this->buildGroupBy($query->groupBy),
            $this->buildHaving($query->having, $params),
        ];

        $sql = implode($this->separator, array_filter($clauses));
        $sql = $this->buildOrderByAndLimit($sql, $query->orderBy, $query->limit, $query->offset);

        if (!empty($query->orderBy)) {
            foreach ($query->orderBy as $expression) {
                if ($expression instanceof ExpressionInterface) {
                    $this->buildExpression($expression, $params);
                }
            }
        }
        if (!empty($query->groupBy)) {
            foreach ($query->groupBy as $expression) {
                if ($expression instanceof ExpressionInterface) {
                    $this->buildExpression($expression, $params);
                }
            }
        }

        $union = $this->buildUnion($query->union, $params);
        if ($union !== '') {
            $sql = "$sql{$this->separator}$union";
        }

        $with = $this->buildWithQueries($query->withQueries, $params);
        if ($with !== '') {
            $sql = "$with{$this->separator}$sql";
        }

        return [$sql, $params];
    }

    /**
     * {@inheritdoc}
     */
    public function renameTable($oldName, $newName)
    {
        return 'ALTER TABLE ' . $this->db->quoteTableName($oldName) . ' RENAME TO ' . $this->db->quoteTableName($newName);
    }


    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function resetSequence($tableName, $value = null)
    {
        throw new NotSupportedException("Resetting sequence is not realized yet");
    }

    /**
     * Tarantool doesn't support offset without limit.
     * Adding limit if we have an offset.
     * {@inheritdoc}
     */
    public function buildLimit($limit, $offset)
    {
        $sql = '';
        if ($this->hasOffset($offset) && !$this->hasLimit($limit)) {
            $sql = 'LIMIT ' . PHP_INT_MAX . ' OFFSET ' . $offset;
            return ltrim($sql);
        }
        if ($this->hasLimit($limit)) {
            $sql = 'LIMIT ' . $limit;
        }
        if ($this->hasOffset($offset)) {
            $sql .= ' OFFSET ' . $offset;
        }

        return ltrim($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function selectExists($rawSql)
    {
        return 'SELECT CASE WHEN EXISTS(SELECT * FROM(' . $rawSql . ') LIMIT 1) THEN 1 ELSE 0 END';
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function addColumn($table, $column, $type)
    {
        if (version_compare($this->db->version,  '2.7', "<")) {
            throw new NotSupportedException("Tarantool version < 2.7 doesn't support adding column.");
        }
        return parent::addColumn($table, $column, $type);
    }

    /**
     * Creates an INSERT SQL statement that works like upsert.
     * For example,
     * ```php
     * $sql = $queryBuilder->insertOrReplace('user', [
     *     'id' => 10,
     *     'name' => 'Sam',
     *     'age' => 30,
     * ], $params);
     * ```
     * The method will properly escape the table and column names.
     * If the row with id 10 already exists, other fields will be replaced.
     * @see https://github.com/tarantool/tarantool/wiki/SQL%3A-ON-CONFLICT-clause-for-INSERT,-UPDATE-statements
     *
     * @param string $table the table that new rows will be inserted into.
     * @param array|Query $columns the column data (name => value) to be inserted into the table or instance
     * of [[yii\db\Query|Query]] to perform INSERT INTO ... SELECT SQL statement.
     * Passing of [[yii\db\Query|Query]] is available since version 2.0.11.
     * @param array $params the binding parameters that will be generated by this method.
     * They should be bound to the DB command later.
     * @return string the INSERT SQL
     */
    public function insertOrReplace($table, $columns, &$params)
    {
        list($names, $placeholders, $values, $params) = $this->prepareInsertValues($table, $columns, $params);
        return 'INSERT OR REPLACE INTO ' . $this->db->quoteTableName($table)
            . (!empty($names) ? ' (' . implode(', ', $names) . ')' : '')
            . (!empty($placeholders) ? ' VALUES (' . implode(', ', $placeholders) . ')' : $values);
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function addCommentOnColumn($table, $column, $comment)
    {
         throw new NotSupportedException('Tarantool doesn\'t support comments');
    }

    /**
     * {@inheritdoc}
     */
    public function addCommentOnTable($table, $comment)
    {
        throw new NotSupportedException('Tarantool doesn\'t support comments');
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function dropCommentFromColumn($table, $column)
    {
        throw new NotSupportedException('Tarantool doesn\'t support comments');
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function dropCommentFromTable($table)
    {
        throw new NotSupportedException('Tarantool doesn\'t support comments');
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function dropColumn($table, $column)
    {
        throw new NotSupportedException("Tarantool doesn't support dropping columns");
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException
     */
    public function renameColumn($table, $oldName, $newName)
    {
        throw new NotSupportedException("Tarantool doesn't support renaming columns");
    }
}
