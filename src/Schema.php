<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace mhthnz\tarantool;

use Tarantool\Client\Exception\ClientException;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\CheckConstraint;
use yii\db\Constraint;
use yii\db\ConstraintFinderInterface;
use yii\db\ConstraintFinderTrait;
use yii\db\ForeignKeyConstraint;
use yii\db\IndexConstraint;
use yii\helpers\ArrayHelper;
use Yii;

/**
 * Schema is the class for retrieving metadata from a Tarantool database ver. >= 2.4.1
 *
 * @author mhthnz <mhthnz@gmail.com>
 */
class Schema extends \yii\db\Schema implements ConstraintFinderInterface
{
    use ConstraintFinderTrait;

    /**
     * Non-associative array.
     */
    const TYPE_ARRAY = 'array';

    /**
     * Associative array with all string keys.
     */
    const TYPE_MAP = 'map';

    /**
     * One of null, boolean, string, integer, double, bin, decimal, uuid, ext.
     */
    const TYPE_SCALAR = 'scalar';

    /**
     * {@inheritdoc}
     */
    public $columnSchemaClass = 'mhthnz\tarantool\ColumnSchema';

    /**
     * @var array mapping from physical column types (keys) to abstract column types (values)
     * Please refer to [Tarantool types](https://www.tarantool.io/en/doc/latest/book/box/data_model/#field-type-details) for
     * details on data types.
     */
    public $typeMap = [
        'boolean' => self::TYPE_BOOLEAN,
        'bool' => self::TYPE_BOOLEAN,
        'double' => self::TYPE_DOUBLE,
        'integer' => self::TYPE_INTEGER,
        'int' => self::TYPE_INTEGER,
        'number' => self::TYPE_FLOAT,
        'scalar' => self::TYPE_SCALAR,
        'string' => self::TYPE_STRING,
        'text' => self::TYPE_STRING,
        'varchar' => self::TYPE_STRING,
        'unsigned' => self::TYPE_INTEGER,
        'bin' => self::TYPE_BINARY,
        'varbinary' => self::TYPE_BINARY,

        // Not supported in sql yet
//        'uuid' => self::TYPE_STRING,
//        'ext' => self::TYPE_DECIMAL,
//        'array' => self::TYPE_ARRAY,
//        'map' => self::TYPE_MAP,
//        'decimal' => self::TYPE_DECIMAL,
    ];

    /**
     * @var array map of DB errors and corresponding exceptions
     * If left part is found in DB error message exception class from the right part is used.
     */
    public $exceptionMap = [
        'Operation would have caused one or more unique constraint violations' => 'yii\db\IntegrityException',
    ];

    /**
     * @var Connection the database connection
     */
    public $db;

    /**
     * {@inheritdoc}
     */
    protected $tableQuoteCharacter = '"';

