<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\tests\classes\AnyCaseValue;
use mhthnz\tarantool\tests\classes\AnyValue;
use mhthnz\tarantool\Connection;
use yii\caching\ArrayCache;
use yii\caching\FileCache;
use yii\db\CheckConstraint;
use yii\db\ColumnSchema;
use yii\db\Constraint;
use yii\db\Expression;
use yii\db\ForeignKeyConstraint;
use yii\db\IndexConstraint;
use mhthnz\tarantool\Schema;
use yii\db\TableSchema;

class SchemaTest extends TestCase
{
    use DbTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication();
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        $this->createStructure();
        $this->getConnection()->createCommand()->createView('animal_view', 'SELECT * FROM "animal"')->execute();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->dropTables();
        parent::tearDown();
    }

    public function testGetTableNames()
    {
        $connection = $this->getConnection();

        /* @var $schema Schema */
        $schema = $connection->schema;

        $tables = $schema->getTableNames();
        $this->assertContains('customer', $tables);
        $this->assertContains('category', $tables);
        $this->assertContains('item', $tables);
        $this->assertContains('order', $tables);
        $this->assertContains('order_item', $tables);
        $this->assertContains('type', $tables);
        $this->assertContains('animal', $tables);
        $this->assertContains('animal_view', $tables);
    }


    public function testGetTableSchemas()
    {
        $connection = $this->getConnection();

        /* @var $schema Schema */
        $schema = $connection->schema;

        $tables = $schema->getTableSchemas();
        $this->assertEquals(\count($schema->getTableNames()), \count($tables));
        foreach ($tables as $table) {
            $this->assertInstanceOf('yii\db\TableSchema', $table);
        }
    }

    public function testGetNonExistingTableSchema()
    {
        $this->assertNull($this->getConnection()->schema->getTableSchema('nonexisting_table'));
    }

    public function testSchemaCache()
    {
        /* @var $db Connection */
        $db = $this->getConnection();

        /* @var $schema Schema */
        $schema = $db->schema;

        $schema->db->enableSchemaCache = true;
        $schema->db->schemaCache = new FileCache();
        $noCacheTable = $schema->getTableSchema('type', true);
        $cachedTable = $schema->getTableSchema('type', false);
        $this->assertEquals($noCacheTable, $cachedTable);

        $db->createCommand()->renameTable('type', 'type_test');
        $noCacheTable = $schema->getTableSchema('type', true);
        $this->assertNotSame($noCacheTable, $cachedTable);

        $db->createCommand()->renameTable('type_test', 'type');
    }

    /**
     * @depends testSchemaCache
     */
    public function testRefreshTableSchema()
    {
        /* @var $schema Schema */
        $schema = $this->getConnection()->schema;

        $schema->db->enableSchemaCache = true;
        $schema->db->schemaCache = new FileCache();
        $noCacheTable = $schema->getTableSchema('type', true);

        $schema->refreshTableSchema('type');
        $refreshedTable = $schema->getTableSchema('type', false);
        $this->assertNotSame($noCacheTable, $refreshedTable);
    }

    public function tableSchemaCachePrefixesProvider()
    {
        $configs = [
            [
                'prefix' => '',
                'name' => 'type',
            ],
            [
                'prefix' => '',
                'name' => '{{%type}}',
            ],
            [
                'prefix' => 'ty',
                'name' => '{{%pe}}',
            ],
        ];
        $data = [];
        foreach ($configs as $config) {
            foreach ($configs as $testConfig) {
                if ($config === $testConfig) {
                    continue;
                }

                $description = sprintf(
                    "%s (with '%s' prefix) against %s (with '%s' prefix)",
                    $config['name'],
                    $config['prefix'],
                    $testConfig['name'],
                    $testConfig['prefix']
                );
                $data[$description] = [
                    $config['prefix'],
                    $config['name'],
                    $testConfig['prefix'],
                    $testConfig['name'],
                ];
            }
        }
        return $data;
    }

    /**
     * @dataProvider tableSchemaCachePrefixesProvider
     * @depends      testSchemaCache
     */
    public function testTableSchemaCacheWithTablePrefixes($tablePrefix, $tableName, $testTablePrefix, $testTableName)
    {
        /* @var $schema Schema */
        $schema = $this->getConnection()->schema;
        $schema->db->enableSchemaCache = true;

        $schema->db->tablePrefix = $tablePrefix;
        $schema->db->schemaCache = new ArrayCache();
        $noCacheTable = $schema->getTableSchema($tableName, true);
        $this->assertInstanceOf(TableSchema::className(), $noCacheTable);

        // Compare
        $schema->db->tablePrefix = $testTablePrefix;
        $testNoCacheTable = $schema->getTableSchema($testTableName);
        $this->assertSame($noCacheTable, $testNoCacheTable);

        $schema->db->tablePrefix = $tablePrefix;
        $schema->refreshTableSchema($tableName);
        $refreshedTable = $schema->getTableSchema($tableName, false);
        $this->assertInstanceOf(TableSchema::className(), $refreshedTable);
        $this->assertNotSame($noCacheTable, $refreshedTable);

        // Compare
        $schema->db->tablePrefix = $testTablePrefix;
        $schema->refreshTableSchema($testTablePrefix);
        $testRefreshedTable = $schema->getTableSchema($testTableName, false);
        $this->assertInstanceOf(TableSchema::className(), $testRefreshedTable);
        $this->assertEquals($refreshedTable, $testRefreshedTable);
        $this->assertNotSame($testNoCacheTable, $testRefreshedTable);
    }

    public function testCompositeFk()
    {
        /* @var $schema Schema */
        $schema = $this->getConnection()->schema;

        $table = $schema->getTableSchema('composite_fk');

        $this->assertCount(1, $table->foreignKeys);
        $this->assertTrue(isset($table->foreignKeys['FK_composite_fk_order_item']));
        $this->assertEquals('order_item', $table->foreignKeys['FK_composite_fk_order_item'][0]);
        $this->assertEquals('order_id', $table->foreignKeys['FK_composite_fk_order_item']['order_id']);
        $this->assertEquals('item_id', $table->foreignKeys['FK_composite_fk_order_item']['item_id']);

        $table = $schema->getTableSchema('T_constraints_3');
        $this->assertCount(1, $table->foreignKeys);
        $this->assertTrue(isset($table->foreignKeys['CN_constraints_3']));
        $this->assertEquals('T_constraints_2', $table->foreignKeys['CN_constraints_3'][0]);
        $this->assertEquals('C_id_1', $table->foreignKeys['CN_constraints_3']['C_fk_id_1']);
        $this->assertEquals('C_id_2', $table->foreignKeys['CN_constraints_3']['C_fk_id_2']);
    }


    public function getExpectedColumns()
    {
        return [
            'int_col' => [
                'type' => 'integer',
                'dbType' => 'integer',
                'phpType' => 'integer',
                'allowNull' => false,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'bigint_col' => [
                'type' => 'integer',
                'dbType' => 'unsigned',
                'phpType' => 'integer',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'int_col2' => [
                'type' => 'integer',
                'dbType' => 'integer',
                'phpType' => 'integer',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => 1,
            ],
            'int_col3' => [
                'type' => 'integer',
                'dbType' => 'unsigned',
                'phpType' => 'integer',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => 1,
            ],
            'char_col' => [
                'type' => 'string',
                'dbType' => 'string',
                'phpType' => 'string',
                'allowNull' => false,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'char_col2' => [
                'type' => 'string',
                'dbType' => 'string',
                'phpType' => 'string',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => 'something',
            ],
            'char_col3' => [
                'type' => 'string',
                'dbType' => 'string',
                'phpType' => 'string',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'float_col' => [
                'type' => 'double',
                'dbType' => 'double',
                'phpType' => 'double',
                'allowNull' => false,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'float_col2' => [
                'type' => 'double',
                'dbType' => 'double',
                'phpType' => 'double',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => 1.23,
            ],
            'blob_col' => [
                'type' => 'binary',
                'dbType' => 'varbinary',
                'phpType' => 'resource',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'numeric_col' => [
                'type' => 'double',
                'dbType' => 'double',
                'phpType' => 'double',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => 33.22,
            ],
            'bool_col' => [
                'type' => 'boolean',
                'dbType' => 'boolean',
                'phpType' => 'boolean',
                'allowNull' => false,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => null,
            ],
            'bool_col2' => [
                'type' => 'boolean',
                'dbType' => 'boolean',
                'phpType' => 'boolean',
                'allowNull' => true,
                'autoIncrement' => false,
                'enumValues' => null,
                'size' => null,
                'precision' => null,
                'scale' => null,
                'defaultValue' => true,
            ],
        ];
    }

    public function testNegativeDefaultValues()
    {
        /* @var $schema Schema */
        $schema = $this->getConnection()->schema;

        $table = $schema->getTableSchema('negative_default_values');
        $this->assertEquals(-123, $table->getColumn('int_col')->defaultValue);
        $this->assertEquals(-12345.6789, $table->getColumn('float_col')->defaultValue);
    }

    public function testColumnSchema()
    {
        $columns = $this->getExpectedColumns();

        $table = $this->getConnection(false)->schema->getTableSchema('type', true);

        $expectedColNames = array_keys($columns);
        sort($expectedColNames);
        $colNames = $table->columnNames;
        sort($colNames);
        $this->assertEquals($expectedColNames, $colNames);

        foreach ($table->columns as $name => $column) {
            $expected = $columns[$name];
            $this->assertSame($expected['dbType'], $column->dbType, "dbType of column $name does not match. type is $column->type, dbType is $column->dbType.");
            $this->assertSame($expected['phpType'], $column->phpType, "phpType of column $name does not match. type is $column->type, dbType is $column->dbType.");
            $this->assertSame($expected['type'], $column->type, "type of column $name does not match.");
            $this->assertSame($expected['allowNull'], $column->allowNull, "allowNull of column $name does not match.");
            $this->assertSame($expected['autoIncrement'], $column->autoIncrement, "autoIncrement of column $name does not match.");
            $this->assertSame($expected['enumValues'], $column->enumValues, "enumValues of column $name does not match.");
            $this->assertSame($expected['size'], $column->size, "size of column $name does not match.");
            $this->assertSame($expected['precision'], $column->precision, "precision of column $name does not match.");
            $this->assertSame($expected['scale'], $column->scale, "scale of column $name does not match.");
            if (\is_object($expected['defaultValue'])) {
                $this->assertInternalType('object', $column->defaultValue, "defaultValue of column $name is expected to be an object but it is not.");
                $this->assertEquals((string)$expected['defaultValue'], (string)$column->defaultValue, "defaultValue of column $name does not match.");
            } else {
                $this->assertEquals($expected['defaultValue'], $column->defaultValue, "defaultValue of column $name does not match.");
            }
            if (isset($expected['dimension'])) { // PgSQL only
                $this->assertSame($expected['dimension'], $column->dimension, "dimension of column $name does not match");
            }
        }
    }

    public function testColumnSchemaDbTypecastWithEmptyCharType()
    {
        $columnSchema = new ColumnSchema(['type' => Schema::TYPE_CHAR]);
        $this->assertSame('', $columnSchema->dbTypecast(''));
    }

    public function testFindUniqueIndexes()
    {
        $db = $this->getConnection();

        try {
            $db->createCommand()->dropTable('uniqueIndex')->execute();
        } catch (\Exception $e) {
        }
        $db->createCommand()->createTable('uniqueIndex', [
            'id' => 'int',
            'somecol' => 'string',
            'someCol2' => 'string',
            'CONSTRAINT "pk1" PRIMARY KEY ("id")'
        ])->execute();

        /* @var $schema Schema */
        $schema = $db->schema;

        $uniqueIndexes = $schema->findUniqueIndexes($schema->getTableSchema('uniqueIndex', true));
        $this->assertEquals(["pk1" => ['id']], $uniqueIndexes);

        $db->createCommand()->createIndex('somecolUnique', 'uniqueIndex', 'somecol', true)->execute();

        $uniqueIndexes = $schema->findUniqueIndexes($schema->getTableSchema('uniqueIndex', true));
        $this->assertEquals([
            "pk1" => ['id'],
            'somecolUnique' => ['somecol'],
        ], $uniqueIndexes);

        // create another column with upper case letter that fails postgres
        // see https://github.com/yiisoft/yii2/issues/10613
        $db->createCommand()->createIndex('someCol2Unique', 'uniqueIndex', 'someCol2', true)->execute();

        $uniqueIndexes = $schema->findUniqueIndexes($schema->getTableSchema('uniqueIndex', true));
        $this->assertEquals([
            "pk1" => ['id'],
            'somecolUnique' => ['somecol'],
            'someCol2Unique' => ['someCol2'],
        ], $uniqueIndexes);

        // see https://github.com/yiisoft/yii2/issues/13814
        $db->createCommand()->createIndex('another unique index', 'uniqueIndex', 'someCol2', true)->execute();

        $uniqueIndexes = $schema->findUniqueIndexes($schema->getTableSchema('uniqueIndex', true));
        $this->assertEquals([
            "pk1" => ['id'],
            'somecolUnique' => ['somecol'],
            'someCol2Unique' => ['someCol2'],
            'another unique index' => ['someCol2'],
        ], $uniqueIndexes);
    }

    public function testContraintTablesExistance()
    {
        $tableNames = [
            'T_constraints_1',
            'T_constraints_2',
            'T_constraints_3',
            'T_constraints_4',
        ];
        $schema = $this->getConnection()->getSchema();
        foreach ($tableNames as $tableName) {
            $tableSchema = $schema->getTableSchema($tableName);
            $this->assertInstanceOf('yii\db\TableSchema', $tableSchema, $tableName);
        }
    }

    public function constraintsProvider()
    {
        return [
            '1: primary key' => ['T_constraints_1', 'primaryKey', new Constraint([
                'name' => AnyValue::getInstance(),
                'columnNames' => ['C_id'],
            ])],
            '1: check' => ['T_constraints_1', 'checks', [
                new CheckConstraint([
                    'name' => AnyValue::getInstance(),
                    'columnNames' => ['C_check'],
                    'expression' => '"C_check" <> \'\'',
                ]),
            ]],
            '1: unique' => ['T_constraints_1', 'uniques', [
                new Constraint([
                    'name' => 'CN_unique',
                    'columnNames' => ['C_unique'],
                ]),
                new Constraint([
                    'name' => AnyValue::getInstance(),
                    'columnNames' => ['C_id'],
                ])
            ]],
            '1: index' => ['T_constraints_1', 'indexes', [
                new IndexConstraint([
                    'name' => AnyValue::getInstance(),
                    'columnNames' => ['C_id'],
                    'isUnique' => true,
                    'isPrimary' => true,
                ]),
                new IndexConstraint([
                    'name' => 'CN_unique',
                    'columnNames' => ['C_unique'],
                    'isPrimary' => false,
                    'isUnique' => true,
                ]),
            ]],

            '2: primary key' => ['T_constraints_2', 'primaryKey', new Constraint([
                'name' => 'CN_pk',
                'columnNames' => ['C_id_1', 'C_id_2'],
            ])],
            '2: unique' => ['T_constraints_2', 'uniques', [
                new Constraint([
                    'name' => AnyValue::getInstance(),
                    'columnNames' => ["C_id_1", "C_id_2"],
                ]),
                new Constraint([
                    'name' => 'CN_constraints_2_multi',
                    'columnNames' => ['C_index_2_1', 'C_index_2_2'],
                ]),
            ]],
            '2: index' => ['T_constraints_2', 'indexes', [
                new IndexConstraint([
                    'name' => AnyValue::getInstance(),
                    'columnNames' => ['C_id_1', 'C_id_2'],
                    'isUnique' => true,
                    'isPrimary' => true,
                ]),
                new IndexConstraint([
                    'name' => 'CN_constraints_2_single',
                    'columnNames' => ['C_index_1'],
                    'isPrimary' => false,
                    'isUnique' => false,
                ]),
                new IndexConstraint([
                    'name' => 'CN_constraints_2_multi',
                    'columnNames' => ['C_index_2_1', 'C_index_2_2'],
                    'isPrimary' => false,
                    'isUnique' => true,
                ]),
            ]],
            '2: check' => ['T_constraints_2', 'checks', []],

            '3: primary key' => ['T_constraints_3', 'primaryKey', null],
            '3: foreign key' => ['T_constraints_3', 'foreignKeys', [
                new ForeignKeyConstraint([
                    'name' => 'CN_constraints_3',
                    'columnNames' => ['C_fk_id_1', 'C_fk_id_2'],
                    'foreignTableName' => 'T_constraints_2',
                    'foreignColumnNames' => ['C_id_1', 'C_id_2'],
                    'onDelete' => 'CASCADE',
                    'onUpdate' => 'CASCADE',
                ]),
            ]],
            '3: unique' => ['T_constraints_3', 'uniques', []],
            '3: index' => ['T_constraints_3', 'indexes', [
            ]],
            '3: check' => ['T_constraints_3', 'checks', []],

            '4: primary key' => ['T_constraints_4', 'primaryKey', new Constraint([
                'name' => AnyValue::getInstance(),
                'columnNames' => ['C_id'],
            ])],
            '4: unique' => ['T_constraints_4', 'uniques', [
                new Constraint([
                    'name' => 'CN_constraints_4',
                    'columnNames' => ['C_col_1', 'C_col_2'],
                ]),
                new Constraint([
                    'name' => AnyValue::getInstance(),
                    'columnNames' => ['C_id'],
                ]),
            ]],
            '4: check' => ['T_constraints_4', 'checks', []],
        ];
    }

    public function lowercaseConstraintsProvider()
    {
        return $this->constraintsProvider();
    }

    public function uppercaseConstraintsProvider()
    {
        return $this->constraintsProvider();
    }

    /**
     * @dataProvider constraintsProvider
     * @param string $tableName
     * @param string $type
     * @param mixed $expected
     */
    public function testTableSchemaConstraints($tableName, $type, $expected)
    {
        $constraints = $this->getConnection()->getSchema()->{'getTable' . ucfirst($type)}($tableName);
        $this->assertMetadataEquals($expected, $constraints);
    }

    private function assertMetadataEquals($expected, $actual)
    {
        $t = strtolower(\gettype($expected));
        $this->{$t === 'null' ? "assertNull" : "assertIs".ucfirst($t)}($actual);
        if (\is_array($expected)) {
            $this->normalizeArrayKeys($expected, false);
            $this->normalizeArrayKeys($actual, false);
        }
        $this->normalizeConstraints($expected, $actual);
        if (\is_array($expected)) {
            $this->normalizeArrayKeys($expected, true);
            $this->normalizeArrayKeys($actual, true);
        }
        $this->assertEquals($expected, $actual);
    }

    private function normalizeArrayKeys(array &$array, $caseSensitive)
    {
        $newArray = [];
        foreach ($array as $value) {
            if ($value instanceof Constraint) {
                $key = (array)$value;
                unset($key['name'], $key['foreignSchemaName']);
                foreach ($key as $keyName => $keyValue) {
                    if ($keyValue instanceof AnyCaseValue) {
                        $key[$keyName] = $keyValue->value;
                    } elseif ($keyValue instanceof AnyValue) {
                        $key[$keyName] = '[AnyValue]';
                    }
                }
                ksort($key, SORT_STRING);
                $newArray[$caseSensitive ? json_encode($key) : strtolower(json_encode($key))] = $value;
            } else {
                $newArray[] = $value;
            }
        }
        ksort($newArray, SORT_STRING);
        $array = $newArray;
    }

    private function normalizeConstraints(&$expected, &$actual)
    {
        if (\is_array($expected)) {
            foreach ($expected as $key => $value) {
                if (!$value instanceof Constraint || !isset($actual[$key]) || !$actual[$key] instanceof Constraint) {
                    continue;
                }

                $this->normalizeConstraintPair($value, $actual[$key]);
            }
        } elseif ($expected instanceof Constraint && $actual instanceof Constraint) {
            $this->normalizeConstraintPair($expected, $actual);
        }
    }

    private function normalizeConstraintPair(Constraint $expectedConstraint, Constraint $actualConstraint)
    {
        if ($expectedConstraint::className() !== $actualConstraint::className()) {
            return;
        }

        foreach (array_keys((array)$expectedConstraint) as $name) {
            if ($expectedConstraint->$name instanceof AnyValue) {
                $actualConstraint->$name = $expectedConstraint->$name;
            } elseif ($expectedConstraint->$name instanceof AnyCaseValue) {
                $actualConstraint->$name = new AnyCaseValue($actualConstraint->$name);
            }
        }
    }
}
