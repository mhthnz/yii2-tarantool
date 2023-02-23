<?php

namespace mhthnz\tarantool\tests;


use mhthnz\tarantool\Connection;
use mhthnz\tarantool\DataReader;
use mhthnz\tarantool\Schema;
use yii\base\NotSupportedException;
use yii\caching\ArrayCache;

class CommandTest extends TestCase
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
        parent::setUp();
    }

    /**
     * @throws \Exception
     */
    protected function tearDown(): void
    {
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        parent::tearDown();
    }

    public function testPrepareCancel()
    {
        $db = $this->getConnection();

        $command = $db->createCommand('SELECT * FROM {{customer}}');
        $this->assertNull($command->preparedStatement);
        $command->prepare();
        $this->assertNotNull($command->preparedStatement);
        $command->cancel();
        $this->assertNull($command->preparedStatement);
    }

    public function testExecute()
    {
        $db = $this->getConnection();

        $sql = 'INSERT INTO {{customer}}([[email]], [[name]], [[address]]) VALUES (\'user4@example.com\', \'user4\', \'address4\')';
        $command = $db->createCommand($sql);
        $this->assertEquals(1, $command->execute());

        $sql = 'SELECT COUNT(*) FROM {{customer}} WHERE [[name]] = \'user4\'';
        $command = $db->createCommand($sql);
        $this->assertEquals(1, $command->queryScalar());

        $command = $db->createCommand('bad SQL');
        $this->expectException('\Throwable');
        $command->execute();
    }

    public function testQuery()
    {
        $db = $this->getConnection();

        // query
        $sql = 'SELECT * FROM {{customer}}';
        $reader = $db->createCommand($sql)->query();
        $this->assertInstanceOf(DataReader::className(), $reader);

        // queryAll
        $rows = $db->createCommand('SELECT * FROM {{customer}}')->queryAll();
        $this->assertCount(3, $rows);
        $row = $rows[2];
        $this->assertEquals(3, $row['id']);
        $this->assertEquals('user3', $row['name']);

        $rows = $db->createCommand('SELECT * FROM {{customer}} WHERE [[id]] = 10')->queryAll();
        $this->assertEquals([], $rows);

        // queryOne
        $sql = 'SELECT * FROM {{customer}} ORDER BY [[id]]';
        $row = $db->createCommand($sql)->queryOne();
        $this->assertEquals(1, $row['id']);
        $this->assertEquals('user1', $row['name']);

        $sql = 'SELECT * FROM {{customer}} ORDER BY [[id]]';
        $command = $db->createCommand($sql);
        $command->prepare();
        $row = $command->queryOne();
        $this->assertEquals(1, $row['id']);
        $this->assertEquals('user1', $row['name']);

        $sql = 'SELECT * FROM {{customer}} WHERE [[id]] = 10';
        $command = $db->createCommand($sql);
        $this->assertFalse($command->queryOne());

        // queryColumn
        $sql = 'SELECT * FROM {{customer}}';
        $column = $db->createCommand($sql)->queryColumn();
        $this->assertEquals(range(1, 3), $column);

        $command = $db->createCommand('SELECT [[id]] FROM {{customer}} WHERE [[id]] = 10');
        $this->assertEquals([], $command->queryColumn());

        // queryScalar
        $sql = 'SELECT * FROM {{customer}} ORDER BY [[id]]';
        $this->assertEquals(1, $db->createCommand($sql)->queryScalar());

        $sql = 'SELECT [[id]] FROM {{customer}} ORDER BY [[id]]';
        $command = $db->createCommand($sql);
        $command->prepare();
        $this->assertEquals(1, $command->queryScalar());

        $command = $db->createCommand('SELECT [[id]] FROM {{customer}} WHERE [[id]] = 10');
        $this->assertFalse($command->queryScalar());

        $command = $db->createCommand('bad SQL');
        $this->expectException('\Throwable');
        $command->query();
    }

    public function testBindParamValue()
    {
        $db = $this->getConnection();

        // bindParam
        $sql = 'INSERT INTO {{customer}}([[email]], [[name]], [[address]]) VALUES (:email, :name, :address)';
        $command = $db->createCommand($sql);
        $email = 'user4@example.com';
        $name = 'user4';
        $address = 'address4';
        $command->bindParam(':email', $email);
        $command->bindParam(':name', $name);
        $command->bindParam(':address', $address);
        $command->execute();

        $sql = 'SELECT [[name]] FROM {{customer}} WHERE [[email]] = :email';
        $command = $db->createCommand($sql);
        $command->bindParam(':email', $email);
        $this->assertEquals($name, $command->queryScalar());
        $blobCol = pack("nvc*", 0x1234, 0x5678, 65, 66);
        $sql = "
INSERT INTO {{type}} ([[int_col]], [[char_col]], [[float_col]], [[blob_col]], [[numeric_col]], [[bool_col]])
  VALUES (:int_col, :char_col, :float_col, :blob_col, :numeric_col, :bool_col)";
        $command = $db->createCommand($sql);
        $intCol = 123;
        $charCol = str_repeat('abc', 33) . 'x'; // a 100 char string
        $command->bindParam(':int_col', $intCol);
        $command->bindParam(':char_col', $charCol);
        $floatCol = 1.23;
        $numericCol = 1.23;

        $boolCol = false;
        $command->bindParam(':float_col', $floatCol);
        $command->bindParam(':numeric_col', $numericCol);
        $command->bindParam(':blob_col', $blobCol, Schema::TYPE_BINARY);
        $command->bindParam(':bool_col', $boolCol);
        //$this->assertEquals(1, $command->execute());
        $command->execute();

        $command = $db->createCommand('SELECT [[int_col]], [[char_col]], [[float_col]], [[blob_col]], [[numeric_col]], [[bool_col]] FROM {{type}}');
//        $command->prepare();
//        $command->pdoStatement->bindColumn('blob_col', $bc, \PDO::PARAM_LOB);
        $row = $command->queryOne();
        $this->assertEquals($intCol, $row['int_col']);
        $this->assertEquals($charCol, $row['char_col']);
        $this->assertEquals($floatCol, $row['float_col']);
        $this->assertEquals($blobCol, $row['blob_col']);
        $this->assertEquals($numericCol, $row['numeric_col']);
        $this->assertEquals($boolCol, $row['bool_col']);


        // bindValue
        $sql = 'INSERT INTO {{customer}}([[email]], [[name]], [[address]]) VALUES (:email, \'user5\', \'address5\')';
        $command = $db->createCommand($sql);
        $command->bindValue(':email', 'user5@example.com');
        $command->execute();

        $sql = 'SELECT [[email]] FROM {{customer}} WHERE [[name]] = :name';
        $command = $db->createCommand($sql);
        $command->bindValue(':name', 'user5');
        $this->assertEquals('user5@example.com', $command->queryScalar());
    }

    public function testInsert()
    {
        $db = $this->getConnection();
        $db->createCommand('DELETE FROM {{customer}}')->execute();

        $command = $db->createCommand();
        $command->insert(
            '{{customer}}',
            [
                'email' => 't1@example.com',
                'name' => 'test',
                'address' => 'test address',
            ]
        )->execute();
        $this->assertEquals(1, $db->createCommand('SELECT COUNT(*) FROM {{customer}};')->queryScalar());
        $record = $db->createCommand('SELECT [[email]], [[name]], [[address]] FROM {{customer}}')->queryOne();
        $this->assertEquals([
            'email' => 't1@example.com',
            'name' => 'test',
            'address' => 'test address',
        ], $record);
    }

    public function testInsertSelect()
    {
        $db = $this->getConnection();
        $db->createCommand('DELETE FROM {{customer}}')->execute();

        $command = $db->createCommand();
        $command->insert(
            '{{customer}}',
            [
                'email' => 't1@example.com',
                'name' => 'test',
                'address' => 'test address',
            ]
        )->execute();

        $query = new \yii\db\Query();
        $query->select([
                '{{customer}}.[[email]] as name',
                '[[name]] as email',
                '[[address]]',
            ]
        )
            ->from('{{customer}}')
            ->where([
                'and',
                ['<>', 'name', 'foo'],
                ['status' => [0, 1, 2, 3]],
            ]);

        $command = $db->createCommand();
        $command->insert(
            '{{customer}}',
            $query
        )->execute();

        $this->assertEquals(2, $db->createCommand('SELECT COUNT(*) FROM {{customer}}')->queryScalar());
        $record = $db->createCommand('SELECT [[email]], [[name]], [[address]] FROM {{customer}}')->queryAll();
        $this->assertEquals([
            [
                'email' => 't1@example.com',
                'name' => 'test',
                'address' => 'test address',
            ],
            [
                'email' => 'test',
                'name' => 't1@example.com',
                'address' => 'test address',
            ],
        ], $record);
    }

    /**
     * Test INSERT INTO ... SELECT SQL statement with alias syntax.
     */
    public function testInsertSelectAlias()
    {
        $db = $this->getConnection();
        $db->createCommand('DELETE FROM {{customer}}')->execute();

        $command = $db->createCommand();
        $command->insert(
            '{{customer}}',
            [
                'email' => 't1@example.com',
                'name' => 'test',
                'address' => 'test address',
            ]
        )->execute();

        $query = new \yii\db\Query();
        $query->select([
                'email' => '{{customer}}.[[email]]',
                'address' => 'name',
                'name' => 'address',
            ]
        )
            ->from('{{customer}}')
            ->where([
                'and',
                ['<>', 'name', 'foo'],
                ['status' => [0, 1, 2, 3]],
            ]);

        $command = $db->createCommand();
        $command->insert(
            '{{customer}}',
            $query
        )->execute();

        $this->assertEquals(2, $db->createCommand('SELECT COUNT(*) FROM {{customer}}')->queryScalar());
        $record = $db->createCommand('SELECT [[email]], [[name]], [[address]] FROM {{customer}}')->queryAll();
        $this->assertEquals([
            [
                'email' => 't1@example.com',
                'name' => 'test',
                'address' => 'test address',
            ],
            [
                'email' => 't1@example.com',
                'name' => 'test address',
                'address' => 'test',
            ],
        ], $record);
    }

    public function testsInsertQueryAsColumnValue()
    {
        $time = time();

        $db = $this->getConnection();
        $db->createCommand('DELETE FROM {{order_with_null_fk}}')->execute();

        $command = $db->createCommand();
        $command->insert('{{order}}', [
            'customer_id' => 1,
            'created_at' => $time,
            'total' => 42,
        ])->execute();

        $orderId = $db->getLastInsertID();


        $columnValueQuery = new \yii\db\Query();
        $columnValueQuery->select('created_at')->from('{{order}}')->where(['id' => $orderId]);

        $command = $db->createCommand();
        $command->insert(
            '{{order_with_null_fk}}',
            [
                'customer_id' => $orderId,
                'created_at' => $columnValueQuery,
                'total' => 42,
            ]
        )->execute();

        $this->assertEquals($time, $db->createCommand('SELECT [[created_at]] FROM {{order_with_null_fk}} WHERE [[customer_id]] = ' . $orderId)->queryScalar());

        $db->createCommand('DELETE FROM {{order_with_null_fk}}')->execute();
        $db->createCommand('DELETE FROM {{order}} WHERE [[id]] = ' . $orderId)->execute();
    }

    public function testCreateTable()
    {
        $db = $this->getConnection();

        if ($db->getSchema()->getTableSchema('testCreateTable') !== null) {
            $db->createCommand()->dropTable('testCreateTable')->execute();
        }

        $this->createTable('testCreateTable', ['id' => Schema::TYPE_PK, 'bar' => Schema::TYPE_INTEGER]);
        $db->createCommand()->insert('testCreateTable', ['bar' => 1])->execute();
        $records = $db->createCommand('SELECT [[id]], [[bar]] FROM {{testCreateTable}};')->queryAll();
        $this->assertEquals([
            ['id' => 1, 'bar' => 1],
        ], $records);
    }

    public function testAlterTable()
    {
        $this->expectException(NotSupportedException::class);
        $db = $this->getConnection();

        if ($db->getSchema()->getTableSchema('testAlterTable') !== null) {
            $db->createCommand()->dropTable('testAlterTable')->execute();
        }

        $this->createTable('testAlterTable', ['id' => Schema::TYPE_PK, 'bar' => Schema::TYPE_INTEGER]);
        $db->createCommand()->insert('testAlterTable', ['bar' => 1])->execute();

        $db->createCommand()->alterColumn('testAlterTable', 'bar', Schema::TYPE_STRING)->execute();

        $db->createCommand()->insert('testAlterTable', ['bar' => 'hello'])->execute();
        $records = $db->createCommand('SELECT [[id]], [[bar]] FROM {{testAlterTable}};')->queryAll();
        $this->assertEquals([
            ['id' => 1, 'bar' => 1],
            ['id' => 2, 'bar' => 'hello'],
        ], $records);
    }

    public function testDropTable()
    {
        $db = $this->getConnection();

        $tableName = 'type';
        $this->assertNotNull($db->getSchema()->getTableSchema($tableName));
        $db->createCommand()->dropTable($tableName)->execute();
        $this->assertNull($db->getSchema()->getTableSchema($tableName));
    }

    public function testTruncateTable()
    {
        $db = $this->getConnection();

        $rows = $db->createCommand('SELECT * FROM {{animal}}')->queryAll();
        $this->assertCount(2, $rows);
        $db->createCommand()->truncateTable('animal')->execute();
        $rows = $db->createCommand('SELECT * FROM {{animal}}')->queryAll();
        $this->assertCount(0, $rows);
    }

    public function testRenameTable()
    {
        $db = $this->getConnection();

        $fromTableName = 'type';
        $toTableName = 'new_type';

        if ($db->getSchema()->getTableSchema($toTableName) !== null) {
            $db->createCommand()->dropTable($toTableName)->execute();
        }

        $this->assertNotNull($db->getSchema()->getTableSchema($fromTableName));
        $this->assertNull($db->getSchema()->getTableSchema($toTableName));

        $db->createCommand()->renameTable($fromTableName, $toTableName)->execute();

        $this->assertNull($db->getSchema()->getTableSchema($fromTableName, true));
        $this->assertNotNull($db->getSchema()->getTableSchema($toTableName, true));
    }

    public function testAddDropPrimaryKey()
    {
        $db = $this->getConnection(false);
        $tableName = 'test_pk';
        $name = 'test_pk_constraint';
        $schema = $db->getSchema();

        if ($schema->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }

        // Can not create table without a primary key
        $this->createTable($tableName, [
            'int1' => 'integer not null',
            'int2' => 'integer not null',
            'CONSTRAINT "'.$name.'" PRIMARY KEY ("int1")'
        ]);

        $this->assertEquals(['int1'], $schema->getTablePrimaryKey($tableName, true)->columnNames);

        $db->createCommand()->dropPrimaryKey($name, $tableName)->execute();
        $this->assertNull($schema->getTablePrimaryKey($tableName, true));

        $db->createCommand()->addPrimaryKey($name, $tableName, ['int1', 'int2'])->execute();
        $this->assertEquals(['int1', 'int2'], $schema->getTablePrimaryKey($tableName, true)->columnNames);
    }

    public function testAddDropForeignKey()
    {
        $db = $this->getConnection(false);
        $tableName = 'test_fk';
        $name = 'test_fk_constraint';
        /** @var \yii\db\pgsql\Schema $schema */
        $schema = $db->getSchema();

        if ($schema->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }
        // Can not create table without a primary key
        $this->createTable($tableName, [
            'id' => 'int primary key autoincrement',
            'int1' => 'integer not null unique',
            'int2' => 'integer not null unique',
            'int3' => 'integer not null unique',
            'int4' => 'integer not null unique',
            'unique ([[int1]], [[int2]])',
            'unique ([[int3]], [[int4]])',
        ]);

        $this->assertEmpty($schema->getTableForeignKeys($tableName, true));
        $db->createCommand()->addForeignKey($name, $tableName, ['int1'], $tableName, ['int3'])->execute();
        $this->assertEquals(['int1'], $schema->getTableForeignKeys($tableName, true)[0]->columnNames);
        $this->assertEquals(['int3'], $schema->getTableForeignKeys($tableName, true)[0]->foreignColumnNames);

        $db->createCommand()->dropForeignKey($name, $tableName)->execute();
        $this->assertEmpty($schema->getTableForeignKeys($tableName, true));

        $db->createCommand()->addForeignKey($name, $tableName, ['int1', 'int2'], $tableName, ['int3', 'int4'])->execute();
        $this->assertEquals(['int1', 'int2'], $schema->getTableForeignKeys($tableName, true)[0]->columnNames);
        $this->assertEquals(['int3', 'int4'], $schema->getTableForeignKeys($tableName, true)[0]->foreignColumnNames);
    }

    public function testCreateDropIndex()
    {
        $db = $this->getConnection(false);
        $tableName = 'test_idx';
        $name = 'test_idx_constraint';

        $schema = $db->getSchema();

        if ($schema->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }
        // Can not create table without a primary key
        $this->createTable($tableName, [
            'id' => 'int',
            'int1' => 'integer not null',
            'int2' => 'integer not null',
            'CONSTRAINT "pk1" PRIMARY KEY ("id")'
        ]);

        $indexes = $schema->getTableIndexes($tableName, true);
        $this->assertTrue($indexes[0]->isPrimary);
        $this->assertTrue(count($indexes) === 1);
        $db->createCommand()->createIndex($name, $tableName, ['int1'])->execute();
        $this->assertEquals(['int1'], $schema->getTableIndexes($tableName, true)[1]->columnNames);
        $this->assertFalse($schema->getTableIndexes($tableName, true)[1]->isUnique);

        $db->createCommand()->dropIndex($name, $tableName)->execute();
        $indexes = $schema->getTableIndexes($tableName, true);
        $this->assertTrue($indexes[0]->isPrimary);
        $this->assertTrue(count($indexes) === 1);

        $db->createCommand()->createIndex($name, $tableName, ['int1', 'int2'])->execute();
        $this->assertEquals(['int1', 'int2'], $schema->getTableIndexes($tableName, true)[1]->columnNames);
        $this->assertFalse($schema->getTableIndexes($tableName, true)[1]->isUnique);

        $db->createCommand()->dropIndex($name, $tableName)->execute();
        $indexes = $schema->getTableIndexes($tableName, true);
        $this->assertTrue($indexes[0]->isPrimary);
        $this->assertTrue(count($indexes) === 1);

        $db->createCommand()->createIndex($name, $tableName, ['int1'], true)->execute();
        $this->assertEquals(['int1'], $schema->getTableIndexes($tableName, true)[1]->columnNames);
        $this->assertTrue($schema->getTableIndexes($tableName, true)[1]->isUnique);

        $db->createCommand()->dropIndex($name, $tableName)->execute();
        $indexes = $schema->getTableIndexes($tableName, true);
        $this->assertTrue($indexes[0]->isPrimary);
        $this->assertTrue(count($indexes) === 1);

        $db->createCommand()->createIndex($name, $tableName, ['int1', 'int2'], true)->execute();
        $this->assertEquals(['int1', 'int2'], $schema->getTableIndexes($tableName, true)[1]->columnNames);
        $this->assertTrue($schema->getTableIndexes($tableName, true)[1]->isUnique);
    }

    public function testAddDropUnique()
    {
        $db = $this->getConnection(false);
        $tableName = 'test_uq';
        $name = 'test_uq_constraint';
        /** @var \yii\db\pgsql\Schema $schema */
        $schema = $db->getSchema();

        if ($schema->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }
        $this->createTable($tableName, [
            'id' => 'int primary key',
            'int1' => 'integer not null',
            'int2' => 'integer not null',
        ]);

        $indexes = $schema->getTableUniques($tableName, true);
        $this->assertTrue(count($indexes) === 1);
        $this->assertEquals(['id'], $indexes[0]->columnNames);
        $db->createCommand()->addUnique($name, $tableName, ['int1'])->execute();
        $this->assertEquals(['int1'], $schema->getTableUniques($tableName, true)[1]->columnNames);

        $db->createCommand()->dropUnique($name, $tableName)->execute();
        $indexes = $schema->getTableUniques($tableName, true);
        $this->assertTrue(count($indexes) === 1);
        $this->assertEquals(['id'], $indexes[0]->columnNames);

        $db->createCommand()->addUnique($name, $tableName, ['int1', 'int2'])->execute();
        $this->assertEquals(['int1', 'int2'], $schema->getTableUniques($tableName, true)[1]->columnNames);
    }

    public function testAddDropCheck()
    {
        $db = $this->getConnection(false);
        $tableName = 'test_ck';
        $name = 'test_ck_constraint';
        /** @var \yii\db\pgsql\Schema $schema */
        $schema = $db->getSchema();

        if ($schema->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }
        $this->createTable($tableName, [
            'id' => 'int primary key',
            'int1' => 'integer',
        ]);

        $this->assertEmpty($schema->getTableChecks($tableName, true));
        $db->createCommand()->addCheck($name, $tableName, '[[int1]] > 1')->execute();
        $this->checkRegex('/^.*int1.*>.*1.*$/', $schema->getTableChecks($tableName, true)[0]->expression);

        $db->createCommand()->dropCheck($name, $tableName)->execute();
        $this->assertEmpty($schema->getTableChecks($tableName, true));
    }



    public function testIntegrityViolation()
    {
        $this->expectException('\Throwable');

        $db = $this->getConnection();

        $sql = 'INSERT INTO {{profile}}([[id]], [[description]]) VALUES (123, \'duplicate\')';
        $command = $db->createCommand($sql);
        $command->execute();
        $command->execute();
    }

    public function testLastInsertId()
    {
        $db = $this->getConnection();

        $sql = 'INSERT INTO {{profile}}([[description]]) VALUES (\'non duplicate\')';
        $command = $db->createCommand($sql);
        $command->execute();
        $this->assertEquals(3, $db->getSchema()->getLastInsertID());
    }

    public function testQueryCache()
    {
        $db = $this->getConnection();
        $db->enableQueryCache = true;
        $db->queryCache = new ArrayCache();
        $command = $db->createCommand('SELECT [[name]] FROM {{customer}} WHERE [[id]] = :id');

        $this->assertEquals('user1', $command->bindValue(':id', 1)->queryScalar());
        $update = $db->createCommand('UPDATE {{customer}} SET [[name]] = :name WHERE [[id]] = :id');

        $update->bindValues([':id' => 1, ':name' => 'user11'])->execute();

        $this->assertEquals('user11', $command->bindValue(':id', 1)->queryScalar());

        $db->cache(function (Connection $db) use ($command, $update) {
            $this->assertEquals('user2', $command->bindValue(':id', 2)->queryScalar());
            $update->bindValues([':id' => 2, ':name' => 'user22'])->execute();
            $this->assertEquals('user2', $command->bindValue(':id', 2)->queryScalar());

            $db->noCache(function () use ($command) {
                $this->assertEquals('user22', $command->bindValue(':id', 2)->queryScalar());
            });

            $this->assertEquals('user2', $command->bindValue(':id', 2)->queryScalar());
        }, 10);

        $db->enableQueryCache = false;
        $db->cache(function ($db) use ($command, $update) {
            $this->assertEquals('user22', $command->bindValue(':id', 2)->queryScalar());
            $update->bindValues([':id' => 2, ':name' => 'user2'])->execute();
            $this->assertEquals('user2', $command->bindValue(':id', 2)->queryScalar());
        }, 10);

        $db->enableQueryCache = true;
        $command = $db->createCommand('SELECT [[name]] FROM {{customer}} WHERE [[id]] = :id')->cache();
        $this->assertEquals('user11', $command->bindValue(':id', 1)->queryScalar());
        $update->bindValues([':id' => 1, ':name' => 'user1'])->execute();
        $this->assertEquals('user11', $command->bindValue(':id', 1)->queryScalar());
        $this->assertEquals('user1', $command->noCache()->bindValue(':id', 1)->queryScalar());

        $command = $db->createCommand('SELECT [[name]] FROM {{customer}} WHERE [[id]] = :id');
        $db->cache(function (Connection $db) use ($command, $update) {
            $this->assertEquals('user11', $command->bindValue(':id', 1)->queryScalar());
            $this->assertEquals('user1', $command->noCache()->bindValue(':id', 1)->queryScalar());
        }, 10);
    }

    public function testAutoRefreshTableSchema()
    {
        $db = $this->getConnection();
        if (version_compare($db->version,  '2.7', "<")) {
            $this->markTestSkipped("Version less than 2.7");
        }
        $tableName = 'test';
        $fkName = 'test_fk';

        if ($db->getSchema()->getTableSchema($tableName) !== null) {
            $db->createCommand()->dropTable($tableName)->execute();
        }

        $this->assertNull($db->getSchema()->getTableSchema($tableName));

        $this->createTable($tableName, [
            'id' => 'pk',
            'fk' => 'int',
            'name' => 'string',
        ]);
        $initialSchema = $db->getSchema()->getTableSchema($tableName);
        $this->assertNotNull($initialSchema);

        $db->createCommand()->addColumn($tableName, 'value', 'integer')->execute();
        $newSchema = $db->getSchema()->getTableSchema($tableName);
        $this->assertNotEquals($initialSchema, $newSchema);

        $db->createCommand()->addForeignKey($fkName, $tableName, 'fk', $tableName, 'id')->execute();
        $this->assertNotEmpty($db->getSchema()->getTableSchema($tableName)->foreignKeys);

        $db->createCommand()->dropForeignKey($fkName, $tableName)->execute();
        $this->assertEmpty($db->getSchema()->getTableSchema($tableName)->foreignKeys);

        $db->createCommand()->dropTable($tableName)->execute();
        $this->assertNull($db->getSchema()->getTableSchema($tableName));
    }

    public function testRetryHandler()
    {
        $connection = $this->getConnection();
        $connection->createCommand("INSERT INTO {{profile}}([[description]]) VALUES('command retry')")->execute();
        $this->assertEquals(1, $connection->createCommand("SELECT COUNT(*) FROM {{profile}} WHERE [[description]] = 'command retry'")->queryScalar());

        $attempts = null;
        $hitHandler = false;
        $hitCatch = false;
        $command = $connection->createCommand("INSERT INTO {{profile}}([[id]], [[description]]) VALUES(1, 'command retry')");
        $this->invokeMethod($command, 'setRetryHandler', [function ($exception, $attempt) use (&$attempts, &$hitHandler) {
            $attempts = $attempt;
            $hitHandler = true;
            return $attempt <= 2;
        }]);
        try {
            $command->execute();
        } catch (\Exception $e) {
            $hitCatch = true;
            $this->assertInstanceOf('\Throwable', $e);
        }
        $this->assertSame(3, $attempts);
        $this->assertTrue($hitHandler);
        $this->assertTrue($hitCatch);
    }

    public function testCreateView()
    {
        $db = $this->getConnection();
        $subquery = (new \yii\db\Query())
            ->select('bar')
            ->from('testCreateViewTable')
            ->where(['>', 'bar', '5']);
        if ($db->getSchema()->getTableSchema('testCreateView')) {
            $db->createCommand()->dropView('testCreateView')->execute();
        }
        if ($db->getSchema()->getTableSchema('testCreateViewTable')) {
            $db->createCommand()->dropTable('testCreateViewTable')->execute();
        }
        $this->createTable('testCreateViewTable', [
            'id' => Schema::TYPE_PK,
            'bar' => Schema::TYPE_INTEGER,
        ]);
        $db->createCommand()->insert('testCreateViewTable', ['bar' => 1])->execute();
        $db->createCommand()->insert('testCreateViewTable', ['bar' => 6])->execute();
        $db->createCommand()->createView('testCreateView', $subquery)->execute();
        $records = $db->createCommand('SELECT [[bar]] FROM {{testCreateView}};')->queryAll();

        $this->assertEquals([['bar' => 6]], $records);
    }

    public function testDropView()
    {
        $db = $this->getConnection();
        $db->createCommand('CREATE VIEW "animal_view" AS SELECT * FROM "animal"')->execute();
        $viewName = 'animal_view';
        $this->assertNotNull($db->getSchema()->getTableSchema($viewName, true));
        $db->createCommand()->dropView($viewName)->execute();

        $this->assertNull($db->getSchema()->getTableSchema($viewName));
    }
}