    /**
     * {@inheritdoc}
     */
    public function insert($table, $columns)
    {
        $command = $this->db->createCommand()->insert($table, $columns);
        if (!$command->execute()) {
            return false;
        }
        $tableSchema = $this->getTableSchema($table);
        $result = [];
        foreach ($tableSchema->primaryKey as $name) {
            // Explicit primary key
            if ($tableSchema->columns[$name]->autoIncrement && (!isset($columns[$name]) || !is_numeric($columns[$name]))) {
                $result[$name] = $this->db->lastInsertID;
                break;
            }

            $result[$name] = isset($columns[$name]) ? $columns[$name] : $tableSchema->columns[$name]->defaultValue;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function findTableNames($schema = '')
    {
        $result = $this->db->createCommand('select "name" from "_space" where LENGTH("format") > 1 AND substr("name",1,1) != \'_\'')->queryAll();
        $tableNames = [];
        foreach ($result as $row) {
            $tableNames[] = $row["name"];
        }

        return $tableNames;
    }

    /**
     * {@inheritdoc}
     */
    public function getServerVersion()
    {
        return $this->db->version;
    }

    /**
     * {@inheritdoc}
     */
    protected function loadTableSchema($name)
    {
        $data = $this->db->createCommand('select "format", "id", "engine" from "_space" where "name" = :table', [':table' => $name])->queryOne();

        if ($data === false) {
            return null;
        }
        $tableID = $data["id"];
        $table = new TableSchema();
        $table->fullName = $table->name = $name;
        $table->engine = $data["engine"];

        // Getting primary index
        $parts = $this->db->createCommand('select "parts" from "_index" where "id" = :tableID limit 1', [':tableID' => $tableID])->queryOne();
        $primaryFields = [];
        if ($parts !== false) {
            $primaryFields = ArrayHelper::getColumn($parts["parts"], 'field');
        }

        // Getting autoincrement fields
        /** @var DataReader $dataReader */
        $dataReader = $this->db->createCommand('select * from "_space_sequence" where "id" = :tableID', [':tableID' => $tableID])->query();
        $sequences = [];
        if ($dataReader->count()) {
            foreach ($dataReader as $seq) {
                if (isset($data['format'][$seq['field']])) {
                    // fieldNo => sequenceID
                    $sequences[$seq['field']] = $seq['sequence_id'];
                }
            }
        }

        // Filling column schema, pk, autoincrement
        foreach ($data["format"] as $key => $info) {
            $info['primary'] = $info['autoincrement'] = false;
            if (in_array($key, $primaryFields)) {
                $info['primary'] = true;
                $table->primaryKey[] = $info['name'];
            }
            if (array_key_exists($key, $sequences)) {
                $info['autoincrement'] = true;
                $table->sequenceName = $sequences[$key];
            }
            $column = $this->loadColumnSchema($info);
            $table->columns[$column->name] = $column;
            $table->addField($key, $info['name']);
        }

        // Processing foreign keys
        $dataReader = $this->db->createCommand('
            select "_fk_constraint"."name","_fk_constraint"."child_cols", "_fk_constraint"."parent_cols", "p"."name" as "tableName", "_space"."format" as "childFormat", "p"."format" as "parentFormat"
            from "_fk_constraint" 
            left join "_space" on "_space"."id" = "_fk_constraint"."child_id"
            left join "_space" as "p" on "p"."id" = "_fk_constraint"."parent_id" 
            where "child_id" = :tableID
        ', [':tableID' => $tableID])->query();
        foreach ($dataReader as $row) {
            $table->foreignKeys[$row["name"]] = [
                $row["tableName"]
            ];

            foreach ($row["parent_cols"] as $key => $fieldNo) {
                $childFieldName = $row["childFormat"][$row["child_cols"][$key]]["name"];
                $parentFieldName = $row["parentFormat"][$fieldNo]["name"];
                $table->foreignKeys[$row["name"]][$childFieldName] = $parentFieldName;
            }
        }

        return $table;
    }

    /**
     * {@inheritdoc}
     */
    public function quoteValue($str)
    {
        if (!is_string($str)) {
            return $str;
        }

        return "'" . addcslashes(str_replace("'", "''", $str), "\000\n\r\032") . "'";
    }


    /**
     * {@inheritdoc}
     */
    protected function loadTablePrimaryKey($tableName)
    {
        $result = $this->db->createCommand('
        select "_index"."name", "_index"."parts", "_space"."format" from "_index", "_space"
        where "_space"."name" = :table AND "_index"."id" = "_space"."id" 
        limit 1
        ', [':table' => $tableName])->queryOne();

        if ($result === false) {
            return null;
        }

        $primaryKey = $result;
        $pkName = $primaryKey["name"];
        $fields = [];
        foreach(ArrayHelper::getColumn($primaryKey['parts'], 'field') as $fieldNo) {
            $fields[] = $primaryKey['format'][$fieldNo]["name"];
        }

        return new Constraint([
            'name' => $pkName,
            'columnNames' => $fields,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function loadTableForeignKeys($tableName)
    {
        static $actionTypes = [
            'cascade' => 'CASCADE',
            'restrict' => 'RESTRICT',
            'no_action' => 'NO ACTION',
            'set_null' => 'SET NULL',
            'set_default' => 'SET DEFAULT'
        ];

        // Processing foreign keys
        /** @var DataReader $dataReader */
        $dataReader = $this->db->createCommand('
            SELECT "fk"."name",
                   "fk"."child_cols", 
                   "fk"."parent_cols", 
                   "fk"."on_delete",
                   "fk"."on_update",
                   "child"."name" AS "childTableName", 
                   "parent"."name" AS "parentTableName", 
                   "child"."format" AS "childFormat", 
                   "parent"."format" AS "parentFormat"
            FROM "_fk_constraint" AS "fk", "_space" AS "parent"
            LEFT JOIN "_space" AS "child" ON "child"."id" = "fk"."child_id"
            WHERE "child"."name" = :tableName AND "parent"."id" = "fk"."parent_id" AND "child"."id" IS NOT NULL  
        ', [':tableName' => $tableName])->query();

        $result = [];
        foreach ($dataReader as $row) {
            $parentFields = $childFields = [];
            foreach ($row["parent_cols"] as $key => $fieldNo) {
                $parentFields[] = $row["parentFormat"][$fieldNo]["name"];
                $childFields[] = $row["childFormat"][$row["child_cols"][$key]]["name"];
            }
            $result[] = new ForeignKeyConstraint([
                'name' => $row["name"],
                'columnNames' => $childFields,
                'foreignTableName' => $row["parentTableName"],
                'foreignColumnNames' => $parentFields,
                'onDelete' => isset($actionTypes[$row["on_delete"]]) ? $actionTypes[$row["on_delete"]] : null,
                'onUpdate' => isset($actionTypes[$row["on_update"]]) ? $actionTypes[$row["on_update"]] : null,
            ]);
        }

        $this->setTableMetadata($tableName, 'foreignKeys', $result);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function loadTableIndexes($tableName)
    {
        return $this->loadTableConstraints($tableName, 'indexes');
    }

    /**
     * {@inheritdoc}
     */
    protected function loadTableUniques($tableName)
    {
        return $this->loadTableConstraints($tableName, 'uniques');
    }


    /**
     * Loads all check constraints for the given table.
     * @param string $tableName table name.
     * @return CheckConstraint[] check constraints for the given table.
     */
    protected function loadTableChecks($tableName)
    {
        /** @var DataReader $dataReader */
        $dataReader = $this->db->createCommand('
            select "i".*, "t"."format" from "_ck_constraint" AS "i", "_space" AS "t"
            WHERE "t"."name" = :tableName AND "i"."space_id" = "t"."id"
        ', [':tableName' => $tableName])->query();


        $result = [];
        foreach ($dataReader->readAll() as $key => $row) {

            // Tarantool doesn't allow to see check index's fields, trying to parse it from expr
            $columns = ArrayHelper::getColumn($row['format'], 'name');
            $checkColumns = [];
            foreach ($columns as $column) {
                if (strpos($row["code"], $column) !== false) {
                    $checkColumns[] = $column;
                }
            }
            $result[] = new CheckConstraint([
                'name' => $row["name"],
                'expression' => $row["code"],
                'columnNames' => $checkColumns,
            ]);
        }

        $this->setTableMetadata($tableName, 'checks', $result);
        return $result;
    }

    /**
     * {@inheritdoc}
     * @throws NotSupportedException if this method is called.
     */
    protected function loadTableDefaultValues($tableName)
    {
        throw new NotSupportedException('Tarantool does not support default value constraints.');
    }


    /**
     * Creates a query builder for the Tarantool database.
     * @return QueryBuilder query builder instance
     */
    public function createQueryBuilder()
    {
        return new QueryBuilder($this->db);
    }

    /**
     * @param \yii\db\TableSchema $table
     * @return array
     */
    public function findUniqueIndexes($table)
    {
        $result = [];
        foreach ($this->loadTableConstraints($table->name, 'uniques') as $row) {
            $result[$row->name] = $row->columnNames;
        }
        return $result;
    }

    /**
     * Creates a column schema for the database.
     * This method may be overridden by child classes to create a DBMS-specific column schema.
     * @return ColumnSchema column schema instance.
     * @throws InvalidConfigException if a column schema class cannot be created.
     */
    protected function createColumnSchema()
    {
        return Yii::createObject($this->columnSchemaClass);
    }

    /**
     * Loads the column information into a [[ColumnSchema]] object.
     * @param array $info column information
     * array(4) { ["name"]=> string(7) "columnname" ["type"]=> string(6) "string" ["is_nullable"]=> bool(true) ["nullable_action"]=> string(4) "none" }
     * or
     *{ ["name"]=> string(8) "columnname" ["type"]=> string(6) "string" }
     * @return ColumnSchema the column schema object
     */
    protected function loadColumnSchema($info)
    {
        $column = $this->createColumnSchema();

        $column->name = $info['name'];
        $column->allowNull = !isset($info['is_nullable']) ? true : $info['is_nullable'];
        $column->isPrimaryKey = $info['primary'];
        $column->autoIncrement = $info['autoincrement'];
        $val = isset($info['default']) ? $info['default'] : null;

        // I got 'defaultValue' instead of just defaultValue string
        if (($info['type'] === Schema::TYPE_STRING || $info['type'] === Schema::TYPE_TEXT || $info['type'] === Schema::TYPE_CHAR) && ($strlen = strlen(/** @scrutinizer ignore-type */ $val)) >= 2) {
            if ($val[0] === "'" && $val[$strlen - 1] === "'") {
                $val = substr($val, 1, $strlen - 2);
            }
        }
        // I got boolean default value as a string
        else if ($info['type'] === Schema::TYPE_BOOLEAN && is_string($val)) {
            $val = $val === 'true';
        }
        $column->defaultValue = $val;
        $column->dbType = $info['type'];
        $column->unsigned = $info['type'] === "unsigned";

        $column->type = self::TYPE_STRING;
        if (preg_match('/^(\w+)(?:\(([^\)]+)\))?/', $column->dbType, $matches)) {
            $type = strtolower($matches[1]);
            if (isset($this->typeMap[$type])) {
                $column->type = $this->typeMap[$type];
            }
            if (!empty($matches[2])) {
                    $values = explode(',', $matches[2]);
                    $column->size = $column->precision = (int) $values[0];
                    if (isset($values[1])) {
                        $column->scale = (int) $values[1];
                    }
            }
        }
        $column->phpType = $this->getColumnPhpType($column);
        return $column;
    }

    /**
     * Extracts the PHP type from abstract DB type.
     * @param ColumnSchema $column the column schema information
     * @return string PHP type name
     */
    protected function getColumnPhpType($column)
    {
        if ($column->type === self::TYPE_MAP || $column->type === self::TYPE_ARRAY) {
            return 'array';
        }
        return parent::getColumnPhpType($column);
    }

    /**
     * {@inheritdoc}
     * @throws \Exception
     */
    public function setTransactionIsolationLevel($level)
    {
        throw new \Exception("Tarantool doesn't support isolation levels.");
    }

    /**
     * {@inheritdoc}
     */
    public function createColumnSchemaBuilder($type, $length = null)
    {
        return new ColumnSchemaBuilder($type, $length, $this->db);
    }

    /**
     * Loads multiple types of constraints and returns the specified ones.
     * @param string $tableName table name.
     * @param string $returnType return type:
     * - indexes
     * - uniques
     * @return mixed constraints.
     */
    private function loadTableConstraints($tableName, $returnType)
    {
        /** @var DataReader $dataReader */
        $dataReader = $this->db->createCommand('
            select "i"."name", "t"."format", "i"."opts", "i"."parts" from "_index" AS "i", "_space" AS "t"
            WHERE "t"."name" = :tableName AND "t"."id" = "i"."id"
        ', [':tableName' => $tableName])->query();

        $result = [
            'indexes' => [],
            'uniques' => [],
        ];

        foreach ($dataReader->readAll() as $key => $row) {
            $isPrimary = $key === 0 ? true : false;
            $name = $row['name'];
            $isUnique = $row['opts']['unique'];
            $parts = ArrayHelper::getColumn($row['parts'], 'field');
            $fields = [];
            foreach ($parts as $part) {
                $fields[] = $row['format'][$part]['name'];
            }
            $result['indexes'][] = new IndexConstraint([
                'isPrimary' => $isPrimary,
                'isUnique' => $isUnique,
                'name' => $name,
                'columnNames' => $fields,
            ]);
            if ($isUnique) {
                $result['uniques'][] = new Constraint([
                    'name' => $name,
                    'columnNames' => $fields,
                ]);
            }
        }

        foreach ($result as $type => $data) {
            $this->setTableMetadata($tableName, $type, $data);
        }

        return $result[$returnType];
    }

    /**
     * {@inheritdoc}
     */
    public function getLastInsertID($sequenceName = '')
    {
        return $this->db->lastInsertID;
    }


    /**
     * Converts a DB exception to a more concrete one if possible.
     *
     * @param \Exception $e
     * @param string $rawSql SQL that produced exception
     * @return \Exception
     */
    public function convertException(\Exception $e, $rawSql)
    {
        if ($e instanceof \Exception) {
            return $e;
        }

        $exceptionClass = '\yii\db\Exception';
        foreach ($this->exceptionMap as $error => $class) {
            if (strpos($e->getMessage(), $error) !== false) {
                $exceptionClass = $class;
            }
        }
        $message = $e->getMessage() . "\nThe SQL being executed was: $rawSql";
        $errorInfo = $e instanceof ClientException ? $e->errorInfo : null;
        return new $exceptionClass($message, $errorInfo, $e->getCode(), $e);
    }


    /**
     * {@inheritdoc}
     * @param string $name table name. The table name may contain schema name if any. Do not quote the table name.
     * @param bool $refresh whether to reload the table schema even if it is found in the cache.
     * @return TableSchema|null table metadata. `null` if the named table does not exist.
     */
    public function getTableSchema($name, $refresh = false)
    {
        return $this->getTableMetadata($name, 'schema', $refresh);
    }

    /**
     * {@inheritdoc}
     */
    protected function getCacheKey($name)
    {
        return [
            __CLASS__,
            $this->db->dsn,
            $this->db->instanceUuid,
            $this->getRawTableName($name),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getCacheTag()
    {
        return md5(serialize([
            __CLASS__,
            $this->db->dsn,
            $this->db->instanceUuid,
        ]));
    }

}
