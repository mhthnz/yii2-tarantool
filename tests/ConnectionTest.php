<?php

namespace mhthnz\tarantool\tests;

use mhthnz\tarantool\Client;
use mhthnz\tarantool\tests\classes\AnyCaseValue;
use mhthnz\tarantool\tests\classes\AnyValue;
use mhthnz\tarantool\tests\classes\Customer;
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

class ConnectionTest extends TestCase
{
    use DbTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockApplication(['components' => [
            'tarantool' => [
                'class' => \mhthnz\tarantool\Connection::class,
                'dsn' => 'tcp://guest@localhost:3301',
            ]
        ]]);
        $this->dropConstraints();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "animal_view"')->execute();
        $this->getDb()->createCommand('DROP VIEW IF EXISTS "testCreateView"')->execute();
        $this->dropTables();
        $this->createStructure();
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

    public function testOpenConnectAR()
    {
        $c = Customer::findOne(1);
        $this->assertTrue($c instanceof Customer);
        $this->assertEquals(1, $c->id);
    }

    public function testOpenConnectAR1()
    {
        $c = new Customer();
        $c->loadDefaultValues();
        $this->assertEquals(0, $c->status);
    }

    /**
     * @dataProvider methodProvider
     * @param string $method
     * @param array $args
     */
    public function testOpenConnectSchema($method, $args)
    {
        /** @var Connection $t */
        $t = \Yii::$app->tarantool;
        $this->assertFalse($t->isActive);
        $thrown = false;
        try {
            call_user_func_array([\Yii::$app->tarantool->schema, $method], $args);
        }catch (\Throwable $e) {
            $thrown = true;
        }
        $this->assertFalse($thrown);
        $this->assertTrue($t->isActive);
    }

    public function methodProvider()
    {
        return [
            ['getTablePrimaryKey', ['customer']],
            ['getTablePrimaryKey', ['T_constraints_2']],
            ['getTablePrimaryKey', ['T_constraints_3']],
            ['getSchemaPrimaryKeys', []],
            ['getSchemaForeignKeys', []],
            ['getTableForeignKeys', ['T_constraints_3']],
            ['getTableIndexes', ['customer']],
            ['getTableIndexes', ['T_constraints_2']],
            ['getTableIndexes', ['T_constraints_4']],
            ['getSchemaIndexes', []],
            ['getTableUniques', ['T_constraints_4']],
            ['getSchemaUniques', []],
            ['getTableChecks', ['T_constraints_1']],
            ['getSchemaChecks', []],
            ['insert', ['category', ['name' => '123123']]],
            ['findUniqueIndexes', [new \mhthnz\tarantool\TableSchema(['name' => 'customer'])]],
            ['getTableSchema', ['customer']],
        ];
    }

    public function testClient()
    {
        $client = $this->getDb()->getSlaveClient();
        $this->assertInstanceOf(Client::class, $client);
    }
}
